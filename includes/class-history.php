<?php
/**
 * 优化历史记录 & 暂存区
 *
 * entry_type 枚举：
 *   backup    — 优化前的自动备份（可回滚）
 *   generated — AI 生成，尚未写入 WordPress
 *   optimized — AI 优化，尚未应用到文章
 *   saved     — 由 generated 已保存到 WordPress
 *   applied   — 由 optimized 已应用到文章
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_History {

	const TABLE      = 'waisg_history';
	const DB_VERSION = '1.1';

	// ================================================================
	// 建表 / 迁移
	// ================================================================

	/** 全新安装时建表（含所有字段） */
	public static function create_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$table} (
			id           bigint(20)   NOT NULL AUTO_INCREMENT,
			post_id      bigint(20)   NOT NULL DEFAULT 0,
			created_at   datetime     NOT NULL,
			entry_type   varchar(20)  NOT NULL DEFAULT 'backup',
			keyword      varchar(500) DEFAULT '',
			post_type    varchar(50)  DEFAULT 'post',
			category_id  bigint(20)   DEFAULT 0,
			post_title   text,
			post_content longtext,
			post_excerpt text,
			seo_title    varchar(255),
			seo_desc     text,
			seo_kw       varchar(500),
			PRIMARY KEY  (id),
			KEY post_id    (post_id),
			KEY entry_type (entry_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'waisg_db_version', self::DB_VERSION );
	}

	/** 已安装插件的增量迁移（v1.0 → v1.1：加 entry_type / keyword / post_type / category_id） */
	public static function maybe_migrate() {
		if ( version_compare( get_option( 'waisg_db_version', '1.0' ), self::DB_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$cols  = $wpdb->get_col( "DESCRIBE `{$table}`" ); // phpcs:ignore

		if ( ! in_array( 'entry_type',  $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN entry_type VARCHAR(20) NOT NULL DEFAULT 'backup' AFTER created_at" ); // phpcs:ignore
		}
		if ( ! in_array( 'keyword',     $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN keyword VARCHAR(500) DEFAULT '' AFTER entry_type" ); // phpcs:ignore
		}
		if ( ! in_array( 'post_type',   $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN post_type VARCHAR(50) DEFAULT 'post' AFTER keyword" ); // phpcs:ignore
		}
		if ( ! in_array( 'category_id', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN category_id BIGINT(20) DEFAULT 0 AFTER post_type" ); // phpcs:ignore
		}

		update_option( 'waisg_db_version', self::DB_VERSION );
	}

	// ================================================================
	// 写入方法
	// ================================================================

	/**
	 * 保存当前文章版本为备份快照（优化前调用）
	 *
	 * @param int $post_id
	 * @return int|false 插入的 history ID
	 */
	public static function snapshot( $post_id ) {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post ) return false;

		$meta_box = new WAISG_Meta_Box();

		$data = array(
			'post_id'      => $post_id,
			'created_at'   => current_time( 'mysql' ),
			'entry_type'   => 'backup',
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'seo_title'    => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'title' ), true ),
			'seo_desc'     => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'description' ), true ),
			'seo_kw'       => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'keywords' ), true ),
		);

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * 保存 AI 生成 / 优化的暂存内容（尚未写入 WordPress）
	 *
	 * @param array $data {
	 *   entry_type   string  'generated' | 'optimized'
	 *   post_id      int     0 = 新文章，>0 = 待优化的原文章 ID
	 *   keyword      string  关键词（生成模式使用）
	 *   post_type    string  文章类型（生成模式使用）
	 *   category_id  int     分类 ID（生成模式使用）
	 *   post_title   string
	 *   post_content string  HTML
	 *   post_excerpt string
	 *   seo_title    string
	 *   seo_desc     string
	 *   seo_kw       string
	 * }
	 * @return int|false
	 */
	public static function save_staged( $data ) {
		global $wpdb;

		$row = array(
			'post_id'      => absint( $data['post_id']     ?? 0 ),
			'created_at'   => current_time( 'mysql' ),
			'entry_type'   => sanitize_key( $data['entry_type']  ?? 'generated' ),
			'keyword'      => sanitize_text_field( $data['keyword']    ?? '' ),
			'post_type'    => sanitize_key( $data['post_type']   ?? 'post' ),
			'category_id'  => absint( $data['category_id'] ?? 0 ),
			'post_title'   => $data['post_title']   ?? '',
			'post_content' => $data['post_content'] ?? '',
			'post_excerpt' => $data['post_excerpt'] ?? '',
			'seo_title'    => $data['seo_title']    ?? '',
			'seo_desc'     => $data['seo_desc']     ?? '',
			'seo_kw'       => $data['seo_kw']       ?? '',
		);

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			$row,
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	// ================================================================
	// 读取方法
	// ================================================================

	/** 获取待处理暂存列表（generated / optimized），支持关键词搜索和类型筛选 */
	public static function get_pending_list( $paged = 1, $per_page = 20, $search = '', $entry_type = '' ) {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$offset = ( $paged - 1 ) * $per_page;

		$where  = "h.entry_type IN ('generated','optimized')";
		$params = array();

		if ( ! empty( $search ) ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= " AND (h.post_title LIKE %s OR h.keyword LIKE %s)";
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $entry_type ) && in_array( $entry_type, array( 'generated', 'optimized' ), true ) ) {
			$where   .= " AND h.entry_type = %s";
			$params[] = $entry_type;
		}

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT h.*, p.post_title AS current_title
			 FROM {$table} h
			 LEFT JOIN {$wpdb->posts} p ON p.ID = h.post_id AND h.post_id > 0
			 WHERE {$where}
			 ORDER BY h.id DESC LIMIT %d OFFSET %d",
			$params
		) );
	}

	/** 获取待处理暂存总数，支持搜索和类型筛选 */
	public static function get_pending_count( $search = '', $entry_type = '' ) {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$where  = "entry_type IN ('generated','optimized')";
		$params = array();

		if ( ! empty( $search ) ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= " AND (post_title LIKE %s OR keyword LIKE %s)";
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $entry_type ) && in_array( $entry_type, array( 'generated', 'optimized' ), true ) ) {
			$where   .= " AND entry_type = %s";
			$params[] = $entry_type;
		}

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore
	}


	/** 获取单条历史详情 */
	public static function get_one( $history_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d",
			$history_id
		) );
	}

	// ================================================================
	// 回滚
	// ================================================================

	/**
	 * 将文章回滚到指定历史版本（先备份当前版本）
	 *
	 * @param int $history_id
	 * @return bool
	 */
	public static function rollback( $history_id ) {
		$snap = self::get_one( $history_id );
		if ( ! $snap ) return false;

		$meta_box = new WAISG_Meta_Box();
		$post_id  = (int) $snap->post_id;

		self::snapshot( $post_id );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => $snap->post_title,
			'post_content' => $snap->post_content,
			'post_excerpt' => $snap->post_excerpt,
		) );

		update_post_meta( $post_id, $meta_box->get_seo_field_name( 'title' ),       $snap->seo_title );
		update_post_meta( $post_id, $meta_box->get_seo_field_name( 'description' ), $snap->seo_desc );
		update_post_meta( $post_id, $meta_box->get_seo_field_name( 'keywords' ),    $snap->seo_kw );

		return true;
	}

	/** 删除某篇文章所有历史 */
}

