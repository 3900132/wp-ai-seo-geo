<?php
/**
 * Plugin Name: WordPress AI SEO + GEO 智能优化
 * Description: 对 WordPress 全站内容实现 AI 生成 + AI 优化，同时完成 SEO 与 GEO 两大优化方向。
 * Version:     1.8.0
 * Author:      ivye
 * Author URI:  https://www.3520.net
 * License:     GPL v2 or later
 * Text Domain: wp-ai-seo-geo
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WAISG_VERSION',  '1.8.0' );
define( 'WAISG_FILE',     __FILE__ );
define( 'WAISG_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WAISG_URL',      plugin_dir_url( __FILE__ ) );
define( 'WAISG_BASENAME', plugin_basename( __FILE__ ) );

// 自动加载 includes 下所有 class-*.php
foreach ( glob( WAISG_DIR . 'includes/class-*.php' ) as $file ) {
	require_once $file;
}

/**
 * 插件激活：创建数据库表
 */
function waisg_activate() {
	WAISG_History::create_table();
	flush_rewrite_rules();
}
register_activation_hook( WAISG_FILE, 'waisg_activate' );

/**
 * 插件停用：清除 Cron
 */
function waisg_deactivate() {
	WAISG_Cron::unschedule();
	flush_rewrite_rules();
}
register_deactivation_hook( WAISG_FILE, 'waisg_deactivate' );

/**
 * 初始化所有功能类
 */
function waisg_init() {
	WAISG_History::maybe_migrate(); // 增量迁移（新字段）
	new WAISG_Settings();
	new WAISG_Meta_Box();
	new WAISG_Post_List();
	new WAISG_Batch();
	new WAISG_Generator();
	new WAISG_Cron();
	new WAISG_Schema();
	new WAISG_Logger();
}
add_action( 'plugins_loaded', 'waisg_init' );
