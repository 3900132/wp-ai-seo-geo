<?php
/**
 * Schema 结构化数据自动注入
 *
 * 1. FAQPage Schema：提取文章中 <h3>问题</h3> + 后续段落答案，生成 FAQPage JSON-LD
 * 2. 全站基础 Schema：WebSite + BreadcrumbList + Article/WebPage（含四层图片兜底）
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
	 * 输出 Article / WebPage Schema（含四层图片兜底 + publisher + author）
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

		// ── Publisher（含 logo 600x600）────────────────────
		$logo_id  = get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'full' ) : '';

		if ( $logo_url ) {
			$data['publisher'] = array(
				'@type' => 'Organization',
				'name'  => $site_name,
				'logo'  => array(
					'@type'  => 'ImageObject',
					'url'    => $logo_url,
					'width'  => 600,
					'height' => 600,
				),
			);
		} else {
			$data['publisher'] = array(
				'@type' => 'Organization',
				'name'  => $site_name,
			);
		}

		// ── Image 四层兜底 ────────────────────────────────
		$image = $this->get_article_image( $post, $logo_url );
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
		$meta_box = new WAISG_Meta_Box();
		$desc     = get_post_meta( $post->ID, $meta_box->get_seo_field_name( 'description' ), true );
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
	 * 四层兜底获取文章图片
	 *
	 * 1. 特色图
	 * 2. 正文第一张图
	 * 3. 站点 Logo
	 * 4. 默认图（设置中配置）
	 *
	 * @param WP_Post $post
	 * @param string  $logo_url 站点 Logo URL
	 * @return string|false 图片 URL，未设置默认图时返回 false
	 */
	private function get_article_image( $post, $logo_url ) {
		// 层级1：特色图
		if ( has_post_thumbnail( $post->ID ) ) {
			return get_the_post_thumbnail_url( $post->ID, 'full' );
		}

		// 层级2：正文第一张图
		if ( ! empty( $post->post_content ) ) {
			if ( preg_match( '/<img\s[^>]*src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
				$img_url = $matches[1];
				// 相对路径转绝对路径
				if ( strpos( $img_url, 'http' ) !== 0 ) {
					$img_url = home_url( $img_url );
				}
				return $img_url;
			}
		}

		// 层级3：站点 Logo
		if ( ! empty( $logo_url ) ) {
			return $logo_url;
		}

		// 层级4：设置中的默认图（留空则不输出，避免无效链接）
		$default = WAISG_Settings::get( 'schema_default_image', '' );
		return ! empty( $default ) ? $default : false;
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