// ================================================================
// AJAX 注册
// ================================================================
add_action( 'wp_ajax_waisg_rollback',            'waisg_ajax_rollback' );
add_action( 'wp_ajax_waisg_get_history',         'waisg_ajax_get_history' );
add_action( 'wp_ajax_waisg_delete_history',      'waisg_ajax_delete_history' );
add_action( 'wp_ajax_waisg_get_current_post',    'waisg_ajax_get_current_post' );
add_action( 'wp_ajax_waisg_save_staged_to_wp',   'waisg_ajax_save_staged_to_wp' );
add_action( 'wp_ajax_waisg_batch_delete_history', 'waisg_ajax_batch_delete_history' );

// ----------------------------------------------------------------

function waisg_ajax_rollback() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

	$history_id = absint( $_POST['history_id'] ?? 0 );
	$snap       = WAISG_History::get_one( $history_id );

	if ( ! $snap || ! current_user_can( 'edit_post', $snap->post_id ) ) {
		wp_send_json_error( array( 'message' => '历史记录不存在或权限不足。' ) );
	}

	$ok = WAISG_History::rollback( $history_id );
	if ( $ok ) {
		wp_send_json_success( array( 'message' => '已回滚成功，请刷新页面查看效果。' ) );
	} else {
		wp_send_json_error( array( 'message' => '回滚失败，请重试。' ) );
	}
}

