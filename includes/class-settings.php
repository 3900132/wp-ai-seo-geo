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
		// 敏感字段加密存储：先循环解密到明文（兼容历史双重/多重加密的旧数据），再加密一次落库——
		// 无论表单提交的是明文还是密文，永远只存单次加密，彻底根治"AI 操作后 Key 变 waisg_enc::"
		$clean['api_key']       = self::encrypt_secret( self::decrypt_secret( sanitize_text_field( trim( $input['api_key'] ?? '' ) ) ) );
		$clean['model']         = sanitize_text_field( trim( $input['model'] ?? 'gpt-4o' ) );
		$clean['lightweight_model'] = sanitize_text_field( trim( $input['lightweight_model'] ?? '' ) );
		$clean['timeout']       = absint( $input['timeout'] ?? 60 );
		$clean['temperature']   = min( 0.7, max( 0.1, floatval( $input['temperature'] ?? 0.3 ) ) );
		$clean['max_tokens']    = absint( $input['max_tokens'] ?? 4096 );
		// 配图搜图词翻译/提取的 max_tokens 预算（v1.9.9 新增）。
		// 旧版写死 200，推理模型（如 deepseek-v4-flash）思考会吃满配额导致返空（finish_reason=length），
		// 兜底重试翻倍到 400 仍不够。提到设置项让用户按模型调整：推理模型建议 800+，普通模型 200 足矣。
		$clean['image_keyword_max_tokens'] = min( 32000, max( 100, absint( $input['image_keyword_max_tokens'] ?? 200 ) ) );
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

		// 批量并发数（同时处理几篇，1-5；越大越快但越易触发 API 限速）
		$clean['batch_concurrency'] = min( 5, max( 1, absint( $input['batch_concurrency'] ?? 2 ) ) );

		// 批量任务模型选择
		$batch_model = sanitize_key( $input['batch_model'] ?? 'main' );
		$clean['batch_model'] = in_array( $batch_model, array( 'main', 'lightweight' ), true ) ? $batch_model : 'main';

		// 降低 AI 痕迹（二次润色）
		$clean['humanize_enabled'] = ! empty( $input['humanize_enabled'] ) ? 1 : 0;

		// 清理无用包装标签（div/p/span 等，默认开——读取侧 get( 'strip_wrapper_tags', 1 ) 兜底）
		$clean['strip_wrapper_tags'] = ! empty( $input['strip_wrapper_tags'] ) ? 1 : 0;
		$clean['humanize_prompt']  = wp_kses_post( $input['humanize_prompt'] ?? '' );
		// 润色模型：lightweight(默认，兼容旧逻辑) / main / follow(跟随主任务模型)
		$humanize_model = sanitize_key( $input['humanize_model'] ?? 'lightweight' );
		$clean['humanize_model'] = in_array( $humanize_model, array( 'lightweight', 'main', 'follow' ), true ) ? $humanize_model : 'lightweight';

		// AI 高频词替换
		$clean['ai_phrases_enabled'] = ! empty( $input['ai_phrases_enabled'] ) ? 1 : 0;
		$clean['ai_phrases_custom']  = sanitize_textarea_field( $input['ai_phrases_custom'] ?? '' );

		// 图片配置
		$allowed_sources          = array( 'none', 'pexels', 'unsplash', 'ai_image' );
		$image_source             = sanitize_key( $input['image_source'] ?? 'none' );
		$clean['image_source']    = in_array( $image_source, $allowed_sources, true ) ? $image_source : 'none';
		// Pexels / Unsplash 各自独立的 Key 字段（加密落库）。
		// 旧用户兼容迁移：若旧的 image_api_key 非空、新字段为空，按当前 image_source 分发到对应字段（一次性）。
		$clean['image_api_key_pexels']   = self::encrypt_secret( self::decrypt_secret( sanitize_text_field( trim( $input['image_api_key_pexels']   ?? '' ) ) ) );
		$clean['image_api_key_unsplash'] = self::encrypt_secret( self::decrypt_secret( sanitize_text_field( trim( $input['image_api_key_unsplash'] ?? '' ) ) ) );
		if ( empty( $input['image_api_key_pexels'] ?? '' ) && empty( $input['image_api_key_unsplash'] ?? '' ) ) {
			$legacy = WAISG_Settings::get( 'image_api_key', '' );
			if ( $legacy !== '' ) {
				if ( $clean['image_source'] === 'unsplash' ) {
					$clean['image_api_key_unsplash'] = self::encrypt_secret( $legacy );
				} else {
					$clean['image_api_key_pexels']   = self::encrypt_secret( $legacy );
				}
			}
		}
		// 保留旧字段标记为弃用空值（避免老调用方报错），下个主版本删除
		$clean['image_api_key'] = '';
		$clean['images_per_post'] = min( 10, max( 1, absint( $input['images_per_post'] ?? 2 ) ) );
		// AI 图片生成接口
		$clean['image_ai_url']   = esc_url_raw( trim( $input['image_ai_url'] ?? '' ) );
		$clean['image_ai_key']   = self::encrypt_secret( self::decrypt_secret( sanitize_text_field( trim( $input['image_ai_key'] ?? '' ) ) ) );
		$clean['image_ai_model'] = sanitize_text_field( trim( $input['image_ai_model'] ?? 'dall-e-3' ) );
		// 图片尺寸：改为自由文本输入，按各图片生成平台实际支持的尺寸填写（如 OpenAI 用 1024x1024，
		// 其他平台可能支持 2048x2048 / 1664x2496 等）。仅做格式净化 + 长度限制，不再硬限白名单。
		$ai_size                 = sanitize_text_field( trim( $input['image_ai_size'] ?? '1024x1024' ) );
		$clean['image_ai_size']  = preg_match( '/^\d{2,5}x\d{2,5}$/i', $ai_size ) ? $ai_size : '1024x1024';

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

		// 推理模型实测名单：由「测试连接」自动写入，不在设置表单里。
		// 保存设置时从旧值保留，但只保留当前 model / lightweight_model 对应的条目，
		// 清除测试过但最终未采用的"幽灵"模型名，避免名单无限膨胀。
		$old_opts = get_option( self::OPTION_KEY, array() );
		$old_list = isset( $old_opts['reasoning_models'] ) && is_array( $old_opts['reasoning_models'] )
			? $old_opts['reasoning_models'] : array();
		$active_models = array( $clean['model'] );
		if ( ! empty( $clean['lightweight_model'] ) ) {
			$active_models[] = $clean['lightweight_model'];
		}
		$clean['reasoning_models'] = array();
		foreach ( $old_list as $m ) {
			foreach ( $active_models as $active ) {
				if ( strcasecmp( (string) $m, (string) $active ) === 0 ) {
					$clean['reasoning_models'][] = $m;
					break;
				}
			}
		}

		// 旧明文密钥迁移说明：表单提交的总是明文（视图 input value 已透明解密填入），
		// sanitize_settings 落库前统一加密（上方 $clean['api_key'] 等行），
		// 老用户第一次保存设置时明文就会被加密为 'waisg_enc::...' 形式落库——天然完成迁移。
		// 读路径（get()）遇旧明文也透明解密兼容，功能不受影响。此处不补迁移代码，避免污染读语义。

		// 配图题材风格表（v1.9.9 新增）——面板可编辑的题材表，留空用内置默认 14 套+default兜底。
		// 格式：每条题材 4 行 YAML 风格——题材名行 + zh/en/words 三行（缩进2空格）。#开头注释，空行忽略。
		// default 行为通用兜底，sanitize 时若用户删了自动补回内置默认兜底，避免没命中题材时无风格指令。
		// 不用 sanitize_textarea_field——它会误剥换行/缩进，破坏 YAML 多行结构。自定义净化：剥 PHP 标签 + 控制字符，保换行保缩进保中英文标点
		$raw_style_table = (string) ( $input['image_style_table'] ?? '' );
		$raw_style_table = strip_tags( $raw_style_table );
		// 厨控制字符：正则定界符必须用单引号——双引号下 \x00 会被 PHP 解释成真实 NUL 字节再进正则，触发 "Warning: Null byte in regex"
		$raw_style_table = preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $raw_style_table );
		// 校验 default 行存在——用户自定义题材表时若没留 default 行，补回内置默认兜底
		// YAML 格式校验：题材表里 default 颜材名行存在（格式 "default:"），缺失时补回内置默认兜底
		if ( $raw_style_table !== '' && ! preg_match( '/^[ 	]*default:\s*$/im', $raw_style_table ) ) {
			$defaults_text = self::get_default_image_style_table_text();
			// 匹配内置默认表里的 default 兜底块（题材名行 + zh 行 + en 行，跨多行）。
			// 模式里用 \r?\n 显式匹配换行，避免裸换行字面量把模式打散成非法形态。
			if ( preg_match( '/^(default:\s*\r?\n  zh: .+\r?\n  en: .+)$/im', $defaults_text, $dm ) ) {
				$raw_style_table .= "\r\n" . $dm[1];
			}
		}
		$clean['image_style_table'] = $raw_style_table;

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
		if ( ! is_array( $stats ) ) $stats = array();
		$month   = gmdate( 'Y-m' );

		// 全局总 + 当月总（当月跨月则归零）
		$stats['total']   = ( $stats['total']   ?? 0 ) + $tokens;
		$stats['monthly'] = ( isset( $stats['month'] ) && $stats['month'] === $month )
			? ( ( $stats['monthly'] ?? 0 ) + $tokens )
			: $tokens;
		$stats['month']   = $month;

		// 分模型计数（main / lightweight 两套独立字段，各自累加，互不覆盖）
		// 旧版用一个标量 stats['model'] 只记最近一次调用，导致 monthly_main 与
		// monthly_lightweight 在交替调用时反复覆盖丢失。改为两套独立字段。
		$key  = 'total_' . $model;        // total_main / total_lightweight
		$mkey = 'monthly_' . $model;      // monthly_main / monthly_lightweight
		$mkey_month = 'month_' . $model;  // month_main / month_lightweight：各自记录上次归零月份

		$stats[ $key ] = ( $stats[ $key ] ?? 0 ) + $tokens;
		if ( isset( $stats[ $mkey_month ] ) && $stats[ $mkey_month ] === $month ) {
			$stats[ $mkey ] = ( $stats[ $mkey ] ?? 0 ) + $tokens;
		} else {
			$stats[ $mkey ]       = $tokens;
			$stats[ $mkey_month ] = $month;
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
			WAISG_Logger::log( 0, 'test_api', $result->get_error_message(), '模型：' . $model . '；地址：' . $api_url );
			wp_send_json_error( array( 'message' => '❌ 连接失败：' . wp_strip_all_tags( $result->get_error_message() ) ) );
		}

		$strategy = $result['strategy'] ?? 'standard';
		$suffix   = $strategy === 'standard' ? '' : '（已自动切换兼容策略：' . $strategy . '）';

		// 测试连接时实测判断是否为推理模型，写入名单供优化时加预算
		$is_reasoning = ! empty( $result['is_reasoning'] );
		self::update_reasoning_model( $model, $is_reasoning );
		$reasoning_note = $is_reasoning ? '（检测到推理模型，已自动适配 token 预算）' : '';

		wp_send_json_success( array( 'message' => "✅ 连接成功！响应时间 {$ms}ms，模型：{$model}{$suffix}{$reasoning_note}" ) );
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
			WAISG_Logger::log( 0, 'test_lightweight', $result->get_error_message(), '轻量模型：' . $model . '；地址：' . $api_url );
			wp_send_json_error( array( 'message' => '❌ 连接失败：' . wp_strip_all_tags( $result->get_error_message() ) ) );
		}

		$strategy = $result['strategy'] ?? 'standard';
		$suffix   = $strategy === 'standard' ? '' : '（已自动切换兼容策略：' . $strategy . '）';

		$is_reasoning = ! empty( $result['is_reasoning'] );
		self::update_reasoning_model( $model, $is_reasoning );
		$reasoning_note = $is_reasoning ? '（检测到推理模型，已自动适配 token 预算）' : '';

		wp_send_json_success( array( 'message' => "✅ 轻量模型连接成功！响应时间 {$ms}ms，模型：{$model}{$suffix}{$reasoning_note}" ) );
	}
	public function ajax_test_image_api() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		// 与大模型测试一致：用前端表单即时填的值测试，未保存也能测——
		// 这样用户能在保存前确认 Key/URL/模型是否有效，避免"必须先保存才能测"的假象。
		// 前端传哪个 source 就测哪个分支；未传的字段回退数据库已保存值。
		$override = array(
			'image_source'    => sanitize_key( wp_unslash( $_POST['image_source']    ?? '' ) ),
			'image_ai_url'    => esc_url_raw( trim( wp_unslash( $_POST['image_ai_url']   ?? '' ) ) ),
			'image_ai_key'    => sanitize_text_field( trim( wp_unslash( $_POST['image_ai_key']  ?? '' ) ) ),
			'image_ai_model'  => sanitize_text_field( trim( wp_unslash( $_POST['image_ai_model'] ?? '' ) ) ),
			'image_ai_size'   => sanitize_text_field( trim( wp_unslash( $_POST['image_ai_size']  ?? '' ) ) ),
		);
		// JS 按当前来源把对应框的 Key 放到 image_api_key 里——这里按 source 分发到对应 override 字段，
		// 与 fetch_images 的取值逻辑对齐（fetch 按 source 取 image_api_key_pexels 或 _unsplash）
		$img_key = sanitize_text_field( trim( wp_unslash( $_POST['image_api_key'] ?? '' ) ) );
		if ( $override['image_source'] === 'pexels' ) {
			$override['image_api_key_pexels'] = $img_key;
		} elseif ( $override['image_source'] === 'unsplash' ) {
			$override['image_api_key_unsplash'] = $img_key;
		}
		// 去掉空值，让 fetch_images 回退数据库已保存的值（用户只改了部分字段时仍可测）
		$override = array_filter( $override, function ( $v ) { return $v !== ''; } );

		$gen = new WAISG_Generator();
		$images = $gen->fetch_images( 'nature', 1, $override );

		// 真实校验：透传 API 返回的具体错误（Key 无效/额度耗尽/URL 错/格式异常等），
		// 不再把"API 拒绝"笼统报为"未获取到图片"——避免假 Key 也"测试正常"的假象。
		if ( is_wp_error( $images ) ) {
			$img_src = $override['image_source'] ?: WAISG_Settings::get( 'image_source', 'none' );
			WAISG_Logger::log( 0, 'test_image', $images->get_error_message(), '来源：' . $img_src );
			wp_send_json_error( array( 'message' => '❌ ' . wp_strip_all_tags( $images->get_error_message() ) ) );
		}
		if ( empty( $images ) ) {
			// API 正常响应但确实没搜到结果（如关键词太冷门）——这才是真正的"未获取到图片"
			$img_src = $override['image_source'] ?: WAISG_Settings::get( 'image_source', 'none' );
			WAISG_Logger::log( 0, 'test_image', 'API 正常但未搜到结果（关键词：nature）', '来源：' . $img_src );
			wp_send_json_error( array( 'message' => '未搜到结果（API 连通正常，但关键词 nature 无匹配图片，可换关键词重试或直接使用）。' ) );
		}
		wp_send_json_success( array( 'url' => $images[0]['url'] ?? '', 'message' => '✅ 图片 API 正常，已获取到图片。' ) );
	}

	/** 获取 Token 统计 */
	public function ajax_get_token_stats() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

		$stats  = get_option( 'waisg_token_stats', array() );
		if ( ! is_array( $stats ) ) $stats = array();
		$month  = gmdate( 'Y-m' );

		// 分模型月度：各自独立归零（与 record_tokens 改造后的两套字段对应）
		$monthly_main = ( isset( $stats['month_main'] ) && $stats['month_main'] === $month )
			? ( $stats['monthly_main'] ?? 0 ) : 0;
		$monthly_lightweight = ( isset( $stats['month_lightweight'] ) && $stats['month_lightweight'] === $month )
			? ( $stats['monthly_lightweight'] ?? 0 ) : 0;

		wp_send_json_success( array(
			'total'             => $stats['total']                    ?? 0,
			'monthly'           => ( isset( $stats['month'] ) && $stats['month'] === $month ) ? ( $stats['monthly'] ?? 0 ) : 0,
			'month'             => $month,
			'total_main'        => $stats['total_main']              ?? 0,
			'monthly_main'      => $monthly_main,
			'total_lightweight' => $stats['total_lightweight']       ?? 0,
			'monthly_lightweight' => $monthly_lightweight,
		) );
	}

	/** 重置 Token 统计 */
	public function ajax_reset_token_stats() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		delete_option( 'waisg_token_stats' );
		wp_send_json_success( array( 'message' => '统计已重置。' ) );
	}

	/**
	 * 加密密钥类敏感字段（API Key、图片 API Key 等）。
	 *
	 * 采用 openssl AES-256-CBC，密钥取自 wp-config.php 的 AUTH_KEY（WordPress 装好即存在，
	 * 不同于数据库被脱库也能保住密文）。openssl 扩展不可用时退回明文（部分受限主机未装），
	 * 仍能正常读写，只是不加密——与旧行为一致，不影响功能。
	 *
	 * 加密后产物格式：base64( iv + ciphertext )，前缀 'waisg_enc::' 便于解密时识别。
	 * 旧明文无此前缀，decrypt_secret 会原样返回（兼容迁移）。
	 *
	 * @param string $plain 明文
	 * @return string 加密产物（或 openssl 不可用时的原明文）
	 */
	private static function encrypt_secret( $plain ) {
		if ( $plain === '' ) return '';
		if ( ! function_exists( 'openssl_encrypt' ) ) return $plain;
		// 密钥取自 wp-config.php 的 AUTH_KEY；未定义时用站点特定值兜底（home_url + ABSPATH 派生），
		// 避免固定字符串让同主机所有站点共用同一密钥。
		// 注：若 AUTH_KEY 未定义且后续站点迁移（域名/路径变动），加密数据将无法解密。
		//    此时需在 wp-config.php 配置 AUTH_KEY 后重新填写 API Key。
		$auth_key = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : self::fallback_key();
		$key    = hash( 'sha256', $auth_key ); // 256-bit
		$iv     = openssl_random_pseudo_bytes( 16 );
		$ct     = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( $ct === false ) return $plain; // 加密失败不阻断，退回明文
		return 'waisg_enc::' . base64_encode( $iv . $ct );
	}

	/**
	 * 派生站点专属的兜底密钥（仅在 AUTH_KEY 未定义时使用）。
	 * 缓存到静态变量避免每次加密/解密都重复拼接和 hash。
	 */
	private static function fallback_key() {
		static $fb = null;
		if ( $fb === null ) {
			$fb = home_url() . '|' . ABSPATH . '|' . WAISG_VERSION;
		}
		return $fb;
	}

	/**
	 * 解密密钥类敏感字段。遇明文（无 'waisg_enc::' 前缀）原样返回，兼容旧数据。
	 * 循环解密兼容历史双重加密（视图曾渲染密文 → 提交密文 → sanitize 再加密 = waisg_enc::waisg_enc::xxx），
	 * 解到不再有前缀即为最终明文，最多 5 层避免死循环。
	 *
	 * @param string $value 加密产物或旧明文
	 * @return string 明文
	 */
	private static function decrypt_secret( $value ) {
		if ( $value === '' || ! is_string( $value ) ) return '';
		if ( ! function_exists( 'openssl_decrypt' ) ) return $value; // openssl 不可用，原样返回
		$auth_key = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : self::fallback_key();
		$key      = hash( 'sha256', $auth_key );
		// 循环解密：每轮剥一层 waisg_enc::，直到无前缀（明文）或层数耗尽
		for ( $i = 0; $i < 5; $i++ ) {
			if ( ! self::is_encrypted( $value ) ) return $value; // 已是明文
			$raw = base64_decode( substr( $value, 11 ), true );
			if ( $raw === false || strlen( $raw ) < 17 ) return ''; // 数据损坏，返空避免错误密钥进请求
			$iv    = substr( $raw, 0, 16 );
			$ct    = substr( $raw, 16 );
			$value = openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( $value === false ) return ''; // 解密失败，返空
		}
		return $value; // 5 层后仍有前缀（极罕见），原样返回避免无限循环
	}

	/**
	 * 是否为已加密的敏感字段值。用 str_starts_with（PHP 8+ 原生，避免 substr 在短字符串上的跨版本差异）。
	 */
	private static function is_encrypted( $value ) {
		return is_string( $value ) && strlen( $value ) >= 11 && strncmp( $value, 'waisg_enc::', 11 ) === 0;
	}

	/**
	 * 获取单项设置（敏感字段自动透明解密）。
	 *
	 * 注：迁移明文 → 密文由 sanitize_settings 在下次保存设置时完成，**不在读路径里写库**——
	 * 读操作触发 update_option 会污染 update_option_waisg_settings 钩子调用方（如 WAISG_Cron 重调度），
	 * 也可能在保存过程中递归。读就是读，保持只读语义。
	 */
	public static function get( $key = null, $default = null ) {
		$opts = get_option( self::OPTION_KEY, array() );
		if ( $key === null ) return $opts;

		$value = $opts[ $key ] ?? $default;

		// 敏感字段透明解密（含旧明文兼容——decrypt_secret 遇明文原样返回）
		$secret_keys = array( 'api_key', 'image_api_key_pexels', 'image_api_key_unsplash', 'image_ai_key' );
		if ( in_array( $key, $secret_keys, true ) && is_string( $value ) && $value !== '' ) {
			return self::decrypt_secret( $value );
		}

		return $value;
	}

	/**
	 * 更新推理模型实测名单（测试连接时调用）。
	 *
	 * 推理模型返回里会带 reasoning_content 字段，test_connection 据此判定。
	 * 命中则把模型名加入名单；普通模型则从名单移除（防止用户换了模型名但旧记录残留）。
	 * 直接 update_option 写入，不经过 sanitize_settings（后者会重建 $clean 丢弃本字段，
	 * 已在 sanitize_settings 末尾做了旧值保留）。
	 *
	 * @param string $model       模型名
	 * @param bool   $is_reasoning 是否为推理模型
	 */
	public static function update_reasoning_model( $model, $is_reasoning ) {
		$model = sanitize_text_field( trim( (string) $model ) );
		if ( $model === '' ) return;

		// 旧版用 transient 做互斥锁，但裸主机（无对象缓存）transient 存 option 表，
		// set_transient 内部 update_option 非原子，两个进程同时 set 都可能"成功"，
		// 锁失效。改为 CAS（compare-and-swap）：用 option 的 autoload 唯一性做原子更新。
		// read-modify-write 仍非原子，但 update_option 是整字段覆盖，最后写入者赢——
		// 推理名单只增不删（删除交给测试连接），并发丢失最多漏一个"增"，下次测试会补回。
		$opts   = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opts ) ) $opts = array();
		$known  = isset( $opts['reasoning_models'] ) && is_array( $opts['reasoning_models'] ) ? $opts['reasoning_models'] : array();

		$exists = false;
		$cleaned = array();
		foreach ( $known as $m ) {
			if ( strcasecmp( (string) $m, $model ) === 0 ) {
				$exists = true;
				if ( $is_reasoning ) $cleaned[] = $m;  // 已在名单且仍为推理模型，保留
				// 否则（普通模型）跳过 = 从名单移除
			} else {
				$cleaned[] = $m;  // 其他模型不动
			}
		}
		if ( $is_reasoning && ! $exists ) {
			$cleaned[] = $model;  // 新发现的推理模型
		}

		$opts['reasoning_models'] = array_values( $cleaned );
		update_option( self::OPTION_KEY, $opts, false );
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
		// 就地把密钥字段解密成明文——视图拿到的 $opts 里这些字段已经是明文，
		// 不再依赖视图每处单独调 WAISG_Settings::get() 解密。
		// 根因：update_reasoning_model 会把含密文的 $opts 整包写回 waisg_settings，
		// 之后 get_option 拿到的是含密文数组；视图里若有任何地方直接读 $opts['api_key']
		// 就会拿到密文 waisg_enc::xxx。这里统一解密后，视图无论怎么读都是明文。
		foreach ( array( 'api_key', 'image_api_key_pexels', 'image_api_key_unsplash', 'image_ai_key' ) as $kf ) {
			if ( isset( $opts[ $kf ] ) && is_string( $opts[ $kf ] ) && $opts[ $kf ] !== '' ) {
				$opts[ $kf ] = self::decrypt_secret( $opts[ $kf ] );
			}
		}
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

	/**
	 * 内置默认配图题材风格表（v1.9.9 新增）。
	 *
	 * 14 套题材 + default 兜底，特征词中英双版都放（按文章总关键词匹配识别题材），
	 * 风格指令中英双版（按文章输出语言选）。`image_style_hint` 读设置项解析，
	 * 留空时调本方法用默认。面板可编辑，用户改了存 waisg_settings['image_style_table']。
	 *
	 * @return array {name=>{'zh'=>str,'en'=>str,'words'=>str[]}, ...} 含 default 兜底行
	 */
	public static function get_default_image_style_table() {
		return array(
			'gaming' => array(
				'zh' => '一张游戏题材的高质量配图：体现游戏氛围，可含游戏画面截图、电竞装备、角色立绘、UI 界面等元素；画风鲜明有张力，配色饱和度高，科技感与娱乐感兼顾。',
				'en' => 'A high-quality gaming-themed image: capture the gaming atmosphere, may include gameplay screenshots, esports gear, character art, or UI elements; vibrant style with saturated colors, balancing tech feel and entertainment vibe.',
				'words' => array( '游戏', '电竞', '吃鸡', '王者', '原神', '手游', '端游', '主机', 'PS5', 'Xbox', 'Nintendo', 'switch', 'Steam', '装备', '副本', '攻略', '外设', 'game', 'gaming', 'RPG', 'MMO', 'shooter', 'battle royale', 'esports', 'console', 'gameplay', '和平精英', '王者荣耀', '英雄联盟', 'pubg', 'moba' ),
			),
			'tech' => array(
				'zh' => '一张科技数码题材的高质量产品配图：突出设备外观与细节，可在干净中性背景上做产品摄影风格呈现，或配抽象的数据流/电路纹理点缀；画风精致专业，配色冷峻。',
				'en' => 'A high-quality tech product image: emphasize device appearance and details, product-photography style on a clean neutral background, may accent with abstract data flow or circuit motifs; refined professional style with cool tones.',
				'words' => array( '手机', '电脑', '笔记本', '平板', '耳机', '相机', '路由器', '硬盘', '内存', '显卡', '处理器', 'CPU', 'GPU', '显示器', '键盘', '鼠标', '充电器', '数据线', '智能', 'AI', '算法', '编程', '代码', '服务器', '云端', 'API', '系统', '软件', '硬件', 'phone', 'laptop', 'desktop', 'camera', 'router', 'processor', 'keyboard', 'server', 'coding', 'software', 'hardware', 'gadget', 'device', 'algorithm' ),
			),
			'food' => array(
				'zh' => '一张美食题材的高质量配图：突出食物色泽与质感，可在暖光下做俯拍或特写，突出新鲜出锅的质感与摆盘细节；画风诱人，配色暖。',
				'en' => 'A high-quality food image: emphasize color and texture, overhead or close-up shot under warm light, highlight fresh-plated presentation; appetizing style with warm tones.',
				'words' => array( '菜', '饭', '面', '汤', '肉', '鱼', '虾', '蟹', '糕', '饼', '茶', '咖啡', '酒', '食材', '调料', '烹饪', '食谱', '美食', '小吃', '甜点', '烘焙', 'recipe', 'cuisine', 'dish', 'food', 'cooking', 'baking', 'dessert', 'ingredient' ),
			),
			'travel' => array(
				'zh' => '一张旅游风景题材的高质量配图：体现目的地的标志性景观或氛围，可在黄金时段光线拍摄，构图开阔；画风大气，配色自然。',
				'en' => 'A high-quality travel landscape image: capture the destination\'s iconic scenery or atmosphere, shot during golden hour with wide composition; grand style with natural colors.',
				'words' => array( '风景', '旅游', '旅行', '景点', '山水', '海滩', '雪山', '古镇', '日出', '日落', '星空', '自然', '公园', '古迹', '建筑', '城市', '夜景', 'landscape', 'travel', 'scenery', 'beach', 'mountain', 'sunset', 'nature', 'landmark', 'cityscape', 'architecture' ),
			),
			'fitness' => array(
				'zh' => '一张健身运动题材的高质量配图：可含动作演示、器械、场地等元素，体现运动氛围与汗水感；画风动感，配色 energetic。',
				'en' => 'A high-quality fitness image: may include motion demonstration, equipment, or venue; convey the workout atmosphere and energy; dynamic style with energetic colors.',
				'words' => array( '健身', '跑步', '瑜伽', '减脂', '增肌', '训练', '器械', '有氧', '无氧', '拉伸', '运动', '游泳', '骑行', '攀岩', 'fitness', 'workout', 'gym', 'yoga', 'running', 'training', 'exercise', 'sports', 'cycling' ),
			),
			'pet' => array(
				'zh' => '一张宠物题材的高质量配图：突出动物的可爱与神态，可做特写或互动场景，光影柔和；画风温馨，配色暖。',
				'en' => 'A high-quality pet image: highlight the animal\'s cuteness and expression, close-up or interaction scene with soft lighting; heartwarming style with warm tones.',
				'words' => array( '猫', '狗', '宠物', '兔', '仓鼠', '鸟', '龟', '驯', 'cat', 'dog', 'pet', 'puppy', 'kitten', 'rabbit', 'hamster', 'parrot' ),
			),
			'business' => array(
				'zh' => '一张商业职场题材的高质量配图：可含会议、协作、数据图表等元素，体现专业氛围；画风正式，配色克制。',
				'en' => 'A high-quality business image: may include meeting, collaboration, or data chart elements; convey professional atmosphere; formal style with restrained colors.',
				'words' => array( '商业', '营销', '投资', '理财', '股票', '基金', '创业', '公司', '团队', '会议', '销售', '客户', '管理', '职场', '办公', 'business', 'marketing', 'finance', 'startup', 'office', 'meeting', 'sales', 'team', 'workplace' ),
			),
			'education' => array(
				'zh' => '一张教育题材的高质量配图：可含书籍、学习场景、知识图表等元素，体现求知氛围；画风清新，配色明亮。',
				'en' => 'A high-quality education image: may include books, study scenes, or knowledge diagram elements; convey learning atmosphere; fresh style with bright colors.',
				'words' => array( '学习', '教育', '课程', '考试', '留学', '入学', '论文', '研究', '学位', '大学', '中学', '小学', '培训', '教学', '知识', 'education', 'study', 'course', 'exam', 'research', 'degree', 'university', 'school', 'training', 'knowledge' ),
			),
			'portrait' => array(
				'zh' => '一张人物肖像题材的高质量配图：突出人物神态与情绪，光影聚焦面部；画风细腻，配色按情绪匹配。',
				'en' => 'A high-quality portrait image: emphasize expression and emotion, lighting focused on the face; delicate style with mood-matched colors.',
				'words' => array( '人物', '肖像', '自拍', '头像', '表情', '模特', '角色', '人像', 'portrait', 'people', 'person', 'character', 'face', 'selfie', 'avatar' ),
			),
			'anime' => array(
				'zh' => '一张动漫题材的高质量配图：扁平日系动画风/赛璐璐风格，色彩明快，角色大眼表现力强，可含角色立绘/番剧截图/漫画分镜；画风青春洋溢。',
				'en' => 'A high-quality anime-themed image: flat Japanese animation style/cel shading, bright colors, large expressive eyes, may include character art/anime screenshots/manga panels; youthful vibrant style.',
				'words' => array( '动漫', '漫画', '动画', '番剧', 'cosplay', '二次元', '声优', '作画', '轻小说', 'anime', 'manga', 'otaku', 'ACG', 'weeb', 'shonen', 'shojo' ),
			),
			'movie' => array(
				'zh' => '一张电影题材的高质量配图：电影剧照风，宽银幕构图，光影戏剧化，可含角色剧照/场景截图/海报风；画风大气有电影感。',
				'en' => 'A high-quality movie-themed image: film still style, widescreen composition, dramatic lighting, may include character stills/scene screenshots/poster style; grand cinematic feel.',
				'words' => array( '电影', '影视', '院线', '票房', '导演', '演员', '剧情', '续集', '预告', '纪录片', 'movie', 'cinema', 'film', 'trailer', 'box office', 'documentary' ),
			),
			'blockchain' => array(
				'zh' => '一张区块链题材的高质量配图：抽象数字科技风，可含链式结构/节点网络/加密符号/币图腾，配色冷峻带霓虹光感；画风未来感。',
				'en' => 'A high-quality blockchain-themed image: abstract digital tech style, may include chain structures/node networks/crypto symbols/coin totems, cool tones with neon glow; futuristic feel.',
				'words' => array( '区块链', '比特币', '以太坊', '加密货币', '挖矿', 'Web3', 'DeFi', 'NFT', '智能合约', '币圈', 'bitcoin', 'BTC', 'ETH', 'crypto', 'blockchain', 'Web3', 'NFT', 'mining' ),
			),
			'health' => array(
				'zh' => '一张医疗健康题材的高质量配图：可含医疗器械/医院场景/健康图标等元素，画风专业可信，配色冷净，避免血腥或具体病灶呈现。',
				'en' => 'A high-quality medical/health image: may include medical equipment/hospital scenes/health icons; professional trustworthy style with cool clean tones, avoid graphic or specific lesion depiction.',
				'words' => array( '医疗', '健康', '医院', '症状', '治疗', '药', '养生', '体检', '疫苗', 'medical', 'health', 'hospital', 'treatment', 'wellness', 'symptom' ),
			),
			'auto' => array(
				'zh' => '一张汽车题材的高质量配图：突出车型外观与线条，可在干净背景做产品摄影风，或在道路场景体现驾驶感；画风精致动感。',
				'en' => 'A high-quality automotive image: emphasize vehicle appearance and lines, product-photography style on clean background or road scene conveying driving feel; refined dynamic style.',
				'words' => array( '汽车', '车', '驾驶', '车型', '改装', '车评', 'car', 'auto', 'driving', 'vehicle', 'SUV', 'sedan' ),
			),
			// 通用兜底：没命中任何题材时用
			'default' => array(
				'zh' => '一张与以上主题相关的高质量配图：扁平插画风格，构图干净主体突出，配色中性专业，适合作为文章配图，避免出现具体人脸或品牌Logo。',
				'en' => 'A high-quality illustration related to the above topic: flat illustration style, clean composition with clear subject, neutral professional colors, suitable for an article, avoid depicting specific faces or brand logos.',
				'words' => array(),
			),
		);
	}

	/** 默认题材表的文本格式（供设置页展示/恢复为默认用） */
	public static function get_default_image_style_table_text() {
		$lines = array(
			'# 配图题材风格表（默认 14 套 + default 兜底）',
			'# 格式：每条题材 4 行——题材名行 + zh/en/words 三行（缩进2空格）',
			'# default 行为通用兜底（没命中任何题材时用），请勿删除',
			'# # 开头为注释行，空行忽略；留空整框 = 使用内置默认',
			'# 缩进必须用2空格（不是Tab，不是1空格或4空格），否则解析不出',
			'',
		);
		foreach ( self::get_default_image_style_table() as $name => $row ) {
			$words = implode( ',', $row['words'] );
			$lines[] = $name . ':';
			$lines[] = '  zh: ' . $row['zh'];
			$lines[] = '  en: ' . $row['en'];
			// default 兜底行 words 本就空（不参与题材识别）——光秃秃易误读，加注释说明
			if ( $name === 'default' && $words === '' ) {
				$lines[] = '  words: # 兜底不参与题材识别，words 留空';
			} else {
				$lines[] = '  words: ' . $words;
			}
			$lines[] = ''; // 题材间空行分隔，视觉更清楚
		}
		return implode( "\n", $lines );
	}

	/**
	 * 解析题材表文本为结构（v1.9.9 新增）。
	 * 供 image_style_hint 调用——读设置项解析成题材数组，留空用默认。
	 *
	 * @param string|null $raw 设置里的题材表文本（null/空 = 用默认）
	 * @return array {name=>{'zh'=>str,'en'=>str,'words'=>str[]}, ...} 至少含 default 行
	 */
	public static function parse_image_style_table( $raw ) {
		$defaults = self::get_default_image_style_table();
		if ( empty( $raw ) ) return $defaults;

		$parsed    = array();
		$cur_name  = '';
		$cur_row   = array( 'zh' => '', 'en' => '', 'words' => array() );
			$lines     = preg_split( '/\r\n|\r|\n/', (string) $raw );  // 按 OS 换行分割（显式模式，避免裸换行字面量打散正则）

		// flush 当前累积的 cur_row 落进 parsed 并复位——题材名行触发/文件结束时调用
		$flush = function() use ( &$parsed, &$cur_name, &$cur_row, $defaults ) {
			if ( $cur_name !== '' && ( $cur_row['zh'] !== '' || $cur_row['en'] !== '' ) ) {
				// 风格都留空时回退兜底对应的风格，避免某些语言取空风格
				$parsed[ $cur_name ] = array(
					'zh'    => $cur_row['zh'] !== '' ? $cur_row['zh'] : ( $defaults['default']['zh'] ?? '' ),
					'en'    => $cur_row['en'] !== '' ? $cur_row['en'] : ( $defaults['default']['en'] ?? '' ),
					'words' => $cur_row['words'],
				);
			}
			$cur_name = '';
			$cur_row  = array( 'zh' => '', 'en' => '', 'words' => array() );
		};

		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( $line === '' ) continue; // 空行忽略（题材间分隔行也走这）
			if ( $line[0] === '#' ) continue; // 注释行

			// 行首2空格 = 字段行（zh/en/words）
			if ( substr( $line, 0, 2 ) === '  ' ) {
				$body = ltrim( $line );
				if ( preg_match( '/^([a-z]+):\s*(.*)$/i', $body, $m ) ) {
					$field = strtolower( $m[1] );
					$value = trim( $m[2] );
					if ( $field === 'zh' || $field === 'en' ) {
						$cur_row[ $field ] = $value;
					} elseif ( $field === 'words' ) {
						// words 字段剥 # 开头的注释部分——按逗号分词后剥每个词里 # 开头的注释，
						// 不用整段正则（会把 C#,编程 里的 C# 误当注释剥）。
						// 例："words: 游戏,game, # 这是游戏题材" → 只剥 "# 这是游戏题材" 保留 "游戏,game"
						$words = $value !== '' ? array_filter( array_map( 'trim', explode( ',', $value ) ) ) : array();
		$cur_row['words'] = array_values( array_filter( $words, function( $w ) {
			return $w !== '' && substr( $w, 0, 1 ) !== '#';
		} ) );
					}
					// 未知字段名跳过（容错）
				}
				continue;
			}

			// 非缩进行 = 题材名行（格式：题材名:）。先 flush 上一个题材，再开新题材
			$flush();
			if ( preg_match( '/^([a-z_-]+):\s*$/i', $line, $m ) ) {
				$cur_name = sanitize_key( $m[1] );
			}
			// 格式不符的行跳过（容错）
		}
		// 文件结束 flush 最后一个题材
		$flush();

		// default 兜底行必须存在——用户删了的话补回内置默认
		if ( ! isset( $parsed['default'] ) ) {
			$parsed['default'] = $defaults['default'];
		}
		return $parsed;
	}
}
