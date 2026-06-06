<?php
/**
 * 批量生成图文文章
 * - 每次 AJAX 请求生成一篇（前端轮流调用，N 篇就调 N 次）
 * - 生成结果存入暂存区（history 表），不直接写入 WordPress
 * - 由用户在结果列表中审阅/编辑后，手动选择状态并保存到 WordPress
 * - 可选自动从 Pexels / Unsplash / AI 接口获取图片并插入正文
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Generator {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_waisg_gen_article',         array( $this, 'ajax_gen_article' ) );
		add_action( 'wp_ajax_waisg_gen_fetch_images',    array( $this, 'ajax_fetch_images' ) );
		add_action( 'wp_ajax_waisg_update_post_status',  array( $this, 'ajax_update_post_status' ) );
		add_action( 'wp_ajax_waisg_rewrite_article',     array( $this, 'ajax_rewrite_article' ) );
	}

	/** 加载脚本 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'waisg-generator' ) === false ) return;

		wp_enqueue_style( 'waisg-admin', WAISG_URL . 'assets/css/admin.css', array(), WAISG_VERSION );
		wp_enqueue_script(
			'waisg-generator',
			WAISG_URL . 'assets/js/generator.js',
			array( 'jquery' ),
			WAISG_VERSION,
			true
		);
		wp_localize_script( 'waisg-generator', 'waisgGen', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'waisg_nonce' ),
		) );
	}

	// =========================================================
	// AJAX：生成单篇文章
	// =========================================================

	/**
	 * 生成一篇文章并存入暂存区（不直接写入 WordPress）
	 * POST 参数：
	 *   topic       主题/要求（可选，不填则以 keywords 为主题）
	 *   keywords    核心关键词（必填）
	 *   description 补充说明（可选）
	 *   length      字数要求（0=不限）
	 *   post_type   文章类型（post/page/...）
	 *   category_id 分类 ID（post 类型有效）
	 *   index       当前第几篇（用于前端显示进度）
	 *   total       总篇数（用于前端显示进度）
	 */
	public function ajax_gen_article() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$topic       = sanitize_text_field( wp_unslash( $_POST['topic']       ?? '' ) );
		$keywords    = sanitize_text_field( wp_unslash( $_POST['keywords']    ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$language    = sanitize_text_field( wp_unslash( $_POST['language']    ?? 'zh-CN' ) );
		$length      = absint( $_POST['length']   ?? 0 );
		$post_type   = sanitize_key( $_POST['post_type']   ?? 'post' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$index       = absint( $_POST['index'] ?? 1 );
		$total       = absint( $_POST['total'] ?? 1 );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( empty( $keywords ) && empty( $topic ) ) {
			wp_send_json_error( array( 'message' => '关键词不能为空。' ) );
		}
		if ( empty( $topic ) ) {
			$topic = $keywords;
		}

		$allowed_types = WAISG_Settings::get_post_types();
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'post';
		}

		// 生成多篇时，加入差异化指令，避免 AI 输出相同标题和结构
		if ( $total > 1 ) {
			$diff_note   = "注意：这是同主题系列文章的第 {$index}/{$total} 篇，必须采用与其他篇完全不同的切入角度和标题，内容结构也要有明显区别。";
			$description = $description ? $description . "\n" . $diff_note : $diff_note;
		}

		$prompts = WAISG_AI_API::build_generate_prompt( $topic, $keywords, $length, $description, $language, $template );
		// 按目标字数动态调整 max_tokens 和 timeout（避免长文被默认 max_tokens 截断）
		$extra   = $length > 0 ? WAISG_AI_API::build_long_content_extra( str_repeat( '中', $length ) ) : array();
		// 前端传参 > 全局设置：确定使用的模型
		$use_model = $model_override ? $model_override : WAISG_Settings::get( 'batch_model', 'main' );
		if ( $use_model === 'lightweight' ) {
			$lm = WAISG_Settings::get( 'lightweight_model', '' );
			if ( $lm ) {
				$extra['model'] = $lm;
			}
		}
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( 0, 'generate', $result->get_error_message(), '第 ' . $index . ' 篇' );
			wp_send_json_error( array(
				'message' => '第 ' . $index . ' 篇 AI 请求失败：' . $result->get_error_message(),
				'index'   => $index,
			) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'] );
		if ( ! $data ) {
			error_log( sprintf(
				'[WAISG] 生成第 %d 篇文章时 AI 返回格式异常。原始返回（前 500 字符）：%s',
				$index,
				mb_substr( $result['text'], 0, 500, 'UTF-8' )
			) );
			WAISG_Logger::log( 0, 'generate', 'AI 返回格式异常（无法解析 JSON）', '第 ' . $index . ' 篇' );
			wp_send_json_error( array(
				'message' => '第 ' . $index . ' 篇 AI 返回格式异常，请重试。',
				'index'   => $index,
			) );
		}

		$post_title   = sanitize_text_field( $data['title']   ?? $topic );
		$post_content = WAISG_AI_API::sanitize_content( $data['content']  ?? '' );
		$post_excerpt = sanitize_textarea_field( $data['excerpt'] ?? '' );
		$seo_title    = sanitize_text_field( $data['seo_title']       ?? '' );
		$seo_desc     = sanitize_textarea_field( $data['seo_description'] ?? '' );
		$seo_kw       = sanitize_text_field( $data['seo_keywords']    ?? '' );

		// 降低 AI 痕迹：先二次润色（如开启），再外层兜底替换 AI 高频词
		if ( ! empty( $post_content ) ) {
			if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$post_content = WAISG_AI_API::humanize( $post_content );
			}
			$post_content = WAISG_AI_API::filter_ai_phrases( $post_content );
		}

		// 如果需要插入图片，先获取图片并插入正文
		$image_source = WAISG_Settings::get( 'image_source', 'none' );
		if ( $image_source !== 'none' ) {
			$image_keyword = ! empty( $keywords ) ? $keywords : $topic;
			$post_content  = $this->insert_images_into_content( $post_content, $image_keyword );
		}

		// SEO 本地修复（零 Token）
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $post_title,
			'seo_title' => $seo_title,
			'seo_desc'  => $seo_desc,
			'seo_kw'    => $seo_kw,
			'excerpt'   => $post_excerpt,
			'content'   => $post_content,
		) );
		$post_title   = $fixed['title'];
		$seo_title    = $fixed['seo_title'];
		$seo_desc     = $fixed['seo_desc'];
		$seo_kw       = $fixed['seo_kw'];
		$post_excerpt = $fixed['excerpt'];

		// 存入暂存区（history 表），不写入 WordPress
		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'generated',
			'post_id'      => 0,
			'keyword'      => $keywords,
			'post_type'    => $post_type,
			'category_id'  => $category_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
		) );

		wp_send_json_success( array(
			'index'        => $index,
			'total'        => $total,
			'history_id'   => $history_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
		) );
	}

	// =========================================================
	// 图片插入
	// =========================================================

	/**
	 * 将图片 HTML 插入到文章正文中
	 * 策略：在每个 <h2> 或 <h3> 标题后面插入一张图片
	 *
	 * @param string $content  原始正文 HTML
	 * @param string $keyword  搜图关键词
	 * @return string 插入图片后的正文
	 */
	private function insert_images_into_content( $content, $keyword ) {
		$images_per_post = (int) WAISG_Settings::get( 'images_per_post', 2 );
		if ( $images_per_post < 1 ) return $content;

		$images = $this->fetch_images( $keyword, $images_per_post );
		if ( empty( $images ) ) return $content;

		$inserted = 0;
		// 在第一个 <h2> 前插入第一张图片，其余在后续 <h2>/<h3> 后插入
		foreach ( $images as $img ) {
			$img_html = sprintf(
				'<figure class="waisg-gen-image"><img src="%s" alt="%s" style="max-width:100%%;height:auto;" /></figure>',
				esc_url( $img['url'] ),
				esc_attr( $img['alt'] )
			);

			if ( $inserted === 0 ) {
				// 第一张：插入到正文最开头
				$content = $img_html . "\n" . $content;
			} else {
				// 后续：插入到第 N 个 <h2> 或 <h3> 标签之后
				$content = $this->inject_after_heading( $content, $img_html, $inserted );
			}
			$inserted++;
			if ( $inserted >= $images_per_post ) break;
		}

		return $content;
	}

	/**
	 * 把 $inject 内容插入到第 $n 个 h2/h3 标签之后
	 */
	private function inject_after_heading( $content, $inject, $n ) {
		$count = 0;
		$result = preg_replace_callback(
			'#(<\/h[23]>)#i',
			function( $matches ) use ( $inject, $n, &$count ) {
				$count++;
				if ( $count === $n ) {
					return $matches[1] . "\n" . $inject;
				}
				return $matches[1];
			},
			$content
		);
		// 如果没有足够的标题，直接追加到末尾
		return ( $count >= $n ) ? $result : ( $content . "\n" . $inject );
	}

	/**
	 * 从 Pexels 或 Unsplash 获取图片列表
	 *
	 * @param string $keyword
	 * @param int    $count
	 * @return array [ ['url'=>'...', 'alt'=>'...'], ... ]
	 */
	public function fetch_images( $keyword, $count = 2 ) {
		$source = WAISG_Settings::get( 'image_source', 'none' );

		if ( $source === 'none' ) return array();

		if ( $source === 'ai_image' ) {
			return $this->fetch_ai_images( $keyword, $count );
		}

		$api_key = WAISG_Settings::get( 'image_api_key', '' );
		if ( empty( $api_key ) ) return array();

		if ( $source === 'pexels' ) {
			return $this->fetch_pexels( $keyword, $count, $api_key );
		}
		if ( $source === 'unsplash' ) {
			return $this->fetch_unsplash( $keyword, $count, $api_key );
		}

		return array();
	}

	/** 调用 AI 大模型接口生成图片（兼容 OpenAI /v1/images/generations） */
	private function fetch_ai_images( $keyword, $count ) {
		$api_url = WAISG_Settings::get( 'image_ai_url', '' );
		$api_key = WAISG_Settings::get( 'image_ai_key', '' );
		$model   = WAISG_Settings::get( 'image_ai_model', 'dall-e-3' );
		$size    = WAISG_Settings::get( 'image_ai_size', '1024x1024' );

		if ( empty( $api_url ) || empty( $api_key ) ) return array();

		$prompt = 'A high-quality illustration related to: ' . $keyword . '. Clean, professional, suitable for an article.';

		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => min( $count, 10 ),
			'size'   => $size,
		);

		$response = wp_remote_post( $api_url, array(
			'timeout' => 60,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) return array();

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) return array();

		$images = array();
		foreach ( $data['data'] as $item ) {
			$url = $item['url'] ?? '';
			if ( empty( $url ) ) continue;
			$images[] = array( 'url' => $url, 'alt' => $keyword );
		}

		return $images;
	}

	/** 从 Pexels 获取图片 */
	private function fetch_pexels( $keyword, $count, $api_key ) {
		$url = add_query_arg( array(
			'query'   => rawurlencode( $keyword ),
			'per_page' => $count,
			'locale'  => 'zh-CN',
		), 'https://api.pexels.com/v1/search' );

		$response = wp_remote_get( $url, array(
			'headers' => array( 'Authorization' => $api_key ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) return array();

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$images = array();
		if ( ! empty( $body['photos'] ) ) {
			foreach ( $body['photos'] as $photo ) {
				$images[] = array(
					'url' => $photo['src']['large'] ?? $photo['src']['original'] ?? '',
					'alt' => $photo['alt'] ?? $keyword,
				);
			}
		}
		return $images;
	}

	/** 从 Unsplash 获取图片 */
	private function fetch_unsplash( $keyword, $count, $api_key ) {
		$url = add_query_arg( array(
			'query'       => rawurlencode( $keyword ),
			'per_page'    => $count,
			'client_id'   => $api_key,
		), 'https://api.unsplash.com/search/photos' );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) return array();

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$images = array();
		if ( ! empty( $body['results'] ) ) {
			foreach ( $body['results'] as $photo ) {
				$images[] = array(
					'url' => $photo['urls']['regular'] ?? $photo['urls']['full'] ?? '',
					'alt' => $photo['alt_description'] ?? $photo['description'] ?? $keyword,
				);
			}
		}
		return $images;
	}

	// =========================================================
	// AJAX：预览搜图结果（设置页测试用）
	// =========================================================
	public function ajax_fetch_images() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

		$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$count   = absint( $_POST['count'] ?? 3 );
		if ( empty( $keyword ) ) wp_send_json_error( array( 'message' => '请输入关键词。' ) );

		$images = $this->fetch_images( $keyword, $count );
		wp_send_json_success( array( 'images' => $images ) );
	}

	// =========================================================
	// AJAX：更新文章发布状态（逐篇或批量，生成/优化完成后由前端调用）
	// =========================================================
	public function ajax_update_post_status() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$raw_ids   = sanitize_text_field( $_POST['post_ids'] ?? '' );
		$post_ids  = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		$target    = sanitize_key( $_POST['target_status'] ?? 'draft' );
		$post_date = sanitize_text_field( wp_unslash( $_POST['post_date'] ?? '' ) );

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => '没有有效的文章 ID。' ) );
		}
		if ( ! in_array( $target, array( 'draft', 'publish', 'future' ), true ) ) {
			$target = 'draft';
		}

		$updated = array();
		foreach ( $post_ids as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) continue;
			$arr = array( 'ID' => $pid, 'post_status' => $target );
			if ( $target === 'future' && ! empty( $post_date ) ) {
				$arr['post_date']     = $post_date;
				$arr['post_date_gmt'] = get_gmt_from_date( $post_date );
			}
			wp_update_post( $arr );
			$updated[] = $pid;
		}

		wp_send_json_success( array( 'updated' => $updated ) );
	}

	// =========================================================
	// AJAX：改写/伪原创
	// =========================================================

	/**
	 * 改写一篇文章并存入暂存区
	 * POST 参数：content, keywords, language, description, post_type, category_id, index
	 */
	public function ajax_rewrite_article() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$content     = wp_unslash( $_POST['content']     ?? '' );
		$keywords    = sanitize_text_field( wp_unslash( $_POST['keywords']    ?? '' ) );
		$language    = sanitize_text_field( wp_unslash( $_POST['language']    ?? 'zh-CN' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$post_type   = sanitize_key( $_POST['post_type']   ?? 'post' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$index       = absint( $_POST['index'] ?? 1 );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( empty( $content ) ) {
			wp_send_json_error( array( 'message' => '请粘贴原文内容。', 'index' => $index ) );
		}

		$allowed_types = WAISG_Settings::get_post_types();
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'post';
		}

		// 保护原文中的图片，防止 AI 改写时丢失
		$img_protected = WAISG_AI_API::protect_images( $content );
		$safe_content  = $img_protected['html'];

		$prompts = WAISG_AI_API::build_rewrite_prompt( $safe_content, $keywords, $language, $description, $template );
		$extra   = WAISG_AI_API::build_long_content_extra( $safe_content );
		// 前端传参 > 全局设置：确定使用的模型
		$use_model = $model_override ? $model_override : WAISG_Settings::get( 'batch_model', 'main' );
		if ( $use_model === 'lightweight' ) {
			$lm = WAISG_Settings::get( 'lightweight_model', '' );
			if ( $lm ) {
				$extra['model'] = $lm;
			}
		}
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( 0, 'rewrite', $result->get_error_message(), '第 ' . $index . ' 篇' );
			wp_send_json_error( array(
				'message' => '改写请求失败：' . $result->get_error_message(),
				'index'   => $index,
			) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'] );
		if ( ! $data ) {
			error_log( sprintf(
				'[WAISG] 改写文章时 AI 返回格式异常。原始返回（前 500 字符）：%s',
				mb_substr( $result['text'], 0, 500, 'UTF-8' )
			) );
			WAISG_Logger::log( 0, 'rewrite', 'AI 返回格式异常（无法解析 JSON）', '第 ' . $index . ' 篇' );
			wp_send_json_error( array( 'message' => 'AI 返回格式异常，请重试。', 'index' => $index ) );
		}

		$post_title   = sanitize_text_field( $data['title']           ?? '改写文章' );
		$post_content = WAISG_AI_API::sanitize_content( $data['content']                ?? '' );
		$post_excerpt = sanitize_textarea_field( $data['excerpt']     ?? '' );
		$seo_title    = sanitize_text_field( $data['seo_title']       ?? '' );
		$seo_desc     = sanitize_textarea_field( $data['seo_description'] ?? '' );
		$seo_kw       = sanitize_text_field( $data['seo_keywords']    ?? '' );

		// 降低 AI 痕迹：还原图片 + 二次润色（如开启）+ 外层兜底 filter
		if ( ! empty( $post_content ) ) {
			$post_content = WAISG_AI_API::restore_images( $post_content, $img_protected['map'] );
			if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$post_content = WAISG_AI_API::humanize( $post_content );
			}
			$post_content = WAISG_AI_API::filter_ai_phrases( $post_content );
		}

		// SEO 本地修复（零 Token）
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $post_title,
			'seo_title' => $seo_title,
			'seo_desc'  => $seo_desc,
			'seo_kw'    => $seo_kw,
			'excerpt'   => $post_excerpt,
			'content'   => $post_content,
		) );
		$post_title   = $fixed['title'];
		$seo_title    = $fixed['seo_title'];
		$seo_desc     = $fixed['seo_desc'];
		$seo_kw       = $fixed['seo_kw'];
		$post_excerpt = $fixed['excerpt'];

		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'generated',
			'post_id'      => 0,
			'keyword'      => $keywords,
			'post_type'    => $post_type,
			'category_id'  => $category_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
		) );

		wp_send_json_success( array(
			'history_id' => $history_id,
			'index'      => $index,
			'title'      => $post_title,
			'detail'     => array(
				'post_title'   => $post_title,
				'post_content' => $post_content,
				'post_excerpt' => $post_excerpt,
				'seo_title'    => $seo_title,
				'seo_desc'     => $seo_desc,
				'seo_kw'       => $seo_kw,
			),
		) );
	}
}