function waisg_ajax_get_history() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

	$history_id = absint( $_POST['history_id'] ?? 0 );
	$snap       = WAISG_History::get_one( $history_id );
	if ( ! $snap ) wp_send_json_error( array( 'message' => '记录不存在。' ) );
	if ( $snap->post_id && ! current_user_can( 'edit_post', (int) $snap->post_id ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ) );
	}

	wp_send_json_success( array(
		'entry_type'   => $snap->entry_type,
		'post_title'   => $snap->post_title,
		'post_content' => $snap->post_content,
		'post_excerpt' => $snap->post_excerpt,
		'seo_title'    => $snap->seo_title,
		'seo_desc'     => $snap->seo_desc,
		'seo_kw'       => $snap->seo_kw,
		'created_at'   => $snap->created_at,
	) );
}

function waisg_ajax_delete_history() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

	$history_id = absint( $_POST['history_id'] ?? 0 );
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . WAISG_History::TABLE, array( 'id' => $history_id ), array( '%d' ) );
	wp_send_json_success( array( 'message' => '已删除。' ) );
}

function waisg_ajax_get_current_post() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$post    = get_post( $post_id );
	if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => '文章不存在或权限不足。' ) );
	}

	$meta_box = new WAISG_Meta_Box();
	wp_send_json_success( array(
		'post_title'   => $post->post_title,
		'post_content' => wp_strip_all_tags( $post->post_content ),
		'post_excerpt' => $post->post_excerpt,
		'seo_title'    => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'title' ), true ),
		'seo_desc'     => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'description' ), true ),
		'seo_kw'       => get_post_meta( $post_id, $meta_box->get_seo_field_name( 'keywords' ), true ),
		'created_at'   => '当前版本',
	) );
}

/**
 * 将暂存内容（generated / optimized）保存到 WordPress
 * 前端传来用户可能编辑过的字段；history_id 用于确定 entry_type 和原文章 ID
 */
