<?php
/**
 * 插件卸载清理
 *
 * 仅在用户从 WordPress 后台"删除插件"时触发。
 * 停用（deactivate）插件不会执行此文件。
 *
 * 清理内容：
 *   - 自定义数据表 waisg_history
 *   - 所有 wp_options 配置项
 *   - 所有文章的 AI 相关 post meta
 *   - 遗留的 WP-Cron 定时任务
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── 1. 删除自定义数据表 ──────────────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}waisg_history`" );

// ── 2. 删除 wp_options 配置项 ────────────────────────────────────────
$options = array(
	'waisg_settings',
	'waisg_token_stats',
	'waisg_content_templates',
	'waisg_cron_log',
	'waisg_error_logs',
	'waisg_db_version',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// ── 3. 删除所有文章的 AI 相关 post meta ──────────────────────────────
$meta_keys = array(
	'_waisg_opt_count',
	'_waisg_ai_generated',
	'_waisg_ai_pending',
);
foreach ( $meta_keys as $key ) {
	delete_post_meta_by_key( $key );
}

// ── 4. 清除遗留的 WP-Cron 定时任务 ──────────────────────────────────
wp_clear_scheduled_hook( 'waisg_auto_optimize' );

// 说明：update_reasoning_model v1.9.3 起改用 wp_options CAS 写入，不再使用 transient 锁，
// 无遗留 transient 需清理。旧版（v1.9.1~v1.9.2）的 waisg_reasoning_lock transient 由
// WordPress 自身按过期时间清理，无需在此手动处理。
