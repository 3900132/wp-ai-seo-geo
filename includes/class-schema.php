<?php
/**
 * Schema 结构化数据自动注入
 *
 * 1. FAQPage Schema：提取文章中 <h3>问题</h3> + 后续段落答案，生成 FAQPage JSON-LD
 * 2. 全站基础 Schema：WebSite + BreadcrumbList + Article/WebPage
 *    - publisher.logo（机构品牌）：手动设置 → custom_logo → 站点图标 兜底
 *    - Article.image（文章封面）：特色图 → 正文第一张图 → 手动默认封面图 兜底
 *
 * 所有输出通过 wp_head hook，不修改文章内容。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Schema {

	public function __construct() {
		add_action( 'wp_head', array( $this, 'output_schemas' ), 99 );
		add_action( 'wp_head', array( $this, 'output_canonical' ), 1 );
	}

	/**
	 * 输出 canonical link 标签
	 * 优先级设为 1（尽早输出），并移除 WordPress 默认的 rel_canonical 避免重复
	 */
	public function output_canonical() {
		if ( is_admin() ) return;
		if ( ! WAISG_Settings::get( 'canonical_enabled', 0 ) ) return;

		// 移除 WordPress 默认的 canonical 输出，避免重复
		remove_action( 'wp_head', 'rel_canonical' );

		$url = $this->get_canonical_url();
		if ( $url ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	/**
	 * 获取当前页面的 canonical URL
	 *
	 * @return string|false
	 */
	private function get_canonical_url() {
		if ( is_singular() ) {
			return wp_get_canonical_url();
		}

		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				return ! is_wp_error( $link ) ? $link : false;
			}
		}

		if ( is_post_type_archive() ) {
			return get_post_type_archive_link( get_query_var( 'post_type' ) );
		}

		if ( is_author() ) {
			return get_author_posts_url( get_queried_object_id() );
		}

		if ( is_date() ) {
			if ( is_year() )  return get_year_link( get_query_var( 'year' ) );
			if ( is_month() ) return get_month_link( get_query_var( 'year' ), get_query_var( 'monthnum' ) );
			if ( is_day() )   return get_day_link( get_query_var( 'year' ), get_query_var( 'monthnum' ), get_query_var( 'day' ) );
		}

		// 分页页面：canonical 指向第一页（集中权重）
		if ( is_paged() ) {
			global $wp;
			$base = home_url( $wp->request );
			// 去掉 /page/N/
			$base = preg_replace( '#/page/\d+/?$#', '/', $base );
			return $base;
		}

		return false;
	}

	/**
	 * 统一输出所有 Schema（wp_head hook）
	 */
	public function output_schemas() {
		if ( is_admin() ) return;

		// FAQPage Schema
		if ( WAISG_Settings::get( 'schema_faq_enabled', 0 ) ) {
			$this->output_faq_schema();
		}

		// 全站基础 Schema
		if ( WAISG_Settings::get( 'schema_base_enabled', 0 ) ) {
			$this->output_base_schemas();
		}
	}

	// ================================================================
	// FAQPage Schema
	// ================================================================

	/**
	 * 从文章正文提取 FAQ 并输出 FAQPage JSON-LD
	 *
	 * 提取规则：
	 *   <h3>问题文字</h3> 后紧跟的 <p>段落</p> 作为答案
	 *   支持多个连续 <p> 合并为一个答案
	 */
	private function output_faq_schema() {
		if ( ! is_singular() ) return;

		global $post;
		if ( ! $post || empty( $post->post_content ) ) return;

		$faqs = $this->extract_faqs( $post->post_content );
		if ( empty( $faqs ) ) return;

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array(),
		);

		foreach ( $faqs as $faq ) {
			$schema['mainEntity'][] = array(
				'@type' => 'Question',
				'name'  => $faq['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $faq['answer'],
				),
			);
		}

		$this->print_jsonld( $schema );
	}

	/**
	 * 从 HTML 正文中提取 FAQ 列表
	 *
	 * @param string $html 文章正文 HTML
	 * @return array [ ['question'=>string, 'answer'=>string], ... ]
	 */
	private function extract_faqs( $html ) {
		$faqs = array();

		// 策略：按 <h3> 分割，取每个 h3 后面的段落文本作为答案
		// 用 DOMDocument 解析，静默错误（兼容不规范 HTML）
		$doc = new DOMDocument();
		// 加 UTF-8 声明避免中文乱码
		$wrapped = '<?xml encoding="UTF-8"><div>' . $html . '</div>';
		@$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );

		$xpath = new DOMXPath( $doc );
		$h3s   = $xpath->query( '//h3' );

		if ( ! $h3s || $h3s->length === 0 ) return $faqs;

		foreach ( $h3s as $h3 ) {
			$question = trim( $h3->textContent );
			if ( empty( $question ) ) continue;

			// 简单启发判断：FAQ 问题通常含问号或以疑问词结尾
			// 不做严格过滤，只要 h3 后面有内容就提取
			$answer_parts = array();
			$sibling      = $h3->nextSibling;

			while ( $sibling ) {
				// 跳过纯空白文本节点
				if ( $sibling->nodeType === XML_TEXT_NODE && trim( $sibling->textContent ) === '' ) {
					$sibling = $sibling->nextSibling;
					continue;
				}

				// 遇到下一个标题标签（h2/h3/h4）则停止
				if ( $sibling->nodeType === XML_ELEMENT_NODE ) {
					$tag = strtolower( $sibling->nodeName );
					if ( in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) break;

					// 收集 p / ul / ol / div 中的文本作为答案
					if ( in_array( $tag, array( 'p', 'ul', 'ol', 'div', 'blockquote' ), true ) ) {
						$text = trim( $sibling->textContent );
						if ( ! empty( $text ) ) {
							$answer_parts[] = $text;
						}
					}
				}

				$sibling = $sibling->nextSibling;
			}

			$answer = implode( ' ', $answer_parts );
			if ( empty( $answer ) ) continue;

			$faqs[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $faqs;
	}

	// ================================================================
	// 全站基础 Schema
	// ================================================================

	/**
	 * 输出全站基础 Schema（WebSite + BreadcrumbList + Article/WebPage）
	 */
	private function output_base_schemas() {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$home_url  = home_url( '/' );

		// ── WebSite ──────────────────────────────────────────
		if ( WAISG_Settings::get( 'schema_website', 1 ) ) {
			$this->print_jsonld( array(
				'@context'    => 'https://schema.org',
				'@type'       => 'WebSite',
				'name'        => $site_name,
				'description' => $site_desc,
				'url'         => $home_url,
			) );
		}

		// ── BreadcrumbList ───────────────────────────────────
		if ( WAISG_Settings::get( 'schema_breadcrumb', 1 ) ) {
			$this->output_breadcrumb( $site_name, $home_url );
		}

		// ── Article / WebPage ────────────────────────────────
		if ( WAISG_Settings::get( 'schema_article', 1 ) && is_singular() ) {
			$this->output_article( $site_name, $home_url );
		}
	}

	/**
	 * 输出 BreadcrumbList Schema
	 */
	private function output_breadcrumb( $site_name, $home_url ) {
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => '首页',
				'item'     => $home_url,
			),
		);

		$pos = 2;

		if ( is_singular() ) {
			// 单篇页面：加分类（仅文章类型）
			if ( is_single() ) {
				$cats = get_the_category();
				if ( ! empty( $cats ) ) {
					$cat = $cats[0];
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $pos++,
						'name'     => $cat->name,
						'item'     => get_category_link( $cat->term_id ),
					);
				}
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => get_the_title(),
				'item'     => get_permalink(),
			);
		} elseif ( is_category() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => single_cat_title( '', false ),
				'item'     => get_category_link( get_queried_object_id() ),
			);
		} elseif ( is_tag() ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => single_tag_title( '', false ),
				'item'     => get_tag_link( get_queried_object_id() ),
			);
		}

		$this->print_jsonld( array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		) );
	}

	/**
	 * 输出 Article / WebPage Schema（publisher 品牌 Logo + 文章封面图三层兜底 + author）
	 */
	private function output_article( $site_name, $home_url ) {
		global $post;
		if ( ! $post ) return;

		$data = array(
			'@context'      => 'https://schema.org',
			'@type'         => is_single() ? 'Article' : 'WebPage',
			'headline'      => get_the_title(),
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
			'url'           => get_permalink(),
		);

		// ── Publisher（机构品牌，含品牌 Logo）──────────────
		$data['publisher'] = $this->get_publisher( $site_name );

		// ── Image（文章封面，与品牌 Logo 解耦）────────────
		$image = $this->get_article_image( $post );
		if ( $image ) {
			$data['image'] = $image;
		}

		// ── Author ────────────────────────────────────────
		if ( is_single() ) {
			$author_name = get_the_author_meta( 'display_name' );
			$author_id   = get_the_author_meta( 'ID' );
			$data['author'] = array(
				'@type' => 'Person',
				'name'  => $author_name ?: $site_name,
				'url'   => $author_id ? get_author_posts_url( $author_id ) : $home_url,
			);
		}

		// ── Description（优先 SEO 描述，后备摘要）─────────
		$desc = get_post_meta( $post->ID, WAISG_Meta_Box::get_seo_field_name( 'description' ), true );
		if ( empty( $desc ) ) {
			$desc = $post->post_excerpt;
		}
		if ( empty( $desc ) ) {
			$desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 50, '...' );
		}
		if ( ! empty( $desc ) ) {
			$data['description'] = $desc;
		}

		$this->print_jsonld( $data );
	}

	/**
	 * 构建 Publisher（Organization）数据，含机构品牌 Logo。
	 *
	 * @param string $site_name 站点名
	 * @return array
	 */
	private function get_publisher( $site_name ) {
		$publisher = array(
			'@type' => 'Organization',
			'name'  => $site_name,
		);

		$logo = $this->get_publisher_logo();
		if ( $logo ) {
			$publisher['logo'] = $logo;
		}

		return $publisher;
	}

	/**
	 * 获取发布者 Logo（publisher.logo），代表网站机构品牌，与文章封面图无关。
	 *
	 * 降级顺序：
	 *   1. 手动设置的 Publisher Logo（schema_publisher_logo）
	 *   2. 主题原生站点 Logo（自定义器 custom_logo，兼容所有主题）
	 *   3. 站点图标 Site Icon（get_site_icon_url，最终兜底）
	 *
	 * 三者均无则返回 false（publisher 不带 logo 字段）。
	 *
	 * @return array|false ImageObject 数组（含可解析到的真实宽高），无 Logo 时 false
	 */
	private function get_publisher_logo() {
		// 层级1：手动设置的 Publisher Logo（纯 URL，尝试解析本地附件尺寸）
		$manual = WAISG_Settings::get( 'schema_publisher_logo', '' );
		if ( ! empty( $manual ) ) {
			return $this->build_image_object( $manual );
		}

		// 层级2：主题原生 custom_logo（自定义器上传，所有主题通用）
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $url ) {
				$meta = wp_get_attachment_metadata( $logo_id );
				$w = ! empty( $meta['width'] )  ? (int) $meta['width']  : 0;
				$h = ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;
				return $this->build_image_object( $url, $w, $h );
			}
		}

		// 层级3：站点图标 Site Icon 兜底（外观→自定义→站点图标处设置）
		if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
			$url = get_site_icon_url( 512 );
			if ( $url ) {
				return $this->build_image_object( $url, 512, 512 );
			}
		}

		return false;
	}

	/**
	 * 构建 ImageObject（供 publisher.logo 与 Article.image 共用）。
	 * 宽高未知时尝试从本地附件解析，仍拿不到则省略 width/height（Google 允许）。
	 *
	 * @param string $url    图片 URL
	 * @param int    $width  已知宽（像素），0 表示未知
	 * @param int    $height 已知高（像素），0 表示未知
	 * @return array ImageObject 数组
	 */
	private function build_image_object( $url, $width = 0, $height = 0 ) {
		if ( $width <= 0 || $height <= 0 ) {
			$dims = $this->resolve_local_image_dimensions( $url );
			if ( $dims ) {
				$width  = $dims[0];
				$height = $dims[1];
			}
		}

		$obj = array(
			'@type' => 'ImageObject',
			'url'   => $url,
		);
		if ( $width > 0 && $height > 0 ) {
			$obj['width']  = (int) $width;
			$obj['height'] = (int) $height;
		}

		return $obj;
	}

	/**
	 * 尝试把图片 URL 解析为本地附件的真实宽高（非本地附件返回 false）。
	 *
	 * attachment_url_to_postid() 每次都会查库，且本方法在每个开启基础 Schema 的单页渲染时
	 * 都可能被 Logo / 封面图调用——用 transient 缓存反查结果（命中的宽高数组或未命中标记），
	 * 避免重复查询。缓存 12 小时：媒体库替换同名图后最迟 12h 生效，兼顾性能与新鲜度。
	 *
	 * @param string $url 图片 URL
	 * @return array|false [width, height] 或 false
	 */
	private function resolve_local_image_dimensions( $url ) {
		if ( empty( $url ) ) return false;

		$cache_key = 'waisg_imgdim_' . md5( $url );
		$cached    = get_transient( $cache_key );
		// get_transient 未命中返回 false；我们把"查过但无尺寸"存成 'none' 以区分，避免每次重查
		if ( $cached !== false ) {
			return is_array( $cached ) ? $cached : false;
		}

		$result = false;
		$id     = attachment_url_to_postid( $url );
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$result = array( (int) $meta['width'], (int) $meta['height'] );
			}
		}

		set_transient( $cache_key, $result === false ? 'none' : $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * 文章封面图三层梯级降级（与 publisher 品牌 Logo 彻底解耦），返回 ImageObject。
	 *
	 * 1. 特色图
	 * 2. 正文第一张图
	 * 3. 手动设置的默认封面图（schema_default_image）
	 *
	 * 纯文本无图文章走到第三层：留空则不输出 image，不再把站点 Logo 错配为文章封面。
	 * 可解析到真实宽高时带上（贴合 Google 富结果对 image 的推荐），拿不到则只给 url。
	 *
	 * @param WP_Post $post
	 * @return array|false ImageObject 数组，全部无图时返回 false
	 */
	private function get_article_image( $post ) {
		// 层级1：特色图（直接用缩略图附件 ID 取真实宽高）
		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $url ) {
				$meta = wp_get_attachment_metadata( $thumb_id );
				$w = ! empty( $meta['width'] )  ? (int) $meta['width']  : 0;
				$h = ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;
				return $this->build_image_object( $url, $w, $h );
			}
		}

		// 层级2：正文第一张图（本地附件会自动解析宽高）
		if ( ! empty( $post->post_content ) ) {
			if ( preg_match( '/<img\s[^>]*src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
				$img_url = $matches[1];
				// 相对路径转绝对路径
				if ( strpos( $img_url, 'http' ) !== 0 ) {
					$img_url = home_url( $img_url );
				}
				return $this->build_image_object( $img_url );
			}
		}

		// 层级3：设置中手动配置的默认封面图（留空则不输出，避免无效链接）
		$default = WAISG_Settings::get( 'schema_default_image', '' );
		return ! empty( $default ) ? $this->build_image_object( $default ) : false;
	}

	// ================================================================
	// 辅助方法
	// ================================================================

	/**
	 * 输出 JSON-LD script 标签
	 *
	 * @param array $data Schema 数据数组
	 */
	private function print_jsonld( $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! $json ) return;
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}
}
