<?php
/**
 * 批量优化
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Batch {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_waisg_batch_optimize_one', array( $this, 'ajax_batch_optimize_one' ) );
		add_action( 'wp_ajax_waisg_batch_get_posts',    array( $this, 'ajax_batch_get_posts' ) );
		// 批量操作入口（文章列表 Bulk Action）
		add_action( 'admin_init', array( $this, 'register_bulk_action' ) );
	}

	/** 添加批量优化子菜单 */
	public function add_menu() {
		add_submenu_page(
			'waisg-settings',
			'批量 AI 优化',
			'批量优化',
			'edit_posts',
			'waisg-batch',
			array( $this, 'render_batch_page' )
		);
	}

	/** 批量优化页面脚本 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'waisg-batch' ) === false ) return;
		wp_enqueue_style( 'waisg-admin', WAISG_URL . 'assets/css/admin.css', array(), WAISG_VERSION );
		wp_enqueue_script(
			'waisg-batch',
			WAISG_URL . 'assets/js/batch.js',
			array( 'jquery' ),
			WAISG_VERSION,
			true
		);
		wp_localize_script( 'waisg-batch', 'waisgCfg', array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'waisg_nonce' ),
			'interval'    => (int) WAISG_Settings::get( 'batch_interval', 3 ) * 1000,
			'concurrency' => (int) WAISG_Settings::get( 'batch_concurrency', 2 ),
		) );
	}

	/** 渲染批量优化页面 */
	public function render_batch_page() {
		if ( ! current_user_can( 'edit_posts' ) ) return;
		$post_types = WAISG_Settings::get_post_types();
		$categories = get_categories( array( 'hide_empty' => false ) );
		include WAISG_DIR . 'admin/views/batch.php';
	}

	/** 注册文章列表批量操作 */
	public function register_bulk_action() {
		$post_types = WAISG_Settings::get_post_types();
		foreach ( $post_types as $pt ) {
			add_filter( "bulk_actions-edit-{$pt}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$pt}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	public function add_bulk_action( $actions ) {
		$actions['waisg_batch_optimize'] = '🤖 AI 批量优化';
		return $actions;
	}

	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( $action !== 'waisg_batch_optimize' ) return $redirect_url;
		$ids = implode( ',', array_map( 'absint', $post_ids ) );
		return admin_url( 'admin.php?page=waisg-batch&post_ids=' . urlencode( $ids ) );
	}

	// =========================================================
	// AJAX
	// =========================================================

	/** AJAX：获取待优化文章列表 */
	public function ajax_batch_get_posts() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

		$post_type     = sanitize_key( $_POST['post_type'] ?? 'post' );
		$allowed_types = WAISG_Settings::get_post_types();
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = reset( $allowed_types ) ?: 'post';
		}
		$cat_id    = absint( $_POST['cat_id'] ?? 0 );
		$status    = sanitize_key( $_POST['status'] ?? 'publish' );
		$exclude   = sanitize_text_field( $_POST['exclude_ids'] ?? '' );
		$limit     = absint( $_POST['limit'] ?? 50 );

		$args = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => min( $limit, 500 ),
			'fields'         => 'ids',
		);

		if ( $cat_id ) {
			$args['cat'] = $cat_id;
		}

		if ( ! empty( $exclude ) ) {
			$exclude_ids = array_map( 'absint', explode( ',', $exclude ) );
			$args['post__not_in'] = $exclude_ids;
		}

		$posts = get_posts( $args );
		$data  = array();
		foreach ( $posts as $id ) {
			$data[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
			);
		}

		wp_send_json_success( array( 'posts' => $data, 'total' => count( $data ) ) );
	}

	/** AJAX：批量优化单篇文章（前端逐篇调用），优化结果存入暂存区，不直接覆盖文章 */
	public function ajax_batch_optimize_one() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$post_id        = absint( $_POST['post_id'] ?? 0 );
		$template_id    = absint( $_POST['template_id'] ?? 0 );
		$seo_only       = ! empty( $_POST['seo_only'] );
		$skip_humanize  = ! empty( $_POST['skip_humanize'] );
		$auto_fix_seo   = ! empty( $_POST['auto_fix_seo'] );
		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效文章 ID。', 'post_id' => $post_id ) );
		}

		$post = get_post( $post_id );

		$vars = array(
			'title'     => $post->post_title,
			'content'   => $post->post_content,
			'excerpt'   => $post->post_excerpt,
			'seo_title' => get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'title' ), true ),
			'seo_desc'  => get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'description' ), true ),
			'seo_kw'    => get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'keywords' ), true ),
		);

		// ── SEO-only 预检：字段已全部达标则跳过 AI（纯 PHP，零 token）──
		// 仅对「仅优化 SEO」生效：全量优化含正文重写，无法靠 PHP 判断是否需要优化。
		// 对已优化好的站点做批量 SEO-only 时，可省下大量重复 AI 调用。
		// 关键词为空时先用 PHP 从标题抽前 10 字补上（与 auto_fix_seo 一致），
		// 避免关键词空直接判 false → 浪费一次 AI 调用做 PHP 本就能做的事。
		if ( $seo_only ) {
			if ( empty( $vars['seo_kw'] ) && ! empty( $vars['title'] ) ) {
				$vars['seo_kw'] = mb_substr( $vars['title'], 0, 10, 'UTF-8' );
			}
			if ( WAISG_AI_API::seo_fields_pass( $vars ) ) {
				$history_id = WAISG_History::save_staged( array(
					'entry_type'   => 'optimized',
					'post_id'      => $post_id,
					'post_title'   => $vars['title'],
					'post_content' => $post->post_content,
					'post_excerpt' => $vars['excerpt'],
					'seo_title'    => $vars['seo_title'],
					'seo_desc'     => $vars['seo_desc'],
					'seo_kw'       => $vars['seo_kw'],
				) );

				wp_send_json_success( array(
					'post_id'    => $post_id,
					'history_id' => $history_id,
					'title'      => $vars['title'],
					'message'    => 'SEO 字段已达标，已跳过 AI（零消耗）',
					'seo_only'   => true,
					'skipped'    => true,
					'original'   => array(
						'post_title'   => $post->post_title,
						'post_content' => wp_strip_all_tags( $post->post_content ),
						'post_excerpt' => $post->post_excerpt,
						'seo_title'    => $vars['seo_title'],
						'seo_desc'     => $vars['seo_desc'],
						'seo_kw'       => $vars['seo_kw'],
					),
					'optimized'  => array(
						'post_title'   => $vars['title'],
						'post_content' => $post->post_content,
						'post_excerpt' => $vars['excerpt'],
						'seo_title'    => $vars['seo_title'],
						'seo_desc'     => $vars['seo_desc'],
						'seo_kw'       => $vars['seo_kw'],
					),
				) );
			}
		}

		// 保护原文中的图片，防止 AI 优化时丢失（仅非 SEO-only 模式）
		$img_protected = array( 'map' => array() );
		if ( ! $seo_only ) {
			$img_protected = WAISG_AI_API::protect_images( $vars['content'] );
			$vars['content'] = $img_protected['html'];
		}

		$prompts = $seo_only
			? WAISG_AI_API::build_optimize_seo_only_prompt( $vars, $template )
			: WAISG_AI_API::build_optimize_all_prompt( $vars, $template );

		// SEO-only 模式：SEO 字段较短，用用户配置的 max_tokens（不写死 1024，推理模型思考 token 也计入配额）
		// 全量模式：根据内容长度动态调整 max_tokens 和 timeout
		$extra = $seo_only
			? array( 'max_tokens' => absint( WAISG_Settings::get( 'max_tokens', 4096 ) ) )
			: WAISG_AI_API::build_long_content_extra( $vars['content'] );
		// 确定当前使用的模型偏好：前端传参 > 全局设置。SEO-only 与全量统一走同一套模型选择逻辑，
		// 旧版 SEO-only 分支无视 $use_model 只要配了轻量模型就强制走轻量，与 UI 承诺不符。
		$use_model = $model_override ? $model_override : WAISG_Settings::get( 'batch_model', 'main' );
		if ( $use_model === 'lightweight' ) {
			$lm = WAISG_Settings::get( 'lightweight_model', '' );
			if ( $lm ) {
				$extra['model'] = $lm;
			}
		}

		$result = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( $post_id, 'batch', $result->get_error_message(), $post->post_title );
			wp_send_json_error( array(
				'message' => wp_strip_all_tags( $result->get_error_message() ),
				'post_id' => $post_id,
				'title'   => $post->post_title,
			) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'], 'batch', $post_id );
		if ( ! $data ) {
			WAISG_Logger::log( $post_id, 'batch', 'AI 返回格式异常（无法解析 JSON）', $post->post_title . ' ｜AI返回：' . mb_substr( $result['text'], 0, 300, 'UTF-8' ) );
			wp_send_json_error( array(
				'message' => 'AI 返回格式异常，已跳过。',
				'post_id' => $post_id,
				'title'   => $post->post_title,
			) );
		}

		$new_title   = sanitize_text_field( $data['title']           ?? $post->post_title );
		$new_content = $seo_only ? $post->post_content : WAISG_AI_API::sanitize_content( $data['content'] ?? $post->post_content );
		$new_excerpt = sanitize_textarea_field( $data['excerpt']     ?? $post->post_excerpt );
		$new_seo_t   = sanitize_text_field( $data['seo_title']       ?? $vars['seo_title'] );
		$new_seo_d   = sanitize_textarea_field( $data['seo_description'] ?? $vars['seo_desc'] );
		$new_seo_kw  = sanitize_text_field( $data['seo_keywords']    ?? $vars['seo_kw'] );

		// 还原图片占位符
		if ( ! $seo_only && ! empty( $new_content ) ) {
			$new_content = WAISG_AI_API::restore_images( $new_content, $img_protected['map'] );
		}

		// 降低 AI 痕迹：先 humanize（如开启且未跳过），再外层兜底 filter（仅非 SEO-only 模式）
		if ( ! $seo_only && ! empty( $new_content ) ) {
			if ( ! $skip_humanize && WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$new_content = WAISG_AI_API::humanize( $new_content, $use_model );
			}
			$new_content = WAISG_AI_API::filter_ai_phrases( $new_content );
		}

		// SEO 本地修复（纯 PHP，零 Token）
		if ( $auto_fix_seo ) {
			$fixed = WAISG_AI_API::auto_fix_seo( array(
				'title'     => $new_title,
				'seo_title' => $new_seo_t,
				'seo_desc'  => $new_seo_d,
				'seo_kw'    => $new_seo_kw,
				'excerpt'   => $new_excerpt,
				'content'   => $new_content,
			), $post_id );

			$new_title   = $fixed['title'];
			$new_seo_t   = $fixed['seo_title'];
			$new_seo_d   = $fixed['seo_desc'];
			$new_seo_kw  = $fixed['seo_kw'];
			$new_excerpt  = $fixed['excerpt'];
		}

		// 优化结果存入暂存区，不直接覆盖文章
		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'optimized',
			'post_id'      => $post_id,
			'post_title'   => $new_title,
			'post_content' => $new_content,
			'post_excerpt' => $new_excerpt,
			'seo_title'    => $new_seo_t,
			'seo_desc'     => $new_seo_d,
			'seo_kw'       => $new_seo_kw,
		) );

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'history_id' => $history_id,
			'title'      => $new_title,
			'message'    => $seo_only ? 'SEO 字段优化完成，请审阅后保存' : 'AI 优化完成，请审阅后保存',
			'seo_only'   => $seo_only,
			// 原文内容（用于前端对比展示）
			'original'   => array(
				'post_title'   => $post->post_title,
				'post_content' => wp_strip_all_tags( $post->post_content ),
				'post_excerpt' => $post->post_excerpt,
				'seo_title'    => $vars['seo_title'],
				'seo_desc'     => $vars['seo_desc'],
				'seo_kw'       => $vars['seo_kw'],
			),
			// 优化后内容（前端可编辑）
			'optimized'  => array(
				'post_title'   => $new_title,
				'post_content' => $new_content,
				'post_excerpt' => $new_excerpt,
				'seo_title'    => $new_seo_t,
				'seo_desc'     => $new_seo_d,
				'seo_kw'       => $new_seo_kw,
			),
			'recovered'  => $data['_recovered'] ?? '',
		) );
	}
}
