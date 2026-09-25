<?php
/**
 * Plugin Name: WordPress AI SEO + GEO 智能优化
 * Description: 对 WordPress 全站内容实现 AI 生成 + AI 优化，同时完成 SEO 与 GEO 两大优化方向。
 * Version:     2.0.15
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

define( 'WAISG_VERSION',  '2.0.15' );
define( 'WAISG_FILE',     __FILE__ );
define( 'WAISG_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WAISG_URL',      plugin_dir_url( __FILE__ ) );
define( 'WAISG_BASENAME', plugin_basename( __FILE__ ) );

// 显式按需加载 includes 下所有类文件（顺序可控，避免 glob 在不同文件系统下顺序不定）
// 加载顺序说明：History 前置因 waisg_activate/waisg_init 里 maybe_migrate 先调；
// Settings 次之（其余类构造里常调 WAISG_Settings::get）；其余按字母序，互无依赖
require_once WAISG_DIR . 'includes/class-history.php';
require_once WAISG_DIR . 'includes/class-logger.php';
require_once WAISG_DIR . 'includes/class-settings.php';
require_once WAISG_DIR . 'includes/class-ai-api.php';
require_once WAISG_DIR . 'includes/class-meta-box.php';
require_once WAISG_DIR . 'includes/class-post-list.php';
require_once WAISG_DIR . 'includes/class-batch.php';
require_once WAISG_DIR . 'includes/class-generator.php';
require_once WAISG_DIR . 'includes/class-cron.php';
require_once WAISG_DIR . 'includes/class-schema.php';

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
