<?php
/**
 * AI 错误日志
 *
 * 记录 AI 操作出错（API 失败 / 返回格式异常等）的明细：文章 ID、场景、错误原因、时间。
 * 支持：查看、清除、导出 CSV；按天数自动清除、按条数上限保留。
 *
 * 存储：option `waisg_error_logs`（数组，最新的在前）。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Logger {

	const OPTION_KEY = 'waisg_error_logs';

	/** 场景代码 → 中文名 */
	private static $context_labels = array(
		'optimize_all'      => '一键优化全部',
		'optimize_seo_only' => '仅优化 SEO',
		'optimize_single'   => '单字段优化',
		'generate'          => 'AI 生成文章',
		'rewrite'           => '改写 / 伪原创',
		'batch'             => '批量优化',
		'cron'              => '定时自动优化',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'wp_ajax_waisg_get_error_logs',   array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_waisg_clear_error_logs', array( $this, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_waisg_export_error_logs', array( $this, 'ajax_export_logs' ) );
	}

	// =========================================================
	// 记录（供各 AI 出错点静态调用）
	// =========================================================

	/**
	 * 记录一条 AI 错误日志
	 *
	 * @param int    $post_id 关联文章 ID（0 = 无关联，如生成新文章）
	 * @param string $context 场景代码（见 $context_labels）
	 * @param string $message 错误原因
	 * @param string $extra   额外信息（如关键词、第几篇），可空
	 */
	public static function log( $post_id, $context, $message, $extra = '' ) {
		// 关闭则不记录
		if ( ! WAISG_Settings::get( 'error_log_enabled', 1 ) ) {
			return;
		}

		$logs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $logs ) ) $logs = array();

		// 新记录插到最前
		array_unshift( $logs, array(
			'time'    => current_time( 'mysql' ),
			'post_id' => absint( $post_id ),
			'context' => sanitize_key( $context ),
			'message' => mb_substr( sanitize_text_field( $message ), 0, 500, 'UTF-8' ),
			'extra'   => mb_substr( sanitize_text_field( $extra ), 0, 200, 'UTF-8' ),
		) );

		$logs = self::apply_retention( $logs );

		update_option( self::OPTION_KEY, $logs, false );
	}

	/**
	 * 应用保留策略：按天数清除过期 + 按条数截断
	 *
	 * @param array $logs
	 * @return array
	 */
	private static function apply_retention( $logs ) {
		// 1. 按天数自动清除（0 = 不限）
		$days = absint( WAISG_Settings::get( 'error_log_days', 30 ) );
		if ( $days > 0 ) {
			$cutoff = strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS;
			$logs = array_values( array_filter( $logs, function ( $row ) use ( $cutoff ) {
				return ! empty( $row['time'] ) && strtotime( $row['time'] ) >= $cutoff;
			} ) );
		}

		// 2. 按条数上限保留（0 = 不限）
		$max = absint( WAISG_Settings::get( 'error_log_max', 200 ) );
		if ( $max > 0 && count( $logs ) > $max ) {
			$logs = array_slice( $logs, 0, $max );
		}

		return $logs;
	}

	/** 获取全部日志（最新在前） */
	public static function get_logs() {
		$logs = get_option( self::OPTION_KEY, array() );
		return is_array( $logs ) ? $logs : array();
	}

	/** 场景代码转中文名 */
	public static function context_label( $context ) {
		return self::$context_labels[ $context ] ?? $context;
	}

	// =========================================================
	// 后台菜单 + 页面
	// =========================================================

	public function add_menu() {
		add_submenu_page(
			'waisg-settings',
			'AI 错误日志',
			'AI 错误日志',
			'manage_options',
			'waisg-error-logs',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		wp_enqueue_style( 'waisg-admin', WAISG_URL . 'assets/css/admin.css', array(), WAISG_VERSION );

		$logs = self::get_logs();
		include WAISG_DIR . 'admin/views/error-logs.php';
	}

	// =========================================================
	// AJAX
	// =========================================================

	/** 获取日志列表 */
	public function ajax_get_logs() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

		$logs = self::get_logs();
		$rows = array();
		foreach ( $logs as $row ) {
			$post_id = absint( $row['post_id'] ?? 0 );
			$rows[] = array(
				'time'      => $row['time'] ?? '',
				'post_id'   => $post_id,
				'edit_url'  => $post_id ? get_edit_post_link( $post_id, 'raw' ) : '',
				'context'   => self::context_label( $row['context'] ?? '' ),
				'message'   => $row['message'] ?? '',
				'extra'     => $row['extra'] ?? '',
			);
		}
		wp_send_json_success( array( 'logs' => $rows, 'total' => count( $rows ) ) );
	}

	/** 清空日志 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		delete_option( self::OPTION_KEY );
		wp_send_json_success( array( 'message' => '错误日志已清空。' ) );
	}

	/** 导出 CSV（返回文本，前端触发下载） */
	public function ajax_export_logs() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$logs = self::get_logs();
		$lines = array( '时间,文章ID,场景,错误原因,额外信息' );
		foreach ( $logs as $row ) {
			$lines[] = implode( ',', array(
				self::csv_cell( $row['time'] ?? '' ),
				self::csv_cell( (string) ( $row['post_id'] ?? 0 ) ),
				self::csv_cell( self::context_label( $row['context'] ?? '' ) ),
				self::csv_cell( $row['message'] ?? '' ),
				self::csv_cell( $row['extra'] ?? '' ),
			) );
		}
		// 加 BOM 让 Excel 正确识别 UTF-8
		$csv = "\xEF\xBB\xBF" . implode( "\r\n", $lines );
		wp_send_json_success( array( 'csv' => $csv ) );
	}

	/** CSV 单元格转义 */
	private static function csv_cell( $value ) {
		$value = str_replace( '"', '""', (string) $value );
		return '"' . $value . '"';
	}
}
