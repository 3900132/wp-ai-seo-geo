<?php
/**
 * 定时自动优化
 * - 使用 WP-Cron 定期选取文章执行 AI 优化
 * - 优化结果存入暂存区（entry_type='optimized'），不直接覆盖文章
 * - 用户在"优化历史→待处理"中审阅后手动决定是否应用
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Cron {

	const HOOK = 'waisg_auto_optimize';

	public function __construct() {
		// 注册自定义 cron 间隔（需在 wp_schedule_event 调用之前）
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );

		// 绑定 cron hook 到执行方法
		add_action( self::HOOK, array( $this, 'run' ) );

		// 设置保存后重新调度（频率/开关变化时）
		add_action( 'update_option_waisg_settings', array( $this, 'reschedule' ), 10, 2 );

		// AJAX：立即执行一次
		add_action( 'wp_ajax_waisg_run_cron_now', array( $this, 'ajax_run_now' ) );
		// AJAX：获取日志与下次执行时间
		add_action( 'wp_ajax_waisg_get_cron_log', array( $this, 'ajax_get_cron_log' ) );

		// 仅在管理后台检查调度状态，避免每次前端访问都查询 wp_next_scheduled（数据库开销）。
		// 前端访问不应触发调度逻辑；设置变更由 reschedule() 处理，激活/停用由 hook 处理。
		if ( is_admin() ) {
			$this->maybe_schedule();
		}
	}

	// =========================================================
	// 自定义 Cron 间隔
	// =========================================================

	/** 注册 weekly（兼容 WP < 6.0）和 biweekly 间隔 */
	public function add_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => '每周一次',
			);
		}
		$schedules['waisg_biweekly'] = array(
			'interval' => 14 * DAY_IN_SECONDS,
			'display'  => '每两周一次',
		);
		return $schedules;
	}

	// =========================================================
	// 调度管理
	// =========================================================

	/**
	 * 插件停用时清除计划任务（由 wp-ai-seo-geo.php deactivation hook 调用）
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * 确保调度状态与当前设置一致（每次 plugins_loaded 时自动调用）
	 */
	private function maybe_schedule() {
		$enabled = WAISG_Settings::get( 'cron_enabled', 0 );
		if ( ! $enabled ) {
			// 已禁用：清除残留调度
			self::unschedule();
			return;
		}
		// 已启用但尚未计划：立即注册
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$freq = WAISG_Settings::get( 'cron_frequency', 'daily' );
			wp_schedule_event( time(), $this->freq_to_interval( $freq ), self::HOOK );
		}
	}

	/**
	 * 设置保存后重新调度（频率或启用状态可能已变化）
	 *
	 * @param mixed $old 旧设置值
	 * @param mixed $new 新设置值
	 */
	public function reschedule( $old, $new ) {
		self::unschedule();
		if ( ! empty( $new['cron_enabled'] ) ) {
			$freq = $new['cron_frequency'] ?? 'daily';
			// 延迟 60s 首次执行，避免保存后立即触发
			wp_schedule_event( time() + 60, $this->freq_to_interval( $freq ), self::HOOK );
		}
	}

	/**
	 * 将设置频率值映射到 WP cron interval 名称
	 *
	 * @param string $freq daily|twicedaily|weekly|biweekly
	 * @return string WP cron interval key
	 */
	private function freq_to_interval( $freq ) {
		$map = array(
			'daily'      => 'daily',
			'twicedaily' => 'twicedaily',
			'weekly'     => 'weekly',
			'biweekly'   => 'waisg_biweekly',
		);
		return $map[ $freq ] ?? 'daily';
	}

	// =========================================================
	// 执行优化
	// =========================================================

	/**
	 * 执行一次定时优化任务
	 * 由 WP Cron 触发，或由 AJAX「立即执行」调用
	 */
	public function run() {
		$enabled = WAISG_Settings::get( 'cron_enabled', 0 );
		if ( ! $enabled ) return;

		// 放宽 PHP 执行时间限制：cron 跑多篇文章 + humanize 耗时较长，
		// 部分主机默认 30/60s 会中途中断 cron。call() 内会按 timeout 再调一次，
		// 这里入口先放一次兜底。部分主机禁用该函数时静默失败（@ 抑制）。
		// 上限 600 秒：避免 AI 请求挂起导致 PHP 进程长时间占用服务器资源。
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		$per_run    = min( 20, max( 1, absint( WAISG_Settings::get( 'cron_per_run', 5 ) ) ) );
		$target     = WAISG_Settings::get( 'cron_target', 'oldest' );
		$post_types = WAISG_Settings::get( 'cron_post_types', array() );

		// 若未指定文章类型，使用插件全局启用的类型
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = WAISG_Settings::get_post_types();
		}

		$posts = $this->get_target_posts( $per_run, $target, $post_types );
		if ( empty( $posts ) ) {
			$this->write_log( 0, '没有找到符合条件的已发布文章。' );
			return;
		}

		$done     = 0;
		$errors   = array();

		foreach ( $posts as $post ) {
			$post_id = $post->ID;

			$vars = array(
				'title'     => $post->post_title,
				'content'   => $post->post_content,
				'excerpt'   => $post->post_excerpt,
				'seo_title' => (string) get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'title' ),       true ),
				'seo_desc'  => (string) get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'description' ), true ),
				'seo_kw'    => (string) get_post_meta( $post_id, WAISG_Meta_Box::get_seo_field_name( 'keywords' ),    true ),
			);

			// 保护原文中的图片，防止 AI 优化时丢失
			$img_protected = WAISG_AI_API::protect_images( $vars['content'] );
			$vars['content'] = $img_protected['html'];

			$prompts = WAISG_AI_API::build_optimize_all_prompt( $vars );

			// 根据内容长度动态调整 max_tokens 和 timeout；根据批量任务模型设置选择模型
			$extra = WAISG_AI_API::build_long_content_extra( $vars['content'] );
			if ( WAISG_Settings::get( 'batch_model', 'main' ) === 'lightweight' ) {
				$lm = WAISG_Settings::get( 'lightweight_model', '' );
				if ( $lm ) {
					$extra['model'] = $lm;
				}
			}

			$result = WAISG_AI_API::call_prompts( $prompts, $extra );

			if ( is_wp_error( $result ) ) {
				$errors[] = 'ID ' . $post_id . '：' . $result->get_error_message();
				WAISG_Logger::log( $post_id, 'cron', $result->get_error_message() );
				continue;
			}

			$data = WAISG_AI_API::parse_json_response( $result['text'], 'cron', $post_id );
			if ( ! $data ) {
				$errors[] = 'ID ' . $post_id . '：AI 返回格式异常';
				WAISG_Logger::log( $post_id, 'cron', 'AI 返回格式异常（无法解析 JSON）', 'AI返回：' . mb_substr( $result['text'], 0, 300, 'UTF-8' ) );
				continue;
			}

			// 降低 AI 痕迹：先 humanize（如开启），再外层兜底 filter
			$cron_content = WAISG_AI_API::sanitize_content( $data['content'] ?? '' );
			// 还原图片占位符
			$cron_content = WAISG_AI_API::restore_images( $cron_content, $img_protected['map'] );
			if ( ! empty( $cron_content ) ) {
				if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
					// 定时任务模型偏好直接读 batch_model 设置（无前端传参）
					$cron_use_model = WAISG_Settings::get( 'batch_model', 'main' );
					$cron_content = WAISG_AI_API::humanize( $cron_content, $cron_use_model );
				}
				$cron_content = WAISG_AI_API::filter_ai_phrases( $cron_content );
			}

			// SEO 本地修复（零 Token）
			$cron_title   = sanitize_text_field( $data['title'] ?? $post->post_title );
			$cron_excerpt = sanitize_textarea_field( $data['excerpt'] ?? '' );
			$cron_seo_t   = sanitize_text_field( $data['seo_title'] ?? '' );
			$cron_seo_d   = sanitize_textarea_field( $data['seo_description'] ?? '' );
			$cron_seo_kw  = sanitize_text_field( $data['seo_keywords'] ?? '' );

			$fixed = WAISG_AI_API::auto_fix_seo( array(
				'title'     => $cron_title,
				'seo_title' => $cron_seo_t,
				'seo_desc'  => $cron_seo_d,
				'seo_kw'    => $cron_seo_kw,
				'excerpt'   => $cron_excerpt,
				'content'   => $cron_content,
			), $post_id );

			WAISG_History::save_staged( array(
				'entry_type'   => 'optimized',
				'post_id'      => $post_id,
				'post_title'   => $fixed['title'],
				'post_content' => $cron_content,
				'post_excerpt' => $fixed['excerpt'],
				'seo_title'    => $fixed['seo_title'],
				'seo_desc'     => $fixed['seo_desc'],
				'seo_kw'       => $fixed['seo_kw'],
			) );

			$done++;

			// AI 调用之间短暂停顿，防止 API 过载
			// 用 usleep 替代 sleep：部分主机 max_execution_time 会中断 sleep 但不计 usleep
			if ( $done < count( $posts ) ) {
				usleep( 2000000 ); // 2 秒（微秒）
			}
		}

		$note = empty( $errors ) ? '' : implode( '；', array_slice( $errors, 0, 3 ) );
		$this->write_log( $done, $note );
	}

	// =========================================================
	// 文章查询
	// =========================================================

	/**
	 * 按策略查询目标文章
	 *
	 * @param int    $limit      最多获取篇数
	 * @param string $target     oldest|least_optimized|random
	 * @param array  $post_types 文章类型列表
	 * @return WP_Post[]
	 */
	private function get_target_posts( $limit, $target, $post_types ) {
		$base = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
		);

		switch ( $target ) {

			case 'least_optimized':
				// OR meta_query + meta_key 排序：
				// - NOT EXISTS 分支：从未优化过的文章（meta_value=NULL，ASC 时排最前）
				// - EXISTS 分支：已优化过的文章，按优化次数升序
				$args = array_merge( $base, array(
					'meta_query' => array(
						'relation' => 'OR',
						array( 'key' => '_waisg_opt_count', 'compare' => 'NOT EXISTS' ),
						array( 'key' => '_waisg_opt_count', 'compare' => 'EXISTS' ),
					),
					'meta_key' => '_waisg_opt_count',
					'orderby'  => array( 'meta_value_num' => 'ASC', 'date' => 'ASC' ),
				) );
				break;

			case 'random':
				$args = array_merge( $base, array( 'orderby' => 'rand' ) );
				break;

			default: // oldest
				$args = array_merge( $base, array(
					'orderby' => 'date',
					'order'   => 'ASC',
				) );
				break;
		}

		return ( new WP_Query( $args ) )->posts;
	}

	// =========================================================
	// 日志
	// =========================================================

	/** 写入执行日志到 wp_options */
	private function write_log( $count, $note = '' ) {
		update_option( 'waisg_cron_log', array(
			'last_run'   => current_time( 'mysql' ),
			'last_count' => (int) $count,
			'note'       => (string) $note,
		), false );
	}

	// =========================================================
	// AJAX 处理
	// =========================================================

	/** AJAX：立即手动执行一次定时优化 */
	public function ajax_run_now() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$this->run();

		$log  = get_option( 'waisg_cron_log', array() );
		$next = wp_next_scheduled( self::HOOK );
		wp_send_json_success( array(
			'count'    => $log['last_count'] ?? 0,
			'last_run' => $log['last_run']   ?? '',
			'note'     => $log['note']       ?? '',
			'next_run' => $next
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				: '未计划',
		) );
	}

	/** AJAX：获取执行日志和下次执行时间 */
	public function ajax_get_cron_log() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$log  = get_option( 'waisg_cron_log', array() );
		$next = wp_next_scheduled( self::HOOK );
		wp_send_json_success( array(
			'last_run'   => ! empty( $log['last_run'] )   ? esc_html( $log['last_run'] )  : '尚未执行过',
			'last_count' => isset( $log['last_count'] )   ? (int) $log['last_count']       : 0,
			'note'       => ! empty( $log['note'] )       ? esc_html( $log['note'] )       : '',
			'next_run'   => $next
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				: '未计划（请启用定时优化并保存设置）',
		) );
	}
}