function waisg_ajax_save_staged_to_wp() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

	$history_id = absint( $_POST['history_id'] ?? 0 );
	$snap       = WAISG_History::get_one( $history_id );
	if ( ! $snap ) {
		wp_send_json_error( array( 'message' => '暂存记录不存在，可能已保存过。' ) );
	}
	if ( ! in_array( $snap->entry_type, array( 'generated', 'optimized' ), true ) ) {
		wp_send_json_error( array( 'message' => '此记录已保存，无需重复操作。' ) );
	}

	// 使用前端传入（用户可能修改过）的内容，后备用 DB 原值
	$post_title   = sanitize_text_field( wp_unslash( $_POST['post_title']   ?? $snap->post_title ) );
	$post_content = WAISG_AI_API::sanitize_content( wp_unslash( $_POST['post_content']        ?? $snap->post_content ) );
	$post_excerpt = sanitize_textarea_field( wp_unslash( $_POST['post_excerpt'] ?? $snap->post_excerpt ) );
	$seo_title    = sanitize_text_field( wp_unslash( $_POST['seo_title']    ?? $snap->seo_title ) );
	$seo_desc     = sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ?? $snap->seo_desc ) );
	$seo_kw       = sanitize_text_field( wp_unslash( $_POST['seo_kw']       ?? $snap->seo_kw ) );
	$target       = sanitize_key( $_POST['target_status'] ?? 'draft' );
	$post_date    = sanitize_text_field( wp_unslash( $_POST['post_date']    ?? '' ) );

	if ( ! in_array( $target, array( 'draft', 'publish', 'future' ), true ) ) {
		$target = 'draft';
	}

	$meta_box = new WAISG_Meta_Box();
	global $wpdb;

	// ── 新建文章（AI 生成） ──────────────────────────────────────────
	if ( $snap->entry_type === 'generated' ) {
		$post_arr = array(
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'post_status'  => $target,
			'post_type'    => $snap->post_type ?: 'post',
		);
		if ( $target === 'future' && ! empty( $post_date ) ) {
			$post_arr['post_date']     = $post_date;
			$post_arr['post_date_gmt'] = get_gmt_from_date( $post_date );
		}
		if ( $snap->post_type === 'post' && $snap->category_id > 0 ) {
			$post_arr['post_category'] = array( (int) $snap->category_id );
		}

		$post_id = wp_insert_post( $post_arr, true );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => '创建文章失败：' . $post_id->get_error_message() ) );
		}

		if ( $seo_title ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'title' ),       $seo_title );
		if ( $seo_desc  ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'description' ), $seo_desc );
		if ( $seo_kw    ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'keywords' ),    $seo_kw );
		update_post_meta( $post_id, '_waisg_ai_generated', 1 );
		update_post_meta( $post_id, '_waisg_opt_count',    1 );

		// 更新暂存记录：绑定 post_id，标记为已保存
		$wpdb->update(
			$wpdb->prefix . WAISG_History::TABLE,
			array( 'post_id' => $post_id, 'entry_type' => 'saved' ),
			array( 'id' => $history_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'post_title' => $post_title,
			'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
			'view_url'   => get_permalink( $post_id ),
			'status'     => $target,
		) );
	}

	// ── 更新已有文章（AI 优化） ──────────────────────────────────────
	if ( $snap->entry_type === 'optimized' ) {
		$post_id = (int) $snap->post_id;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '文章不存在或权限不足。' ) );
		}

		// 先备份当前版本
		WAISG_History::snapshot( $post_id );

		$update_arr = array(
			'ID'           => $post_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
		);
		if ( in_array( $target, array( 'draft', 'publish', 'future' ), true ) ) {
			$update_arr['post_status'] = $target;
		}
		if ( $target === 'future' && ! empty( $post_date ) ) {
			$update_arr['post_date']     = $post_date;
			$update_arr['post_date_gmt'] = get_gmt_from_date( $post_date );
		}
		wp_update_post( $update_arr );

		if ( $seo_title ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'title' ),       $seo_title );
		if ( $seo_desc  ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'description' ), $seo_desc );
		if ( $seo_kw    ) update_post_meta( $post_id, $meta_box->get_seo_field_name( 'keywords' ),    $seo_kw );

		$count = (int) get_post_meta( $post_id, '_waisg_opt_count', true );
		update_post_meta( $post_id, '_waisg_opt_count', $count + 1 );

		// 标记暂存记录为已应用
		$wpdb->update(
			$wpdb->prefix . WAISG_History::TABLE,
			array( 'entry_type' => 'applied' ),
			array( 'id' => $history_id ),
			array( '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array(
			'post_id'    => $post_id,
			'post_title' => $post_title,
			'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
			'status'     => $target,
		) );
	}
}

function waisg_ajax_batch_delete_history() {
	check_ajax_referer( 'waisg_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => '权限不足。' ) );
	}

	$raw_ids = sanitize_text_field( $_POST['ids'] ?? '' );
	$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => '没有选中任何记录。' ) );
	}

	global $wpdb;
	$table        = $wpdb->prefix . WAISG_History::TABLE;
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore

	wp_send_json_success( array( 'deleted' => count( $ids ) ) );
}
