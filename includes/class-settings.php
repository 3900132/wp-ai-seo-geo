<?php
/**
 * 后台设置管理类
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Settings {

	const OPTION_KEY      = 'waisg_settings';
	const TEMPLATES_KEY   = 'waisg_content_templates';

	public function __construct() {
		add_action( 'admin_menu',    array( $this, 'add_menu' ) );
		add_action( 'admin_init',    array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// AJAX：测试 API 连通
		add_action( 'wp_ajax_waisg_test_api',       array( $this, 'ajax_test_api' ) );
		// AJAX：测试轻量模型连通
		add_action( 'wp_ajax_waisg_test_lightweight_api', array( $this, 'ajax_test_lightweight_api' ) );
		// AJAX：测试图片 API
		add_action( 'wp_ajax_waisg_test_image_api', array( $this, 'ajax_test_image_api' ) );
		// AJAX：获取 Token 统计
		add_action( 'wp_ajax_waisg_get_token_stats',   array( $this, 'ajax_get_token_stats' ) );
		// AJAX：重置 Token 统计
		add_action( 'wp_ajax_waisg_reset_token_stats', array( $this, 'ajax_reset_token_stats' ) );
		// AJAX：内容结构模板 CRUD
		add_action( 'wp_ajax_waisg_save_template',   array( $this, 'ajax_save_template' ) );
		add_action( 'wp_ajax_waisg_delete_template', array( $this, 'ajax_delete_template' ) );
		add_action( 'wp_ajax_waisg_get_templates',   array( $this, 'ajax_get_templates' ) );
	}

	/** 添加后台菜单 */
	public function add_menu() {
		add_menu_page(
			'AI SEO+GEO 设置',
			'AI SEO+GEO',
			'manage_options',
			'waisg-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-superhero',
			80
		);
		add_submenu_page(
			'waisg-settings',
			'基本设置',
			'基本设置',
			'manage_options',
			'waisg-settings',
			array( $this, 'render_settings_page' )
		);
		add_submenu_page(
			'waisg-settings',
			'AI文章生成',
			'AI文章生成',
			'edit_posts',
			'waisg-generator',
			array( $this, 'render_generator_page' )
		);
		add_submenu_page(
			'waisg-settings',
			'优化历史',
			'优化历史',
			'edit_posts',
			'waisg-history',
			array( $this, 'render_history_page' )
		);
	}

	/** 注册设置 */
	public function register_settings() {
		register_setting( 'waisg_settings_group', self::OPTION_KEY, array( $this, 'sanitize_settings' ) );
	}

	/** 净化输入 */
	public function sanitize_settings( $input ) {
		$clean = array();

		$clean['api_url']       = esc_url_raw( trim( $input['api_url'] ?? '' ) );
		$clean['api_key']       = sanitize_text_field( trim( $input['api_key'] ?? '' ) );
		$clean['model']         = sanitize_text_field( trim( $input['model'] ?? 'gpt-4o' ) );
		$clean['lightweight_model'] = sanitize_text_field( trim( $input['lightweight_model'] ?? '' ) );
		$clean['timeout']       = absint( $input['timeout'] ?? 60 );
		$clean['temperature']   = min( 0.7, max( 0.1, floatval( $input['temperature'] ?? 0.3 ) ) );
		$clean['max_tokens']    = absint( $input['max_tokens'] ?? 4096 );
		$clean['system_prompt'] = wp_kses_post( $input['system_prompt'] ?? '' );

		// 支持的文章类型（数组）
		$clean['post_types'] = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $pt ) {
				$clean['post_types'][] = sanitize_key( $pt );
			}
		}

		// SEO 字段名
		$clean['seo_title_field']       = sanitize_text_field( $input['seo_title_field'] ?? '' );
		$clean['seo_description_field'] = sanitize_text_field( $input['seo_description_field'] ?? '' );
		$clean['seo_keywords_field']    = sanitize_text_field( $input['seo_keywords_field'] ?? '' );

		// 批量优化间隔（秒）
		$clean['batch_interval'] = absint( $input['batch_interval'] ?? 3 );

		// 批量任务模型选择
		$batch_model = sanitize_key( $input['batch_model'] ?? 'main' );
		$clean['batch_model'] = in_array( $batch_model, array( 'main', 'lightweight' ), true ) ? $batch_model : 'main';

		// 降低 AI 痕迹（二次润色）
		$clean['humanize_enabled'] = ! empty( $input['humanize_enabled'] ) ? 1 : 0;
		$clean['humanize_prompt']  = wp_kses_post( $input['humanize_prompt'] ?? '' );

		// AI 高频词替换
		$clean['ai_phrases_enabled'] = ! empty( $input['ai_phrases_enabled'] ) ? 1 : 0;
		$clean['ai_phrases_custom']  = sanitize_textarea_field( $input['ai_phrases_custom'] ?? '' );

		// 图片配置
		$allowed_sources          = array( 'none', 'pexels', 'unsplash', 'ai_image' );
		$image_source             = sanitize_key( $input['image_source'] ?? 'none' );
		$clean['image_source']    = in_array( $image_source, $allowed_sources, true ) ? $image_source : 'none';
		$clean['image_api_key']   = sanitize_text_field( trim( $input['image_api_key'] ?? '' ) );
		$clean['images_per_post'] = min( 10, max( 1, absint( $input['images_per_post'] ?? 2 ) ) );
		// AI 图片生成接口
		$clean['image_ai_url']   = esc_url_raw( trim( $input['image_ai_url'] ?? '' ) );
		$clean['image_ai_key']   = sanitize_text_field( trim( $input['image_ai_key'] ?? '' ) );
		$clean['image_ai_model'] = sanitize_text_field( trim( $input['image_ai_model'] ?? 'dall-e-3' ) );
		$allowed_sizes           = array( '1024x1024', '1792x1024', '1024x1792' );
		$ai_size                 = sanitize_text_field( $input['image_ai_size'] ?? '1024x1024' );
		$clean['image_ai_size']  = in_array( $ai_size, $allowed_sizes, true ) ? $ai_size : '1024x1024';

		// 定时自动优化
		$clean['cron_enabled']    = ! empty( $input['cron_enabled'] ) ? 1 : 0;
		$allowed_freq             = array( 'daily', 'twicedaily', 'weekly', 'biweekly' );
		$cron_freq                = sanitize_key( $input['cron_frequency'] ?? 'daily' );
		$clean['cron_frequency']  = in_array( $cron_freq, $allowed_freq, true ) ? $cron_freq : 'daily';
		$clean['cron_per_run']    = min( 20, max( 1, absint( $input['cron_per_run'] ?? 5 ) ) );
		$allowed_target           = array( 'oldest', 'least_optimized', 'random' );
		$cron_target              = sanitize_key( $input['cron_target'] ?? 'oldest' );
		$clean['cron_target']     = in_array( $cron_target, $allowed_target, true ) ? $cron_target : 'oldest';
		$clean['cron_post_types'] = array();
		if ( ! empty( $input['cron_post_types'] ) && is_array( $input['cron_post_types'] ) ) {
			foreach ( $input['cron_post_types'] as $pt ) {
				$clean['cron_post_types'][] = sanitize_key( $pt );
			}
		}

		// Schema 结构化数据
		$clean['schema_faq_enabled']   = ! empty( $input['schema_faq_enabled'] )  ? 1 : 0;
		$clean['schema_base_enabled']  = ! empty( $input['schema_base_enabled'] ) ? 1 : 0;
		$clean['schema_website']       = ! empty( $input['schema_website'] )      ? 1 : 0;
		$clean['schema_breadcrumb']    = ! empty( $input['schema_breadcrumb'] )   ? 1 : 0;
		$clean['schema_article']       = ! empty( $input['schema_article'] )      ? 1 : 0;
		$clean['schema_default_image'] = esc_url_raw( trim( $input['schema_default_image'] ?? '' ) );
		$clean['canonical_enabled']    = ! empty( $input['canonical_enabled'] ) ? 1 : 0;

		// AI 错误日志
		$clean['error_log_enabled'] = ! empty( $input['error_log_enabled'] ) ? 1 : 0;
		$clean['error_log_days']    = absint( $input['error_log_days'] ?? 30 );
		$clean['error_log_max']     = absint( $input['error_log_max'] ?? 200 );

		return $clean;
	}

	// =========================================================
	// Token 用量记录
	// =========================================================

	/**
	 * 累计记录 Token 用量(分模型)
	 *
	 * @param int    $tokens 本次消耗的 token 数
	 * @param string $model  模型名('main' 或 'lightweight');默认 'main'
	 */
	public static function record_tokens( $tokens, $model = 'main' ) {
		$tokens = absint( $tokens );
		if ( $tokens <= 0 ) return;

		$stats   = get_option( 'waisg_token_stats', array() );
		$month   = gmdate( 'Y-m' );

		$stats['total']   = ( $stats['total']   ?? 0 ) + $tokens;
		$stats['monthly'] = ( isset( $stats['month'] ) && $stats['month'] === $month )
			? ( ( $stats['monthly'] ?? 0 ) + $tokens )
			: $tokens;
		$stats['month']   = $month;

		// 分模型计数
		$key   = 'total_' . $model;
		$mkey  = 'monthly_' . $model;
		$stats[ $key ] = ( $stats[ $key ] ?? 0 ) + $tokens;
		if ( isset( $stats['model'] ) && $stats['model'] === $model ) {
			$stats[ $mkey ] = ( ( $stats[ $mkey ] ?? 0 ) + $tokens );
		} else {
			$stats[ $mkey ] = $tokens;
			$stats['model'] = $model;
		}

		update_option( 'waisg_token_stats', $stats, false );
	}

	// =========================================================
	// AJAX handlers
	// =========================================================

	/** 测试 AI API 连通性 */
	public function ajax_test_api() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		// 用前端传来的值（表单尚未保存时也能测试）
		$api_url = esc_url_raw( trim( wp_unslash( $_POST['api_url'] ?? '' ) ) );
		$api_key = sanitize_text_field( trim( wp_unslash( $_POST['api_key'] ?? '' ) ) );
		$model   = sanitize_text_field( trim( wp_unslash( $_POST['model']   ?? 'gpt-4o' ) ) );

		if ( empty( $api_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'API 地址和 Key 不能为空。' ) );
		}

		$start  = microtime( true );
		$result = WAISG_AI_API::test_connection( $api_url, $api_key, $model );
		$ms     = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => '❌ 连接失败：' . $result->get_error_message() ) );
		}

		$strategy = $result['strategy'] ?? 'standard';
		$suffix   = $strategy === 'standard' ? '' : '（已自动切换兼容策略：' . $strategy . '）';
		wp_send_json_success( array( 'message' => "✅ 连接成功！响应时间 {$ms}ms，模型：{$model}{$suffix}" ) );
	}

	/** 测试轻量模型 API 连通性 */
	public function ajax_test_lightweight_api() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$api_url = esc_url_raw( trim( wp_unslash( $_POST['api_url'] ?? '' ) ) );
		$api_key = sanitize_text_field( trim( wp_unslash( $_POST['api_key'] ?? '' ) ) );
		$model   = sanitize_text_field( trim( wp_unslash( $_POST['lightweight_model'] ?? '' ) ) );

		if ( empty( $api_url ) || empty( $api_key ) ) {
			wp_send_json_error( array( 'message' => 'API 地址和 Key 不能为空。' ) );
		}
		if ( empty( $model ) ) {
			wp_send_json_error( array( 'message' => '请填写轻量模型名称。' ) );
		}

		$start  = microtime( true );
		$result = WAISG_AI_API::test_connection( $api_url, $api_key, $model );
		$ms     = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => '❌ 连接失败：' . $result->get_error_message() ) );
		}

		$strategy = $result['strategy'] ?? 'standard';
		$suffix   = $strategy === 'standard' ? '' : '（已自动切换兼容策略：' . $strategy . '）';
		wp_send_json_success( array( 'message' => "✅ 轻量模型连接成功！响应时间 {$ms}ms，模型：{$model}{$suffix}" ) );
	}
	public function ajax_test_image_api() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$gen = new WAISG_Generator();
		$images = $gen->fetch_images( 'nature', 1 );

		if ( empty( $images ) ) {
			wp_send_json_error( array( 'message' => '未获取到图片，请检查图片来源和 API Key 配置。' ) );
		}
		wp_send_json_success( array( 'url' => $images[0]['url'] ?? '', 'message' => '✅ 图片 API 正常，已获取到图片。' ) );
	}

	/** 获取 Token 统计 */
	public function ajax_get_token_stats() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

		$stats = get_option( 'waisg_token_stats', array() );
		$month = gmdate( 'Y-m' );
		wp_send_json_success( array(
			'total'          => $stats['total']          ?? 0,
			'monthly'        => ( isset( $stats['month'] ) && $stats['month'] === $month ) ? ( $stats['monthly'] ?? 0 ) : 0,
			'month'          => $month,
			'total_main'     => $stats['total_main']     ?? 0,
			'monthly_main'   => ( isset( $stats['model'] ) && $stats['model'] === 'main' ) ? ( $stats['monthly_main'] ?? 0 ) : 0,
			'total_lightweight' => $stats['total_lightweight'] ?? 0,
			'monthly_lightweight' => ( isset( $stats['model'] ) && $stats['model'] === 'lightweight' ) ? ( $stats['monthly_lightweight'] ?? 0 ) : 0,
		) );
	}

	/** 重置 Token 统计 */
	public function ajax_reset_token_stats() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		delete_option( 'waisg_token_stats' );
		wp_send_json_success( array( 'message' => '统计已重置。' ) );
	}

	/** 获取单项设置 */
	public static function get( $key = null, $default = null ) {
		$opts = get_option( self::OPTION_KEY, array() );
		if ( $key === null ) return $opts;
		return $opts[ $key ] ?? $default;
	}

	/** 获取已启用的文章类型 */
	public static function get_post_types() {
		$types = self::get( 'post_types', array() );
		return empty( $types ) ? array( 'post', 'page' ) : $types;
	}

	/** 渲染设置页 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$opts = self::get();
		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );

		wp_enqueue_script(
			'waisg-settings',
			WAISG_URL . 'assets/js/settings.js',
			array( 'jquery' ),
			WAISG_VERSION,
			true
		);
		wp_localize_script( 'waisg-settings', 'waisgSettings', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'waisg_nonce' ),
		) );

		include WAISG_DIR . 'admin/views/settings.php';
	}

	/** 渲染历史页 */
	public function render_history_page() {
		if ( ! current_user_can( 'edit_posts' ) ) return;
		include WAISG_DIR . 'admin/views/history.php';
	}

	/** 渲染批量生成文章页 */
	public function render_generator_page() {
		if ( ! current_user_can( 'edit_posts' ) ) return;
		include WAISG_DIR . 'admin/views/generator.php';
	}

	/** 管理员通知：未配置 API */
	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$api_url = self::get( 'api_url' );
		$api_key = self::get( 'api_key' );
		if ( empty( $api_url ) || empty( $api_key ) ) {
			$url = admin_url( 'admin.php?page=waisg-settings' );
			echo '<div class="notice notice-warning is-dismissible"><p>';
			printf(
				'<strong>AI SEO+GEO 插件</strong>：请先 <a href="%s">配置 API 接口信息</a> 才能使用 AI 功能。',
				esc_url( $url )
			);
			echo '</p></div>';
		}
	}

	// =========================================================
	// 内容结构模板 CRUD
	// =========================================================

	/** 获取所有模板 */
	public static function get_templates() {
		$raw = get_option( self::TEMPLATES_KEY, '[]' );
		$arr = json_decode( $raw, true );
		return is_array( $arr ) ? $arr : array();
	}

	/** 保存模板列表 */
	private static function save_templates( $templates ) {
		update_option( self::TEMPLATES_KEY, wp_json_encode( $templates ), false );
	}

	/** AJAX：保存（新增或更新）单个模板 */
	public function ajax_save_template() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$id        = absint( $_POST['id'] ?? 0 );
		$name      = sanitize_text_field( wp_unslash( $_POST['name']      ?? '' ) );
		$structure = sanitize_textarea_field( wp_unslash( $_POST['structure'] ?? '' ) );
		$extra     = sanitize_textarea_field( wp_unslash( $_POST['extra']     ?? '' ) );

		if ( empty( $name ) || empty( $structure ) ) {
			wp_send_json_error( array( 'message' => '模板名称和结构内容不能为空。' ) );
		}

		$templates = self::get_templates();

		if ( $id ) {
			// 更新已有模板
			$found = false;
			foreach ( $templates as &$tpl ) {
				if ( (int) $tpl['id'] === $id ) {
					$tpl['name']      = $name;
					$tpl['structure'] = $structure;
					$tpl['extra']     = $extra;
					$found = true;
					break;
				}
			}
			unset( $tpl );
			if ( ! $found ) {
				wp_send_json_error( array( 'message' => '模板不存在。' ) );
			}
		} else {
			// 新增模板：生成 ID（最大 ID + 1）
			$max_id = 0;
			foreach ( $templates as $t ) {
				if ( (int) $t['id'] > $max_id ) $max_id = (int) $t['id'];
			}
			$id = $max_id + 1;
			$templates[] = array(
				'id'        => $id,
				'name'      => $name,
				'structure' => $structure,
				'extra'     => $extra,
			);
		}

		self::save_templates( $templates );
		wp_send_json_success( array( 'id' => $id, 'message' => '模板已保存。', 'templates' => $templates ) );
	}

	/** AJAX：删除模板 */
	public function ajax_delete_template() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) wp_send_json_error( array( 'message' => '无效 ID。' ) );

		$templates = array_values( array_filter( self::get_templates(), function ( $t ) use ( $id ) {
			return (int) $t['id'] !== $id;
		} ) );
		self::save_templates( $templates );
		wp_send_json_success( array( 'message' => '已删除。', 'templates' => $templates ) );
	}

	/** AJAX：获取全部模板（供生成/优化下拉框用） */
	public function ajax_get_templates() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();
		wp_send_json_success( array( 'templates' => self::get_templates() ) );
	}
}
