<?php
/**
 * 优化历史页面视图
 * Tab 1：待处理（AI 生成/优化尚未保存到 WordPress 的暂存内容）
 * Tab 2：备份历史（优化前的快照，可回滚）
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Tab 状态 ──────────────────────────────────────────────────
$active_tab = sanitize_key( $_GET['tab'] ?? 'pending' );
if ( ! in_array( $active_tab, array( 'pending', 'backup' ), true ) ) {
	$active_tab = 'pending';
}

// ── 分页参数 ──────────────────────────────────────────────────
$per_page   = max( 10, min( 200, absint( $_GET['per_page'] ?? 20 ) ) );
$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
$post_id    = absint( $_GET['post_id'] ?? 0 );
$pending_search = sanitize_text_field( wp_unslash( $_GET['pending_search'] ?? '' ) );
$pending_type   = sanitize_key( $_GET['pending_type'] ?? '' );

global $wpdb;
$table    = $wpdb->prefix . 'waisg_history';
$base_url = admin_url( 'admin.php?page=waisg-history' );

// ── 待处理数量（用于 Tab 徽章，不带搜索条件） ────────────────
$pending_count = WAISG_History::get_pending_count();

// ── 备份历史查询 ──────────────────────────────────────────────
if ( $active_tab === 'backup' ) {
	$offset = ( $paged - 1 ) * $per_page;

	if ( $post_id ) {
		$the_post  = get_post( $post_id );
		$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND entry_type = 'backup'", $post_id ) );
		$histories = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, post_id, created_at, post_title FROM {$table}
			 WHERE post_id = %d AND entry_type = 'backup' ORDER BY id DESC LIMIT %d OFFSET %d",
			$post_id, $per_page, $offset
		) );
	} else {
		$the_post  = null;
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE entry_type = 'backup'" ); // phpcs:ignore
		$histories = $wpdb->get_results( $wpdb->prepare(
			"SELECT h.id, h.post_id, h.created_at, h.post_title, p.post_title AS current_title
			 FROM {$table} h
			 LEFT JOIN {$wpdb->posts} p ON p.ID = h.post_id
			 WHERE h.entry_type = 'backup'
			 ORDER BY h.id DESC LIMIT %d OFFSET %d",
			$per_page, $offset
		) );
	}

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$backup_base = $base_url . '&tab=backup' . ( $post_id ? '&post_id=' . $post_id : '' ) . ( $per_page !== 20 ? '&per_page=' . $per_page : '' );
}

// ── 待处理查询 ────────────────────────────────────────────────
if ( $active_tab === 'pending' ) {
	$pending_list  = WAISG_History::get_pending_list( $paged, $per_page, $pending_search, $pending_type );
	$pending_total = WAISG_History::get_pending_count( $pending_search, $pending_type );
	$pending_pages = max( 1, (int) ceil( $pending_total / $per_page ) );
	$pending_base  = $base_url . '&tab=pending'
		. ( $pending_search ? '&pending_search=' . rawurlencode( $pending_search ) : '' )
		. ( $pending_type   ? '&pending_type='   . rawurlencode( $pending_type )   : '' )
		. ( $per_page !== 20 ? '&per_page=' . $per_page : '' );
}
?>
<div class="wrap">
	<h1>📋 AI 优化历史记录</h1>

	<!-- Tab 导航 -->
	<div style="margin-bottom:0;border-bottom:1px solid #c3c4c7;">
		<a href="<?php echo esc_url( $base_url . '&tab=pending' ); ?>"
			class="gen-tab-link"
			style="display:inline-block;padding:8px 20px;border:1px solid <?php echo $active_tab === 'pending' ? '#c3c4c7; border-bottom:1px solid #fff;background:#fff' : 'transparent;background:transparent;color:#646970'; ?>;border-radius:4px 4px 0 0;font-size:14px;text-decoration:none;margin-bottom:-1px;position:relative;">
			📥 待处理
			<?php if ( $pending_count > 0 ) : ?>
				<span style="background:#d63638;color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;margin-left:4px;"><?php echo $pending_count; ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $base_url . '&tab=backup' ); ?>"
			style="display:inline-block;padding:8px 20px;border:1px solid <?php echo $active_tab === 'backup' ? '#c3c4c7; border-bottom:1px solid #fff;background:#fff' : 'transparent;background:transparent;color:#646970'; ?>;border-radius:4px 4px 0 0;font-size:14px;text-decoration:none;margin-left:4px;margin-bottom:-1px;position:relative;">
			📋 备份历史
		</a>
	</div>

	<!-- ============================================================
	     Tab 1：待处理（AI 生成 / AI 优化暂存）
	     ============================================================ -->
	<?php if ( $active_tab === 'pending' ) : ?>
	<div class="waisg-card" style="border-top:none;border-radius:0 4px 4px 4px;margin-top:0;">

		<?php if ( empty( $pending_list ) && empty( $pending_search ) && empty( $pending_type ) ) : ?>
			<div class="notice notice-info inline"><p>暂无待处理内容。AI 生成或优化文章后，若未立即保存到 WordPress，会在这里显示。</p></div>
		<?php else : ?>
			<!-- 搜索/筛选栏（服务端过滤，支持跨页搜索） -->
			<form method="get" action="<?php echo esc_url( $base_url . '&tab=pending' ); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
				<input type="hidden" name="page" value="waisg-history" />
				<input type="hidden" name="tab"  value="pending" />
				<input type="text" name="pending_search" value="<?php echo esc_attr( $pending_search ); ?>"
					placeholder="搜索标题 / 关键词..." class="regular-text" style="max-width:260px;" />
				<select name="pending_type">
					<option value="">全部类型</option>
					<option value="generated" <?php selected( $pending_type, 'generated' ); ?>>🆕 AI生成</option>
					<option value="optimized" <?php selected( $pending_type, 'optimized' ); ?>>✏️ AI优化</option>
				</select>
				<button type="submit" class="button">搜索</button>
				<?php if ( $pending_search || $pending_type ) : ?>
					<a href="<?php echo esc_url( $base_url . '&tab=pending' . ( $per_page !== 20 ? '&per_page=' . $per_page : '' ) ); ?>" class="button">清除筛选</a>
				<?php endif; ?>
				<span style="color:#646970;font-size:13px;">
					共 <strong><?php echo $pending_total; ?></strong> 条
					<?php if ( $pending_search || $pending_type ) echo '（已筛选）'; ?>
				</span>
				<span style="margin-left:auto;display:flex;align-items:center;gap:4px;font-size:13px;color:#646970;">
					每页
					<input type="number" name="per_page" value="<?php echo $per_page; ?>" min="10" max="200" step="10"
						class="waisg-per-page-input" style="width:60px;height:28px;text-align:center;" />
					条
					<button type="submit" class="button button-small">应用</button>
				</span>
			</form>

			<?php if ( empty( $pending_list ) ) : ?>
				<p style="color:#646970;margin-top:0;">没有找到符合条件的内容，请调整搜索关键词后重试。</p>
			<?php else : ?>
			<p style="color:#646970;font-size:13px;margin-top:0;">
				第 <?php echo $paged; ?> / <?php echo $pending_pages; ?> 页（每页 <?php echo $per_page; ?> 条）
			</p>
			<div style="margin-bottom:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
				<span style="font-size:13px;color:#646970;">已选 <strong id="waisg-batch-count-pending">0</strong> 条</span>
				<select id="waisg-pending-batch-status" style="height:30px;">
					<option value="draft">存为草稿</option>
					<option value="publish">立即发布</option>
					<option value="future">定时发布</option>
				</select>
				<input type="datetime-local" id="waisg-pending-batch-date" style="display:none;height:30px;" />
				<button type="button" id="waisg-pending-batch-apply" class="button button-primary" disabled>📥 批量应用到 WordPress</button>
				<button type="button" id="waisg-batch-delete-pending" class="button button-link-delete waisg-batch-delete-btn" data-tab="pending" disabled>批量删除选中</button>
				<span id="waisg-pending-batch-msg" style="font-size:12px;display:none;"></span>
			</div>
			<div id="waisg-pending-batch-progress" style="display:none;margin-bottom:8px;padding:8px 12px;background:#f0f6fc;border-left:3px solid #0073aa;border-radius:3px;font-size:13px;">
				<span id="waisg-pending-progress-text">准备中...</span>
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th width="36"><input type="checkbox" class="waisg-check-all" data-tab="pending" /></th>
						<th width="60">ID</th>
						<th width="90">类型</th>
						<th>关键词 / 原文章</th>
						<th>AI 生成标题</th>
						<th width="160">创建时间</th>
						<th width="180">操作</th>
					</tr>
				</thead>
				<tbody id="waisg-pending-tbody">
					<?php foreach ( $pending_list as $item ) :
						$ref = $item->entry_type === 'generated' ? ( $item->keyword ?: '' ) : ( $item->current_title ?: '' );
					?>
					<tr id="waisg-pending-row-<?php echo $item->id; ?>"
						data-id="<?php echo $item->id; ?>"
						data-type="<?php echo esc_attr( $item->entry_type ); ?>"
						data-title="<?php echo esc_attr( mb_strtolower( $item->post_title ) ); ?>"
						data-ref="<?php echo esc_attr( mb_strtolower( $ref ) ); ?>">
						<td><input type="checkbox" class="waisg-check-item" data-tab="pending" value="<?php echo $item->id; ?>" /></td>
						<td><?php echo $item->id; ?></td>
						<td>
							<?php if ( $item->entry_type === 'generated' ) : ?>
								<span style="background:#0073aa;color:#fff;border-radius:3px;padding:2px 6px;font-size:11px;">🆕 AI生成</span>
							<?php else : ?>
								<span style="background:#00a32a;color:#fff;border-radius:3px;padding:2px 6px;font-size:11px;">✏️ AI优化</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $item->entry_type === 'generated' ) : ?>
								<?php echo esc_html( $item->keyword ?: '—' ); ?>
							<?php else : ?>
								<?php if ( $item->post_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-history&tab=backup&post_id=' . $item->post_id ) ); ?>">
										<?php echo esc_html( $item->current_title ?: '（文章 ID: ' . $item->post_id . '）' ); ?>
									</a>
								<?php else : ?>
									—
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $item->post_title ); ?></td>
						<td><?php echo esc_html( $item->created_at ); ?></td>
						<td>
							<button type="button" class="button waisg-pending-edit"
								data-id="<?php echo $item->id; ?>"
								data-type="<?php echo esc_attr( $item->entry_type ); ?>">编辑/保存到WP</button>
							<button type="button" class="button button-link-delete waisg-delete-history"
								data-id="<?php echo $item->id; ?>">删除</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pending_pages > 1 ) : ?>
			<div style="margin-top:12px;display:flex;align-items:center;gap:6px;">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $pending_base . '&paged=1' ); ?>">«</a>
					<a class="button" href="<?php echo esc_url( $pending_base . '&paged=' . ( $paged - 1 ) ); ?>">‹ 上一页</a>
				<?php else : ?>
					<button class="button" disabled>«</button><button class="button" disabled>‹ 上一页</button>
				<?php endif; ?>
				<span style="padding:0 10px;font-size:13px;color:#646970;">第 <?php echo $paged; ?> / <?php echo $pending_pages; ?> 页</span>
				<?php if ( $paged < $pending_pages ) : ?>
					<a class="button" href="<?php echo esc_url( $pending_base . '&paged=' . ( $paged + 1 ) ); ?>">下一页 ›</a>
					<a class="button" href="<?php echo esc_url( $pending_base . '&paged=' . $pending_pages ); ?>">»</a>
				<?php else : ?>
					<button class="button" disabled>下一页 ›</button><button class="button" disabled>»</button>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php endif; // end if empty list from search ?>

		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ============================================================
	     Tab 2：备份历史
	     ============================================================ -->
	<?php if ( $active_tab === 'backup' ) : ?>
	<div class="waisg-card" style="border-top:none;border-radius:0 4px 4px 4px;margin-top:0;">

		<?php if ( $post_id && isset( $the_post ) && $the_post ) : ?>
		<p>
			<a href="<?php echo esc_url( $base_url . '&tab=backup' ); ?>">← 返回全部备份</a>
			&nbsp;|&nbsp; 文章：<strong><?php echo esc_html( $the_post->post_title ); ?></strong>
			&nbsp;<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank">编辑文章</a>
		</p>
		<?php endif; ?>

		<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:0;margin-bottom:10px;">
			<span style="color:#646970;font-size:13px;">
				共 <strong><?php echo $total; ?></strong> 条，第 <?php echo $paged; ?> / <?php echo $total_pages; ?> 页
			</span>
			<span style="margin-left:auto;display:flex;align-items:center;gap:4px;font-size:13px;color:#646970;">
				每页
				<input type="number" value="<?php echo $per_page; ?>" min="10" max="200" step="10"
					class="waisg-per-page-input" data-tab="backup" style="width:60px;height:28px;text-align:center;" />
				条
				<button type="button" class="button button-small waisg-per-page-btn" data-tab="backup">应用</button>
			</span>
		</div>

		<?php if ( empty( $histories ) ) : ?>
			<div class="notice notice-info inline"><p>暂无备份记录。</p></div>
		<?php else : ?>
			<div style="margin-bottom:6px;display:flex;align-items:center;gap:10px;">
				<span style="font-size:13px;color:#646970;">已选 <strong id="waisg-batch-count-backup">0</strong> 条</span>
				<button type="button" id="waisg-batch-delete-backup" class="button button-link-delete waisg-batch-delete-btn" data-tab="backup" disabled>批量删除选中</button>
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th width="36"><input type="checkbox" class="waisg-check-all" data-tab="backup" /></th>
						<th width="60">ID</th>
						<?php if ( ! $post_id ) : ?><th>所属文章</th><?php endif; ?>
						<th>备份时的标题</th>
						<th width="160">备份时间</th>
						<th width="240">操作</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $histories as $h ) : ?>
					<tr id="waisg-history-row-<?php echo $h->id; ?>">
						<td><input type="checkbox" class="waisg-check-item" data-tab="backup" value="<?php echo $h->id; ?>" /></td>
						<td><?php echo $h->id; ?></td>
						<?php if ( ! $post_id ) : ?>
						<td>
							<a href="<?php echo esc_url( $base_url . '&tab=backup&post_id=' . $h->post_id ); ?>">
								<?php echo esc_html( $h->current_title ?: '（已删除）' ); ?>
							</a>
						</td>
						<?php endif; ?>
						<td><?php echo esc_html( $h->post_title ); ?></td>
						<td><?php echo esc_html( $h->created_at ); ?></td>
						<td>
							<button type="button" class="button waisg-view-history"
								data-id="<?php echo $h->id; ?>"
								data-post-id="<?php echo $h->post_id; ?>">查看</button>
							<button type="button" class="button waisg-compare-history"
								data-id="<?php echo $h->id; ?>"
								data-post-id="<?php echo $h->post_id; ?>">对比当前</button>
							<button type="button" class="button button-primary waisg-rollback"
								data-id="<?php echo $h->id; ?>">回滚</button>
							<button type="button" class="button button-link-delete waisg-delete-history"
								data-id="<?php echo $h->id; ?>">删除</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
			<div style="margin-top:12px;display:flex;align-items:center;gap:6px;">
				<?php if ( $paged > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $backup_base . '&paged=1' ); ?>">«</a>
					<a class="button" href="<?php echo esc_url( $backup_base . '&paged=' . ( $paged - 1 ) ); ?>">‹ 上一页</a>
				<?php else : ?>
					<button class="button" disabled>«</button><button class="button" disabled>‹ 上一页</button>
				<?php endif; ?>
				<span style="padding:0 10px;font-size:13px;color:#646970;">第 <?php echo $paged; ?> / <?php echo $total_pages; ?> 页</span>
				<?php if ( $paged < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( $backup_base . '&paged=' . ( $paged + 1 ) ); ?>">下一页 ›</a>
					<a class="button" href="<?php echo esc_url( $backup_base . '&paged=' . $total_pages ); ?>">»</a>
				<?php else : ?>
					<button class="button" disabled>下一页 ›</button><button class="button" disabled>»</button>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		<?php endif; ?>

	</div>
	<?php endif; ?>
</div>

<!-- ============================================================
     弹窗：查看单个历史版本（备份）
     ============================================================ -->
<div id="waisg-history-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:9999;overflow:auto;">
	<div style="background:#fff;margin:30px auto;max-width:860px;padding:30px;border-radius:6px;position:relative;">
		<button type="button" id="waisg-modal-close" class="waisg-modal-close-btn">✕ 关闭</button>
		<h2 id="waisg-modal-title">历史版本详情</h2>
		<div id="waisg-modal-content"></div>
	</div>
</div>

<!-- ============================================================
     弹窗：对比历史 vs 当前
     ============================================================ -->
<div id="waisg-compare-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:9999;overflow:auto;">
	<div style="background:#fff;margin:20px auto;max-width:1200px;padding:30px;border-radius:6px;position:relative;">
		<button type="button" id="waisg-compare-close" class="waisg-modal-close-btn">✕ 关闭</button>
		<h2>历史版本 vs 当前版本 对比</h2>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" id="waisg-compare-content">
			<div><h3 style="background:#fff3cd;padding:8px;border-radius:3px;">📁 历史备份版本</h3><div id="waisg-compare-old"></div></div>
			<div><h3 style="background:#d4edda;padding:8px;border-radius:3px;">✅ 当前版本</h3><div id="waisg-compare-new"></div></div>
		</div>
	</div>
</div>

<!-- ============================================================
     弹窗：编辑待处理内容并保存到 WordPress
     ============================================================ -->
<div id="waisg-pending-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:9999;overflow:auto;">
	<div style="background:#fff;margin:20px auto;max-width:900px;padding:30px;border-radius:6px;position:relative;">
		<button type="button" id="waisg-pending-close" class="waisg-modal-close-btn">✕ 关闭</button>
		<h2 id="waisg-pending-modal-title">编辑并保存到 WordPress</h2>
		<input type="hidden" id="waisg-pending-history-id" />
		<table class="form-table" style="margin-top:0;">
			<tr>
				<th style="width:110px;">标题</th>
				<td><input type="text" id="waisg-pending-title" class="large-text" /></td>
			</tr>
			<tr>
				<th>摘要</th>
				<td><textarea id="waisg-pending-excerpt" class="large-text" rows="3"></textarea></td>
			</tr>
			<tr>
				<th>正文（HTML）</th>
				<td><textarea id="waisg-pending-content" class="large-text" rows="14"></textarea></td>
			</tr>
			<tr>
				<th>SEO 标题</th>
				<td><input type="text" id="waisg-pending-seo-title" class="large-text" /></td>
			</tr>
			<tr>
				<th>SEO 描述</th>
				<td><textarea id="waisg-pending-seo-desc" class="large-text" rows="2"></textarea></td>
			</tr>
			<tr>
				<th>SEO 关键词</th>
				<td><input type="text" id="waisg-pending-seo-kw" class="large-text" /></td>
			</tr>
			<tr>
				<th>保存为</th>
				<td>
					<select id="waisg-pending-status">
						<option value="draft">草稿</option>
						<option value="publish">立即发布</option>
						<option value="future">定时发布</option>
					</select>
					<input type="datetime-local" id="waisg-pending-date" style="display:none;margin:0 8px;" />
					<button type="button" id="waisg-pending-save" class="button button-primary" style="margin-left:6px;">保存到 WordPress</button>
					<span id="waisg-pending-msg" style="font-size:13px;margin-left:8px;display:none;"></span>
				</td>
			</tr>
		</table>
	</div>
</div>

<style>
.waisg-modal-close-btn {
	position:absolute;top:12px;right:15px;background:#f0f0f1;border:1px solid #ccc;
	border-radius:3px;cursor:pointer;padding:4px 10px;
}
.waisg-compare-field { margin-bottom:14px; }
.waisg-compare-field label { display:block;font-weight:600;font-size:12px;color:#646970;margin-bottom:4px; }
.waisg-compare-field .val {
	background:#f9f9f9;border:1px solid #e0e0e0;padding:8px;border-radius:3px;
	font-size:13px;min-height:40px;word-break:break-all;
}
.waisg-diff-changed .val { background:#fff8dc;border-color:#e6c200; }
</style>

<script>
(function($){
	var nonce = '<?php echo esc_js( wp_create_nonce( 'waisg_nonce' ) ); ?>';

	// ── 查看备份历史 ──────────────────────────────────────────
	$(document).on('click', '.waisg-view-history', function(){
		var id = $(this).data('id');
		$.post(ajaxurl, { action:'waisg_get_history', nonce:nonce, history_id:id }, function(res){
			if (!res.success) { alert(res.data.message); return; }
			var d = res.data;
			var html = '<table class="widefat"><tbody>';
			html += row('备份时间', esc(d.created_at));
			html += row('标题', esc(d.post_title));
			html += row('摘要', esc(d.post_excerpt));
			html += row('SEO 标题', esc(d.seo_title));
			html += row('SEO 描述', esc(d.seo_desc));
			html += row('SEO 关键词', esc(d.seo_kw));
			html += row('正文预览', '<div style="max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px;">' + esc(d.post_content) + '</div>');
			html += '</tbody></table>';
			$('#waisg-modal-title').text('历史版本详情（备份于 ' + d.created_at + '）');
			$('#waisg-modal-content').html(html);
			$('#waisg-history-modal').show();
		});
	});

	// ── 对比当前版本 ──────────────────────────────────────────
	$(document).on('click', '.waisg-compare-history', function(){
		var id = $(this).data('id'), postId = $(this).data('post-id');
		$.when(
			$.post(ajaxurl, { action:'waisg_get_history', nonce:nonce, history_id:id }),
			$.post(ajaxurl, { action:'waisg_get_current_post', nonce:nonce, post_id:postId })
		).done(function(r1, r2){
			if (!r1[0].success || !r2[0].success) { alert('加载失败，请重试。'); return; }
			var old = r1[0].data, cur = r2[0].data;
			$('#waisg-compare-old').html(buildComparePanel(old));
			$('#waisg-compare-new').html(buildComparePanel(cur));
			['post_title','post_excerpt','seo_title','seo_desc','seo_kw'].forEach(function(f){
				if (old[f] !== cur[f]) {
					$('#waisg-compare-old .cf-'+f+', #waisg-compare-new .cf-'+f).addClass('waisg-diff-changed');
				}
			});
			$('#waisg-compare-modal').show();
		});
	});

	function buildComparePanel(d) {
		return compareField('post_title',   '标题',       d.post_title)
		     + compareField('post_excerpt', '摘要',       d.post_excerpt)
		     + compareField('seo_title',    'SEO 标题',   d.seo_title)
		     + compareField('seo_desc',     'SEO 描述',   d.seo_desc)
		     + compareField('seo_kw',       'SEO 关键词', d.seo_kw)
		     + compareField('post_content', '正文预览',
		         '<div style="max-height:200px;overflow:auto;white-space:pre-wrap;font-size:12px;">' + esc(d.post_content || '') + '</div>',
		         true);
	}
	function compareField(cls, label, val, raw) {
		return '<div class="waisg-compare-field cf-' + cls + '">'
		     + '<label>' + label + '</label>'
		     + '<div class="val">' + (raw ? val : esc(val || '（空）')) + '</div>'
		     + '</div>';
	}

	// ── 关闭弹窗 ────────────────────────────────────────────
	$('#waisg-modal-close, #waisg-history-modal').on('click', function(e){
		if (e.target === this || $(e.target).is('#waisg-modal-close')) $('#waisg-history-modal').hide();
	});
	$('#waisg-compare-close, #waisg-compare-modal').on('click', function(e){
		if (e.target === this || $(e.target).is('#waisg-compare-close')) $('#waisg-compare-modal').hide();
	});
	$('#waisg-pending-close, #waisg-pending-modal').on('click', function(e){
		if (e.target === this || $(e.target).is('#waisg-pending-close')) $('#waisg-pending-modal').hide();
	});

	// ── 回滚 ────────────────────────────────────────────────
	$(document).on('click', '.waisg-rollback', function(){
		if (!confirm('确定要回滚到此版本吗？\n当前内容将先被备份，然后还原为历史版本。')) return;
		var id = $(this).data('id');
		$.post(ajaxurl, { action:'waisg_rollback', nonce:nonce, history_id:id }, function(res){
			if (res.success) { alert(res.data.message); location.reload(); }
			else alert(res.data.message);
		});
	});

	// ── 删除历史（备份 + 待处理通用） ────────────────────────
	$(document).on('click', '.waisg-delete-history', function(){
		if (!confirm('确定删除此条记录？此操作不可撤销。')) return;
		var id  = $(this).data('id');
		var $row = $('#waisg-history-row-' + id + ', #waisg-pending-row-' + id);
		$.post(ajaxurl, { action:'waisg_delete_history', nonce:nonce, history_id:id }, function(res){
			if (res.success) $row.fadeOut(300, function(){ $row.remove(); });
			else alert(res.data.message);
		});
	});

	// ── 编辑待处理内容 ───────────────────────────────────────
	$(document).on('click', '.waisg-pending-edit', function(){
		var id   = $(this).data('id');
		var type = $(this).data('type');
		$.post(ajaxurl, { action:'waisg_get_history', nonce:nonce, history_id:id }, function(res){
			if (!res.success) { alert('加载失败：' + res.data.message); return; }
			var d = res.data;
			$('#waisg-pending-history-id').val(id);
			$('#waisg-pending-modal-title').text(
				(type === 'generated' ? '🆕 AI 生成内容' : '✏️ AI 优化内容') + '——编辑并保存到 WordPress'
			);
			$('#waisg-pending-title').val(d.post_title || '');
			$('#waisg-pending-excerpt').val(d.post_excerpt || '');
			$('#waisg-pending-content').val(d.post_content || '');
			$('#waisg-pending-seo-title').val(d.seo_title || '');
			$('#waisg-pending-seo-desc').val(d.seo_desc || '');
			$('#waisg-pending-seo-kw').val(d.seo_kw || '');
			$('#waisg-pending-status').val('draft');
			$('#waisg-pending-date').hide();
			$('#waisg-pending-msg').hide();
			$('#waisg-pending-save').prop('disabled', false).text('保存到 WordPress');
			$('#waisg-pending-modal').show();
		});
	});

	$('#waisg-pending-status').on('change', function(){
		$('#waisg-pending-date').toggle($(this).val() === 'future');
	});

	$('#waisg-pending-save').on('click', function(){
		var historyId = $('#waisg-pending-history-id').val();
		var target    = $('#waisg-pending-status').val();
		var postDate  = $('#waisg-pending-date').val();
		if (target === 'future' && !postDate) { alert('请选择定时发布时间。'); return; }

		$(this).prop('disabled', true).text('保存中...');
		$('#waisg-pending-msg').hide();

		$.post(ajaxurl, {
			action:        'waisg_save_staged_to_wp',
			nonce:         nonce,
			history_id:    historyId,
			post_title:    $('#waisg-pending-title').val(),
			post_content:  $('#waisg-pending-content').val(),
			post_excerpt:  $('#waisg-pending-excerpt').val(),
			seo_title:     $('#waisg-pending-seo-title').val(),
			seo_desc:      $('#waisg-pending-seo-desc').val(),
			seo_kw:        $('#waisg-pending-seo-kw').val(),
			target_status: target,
			post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
		}, function(res){
			$('#waisg-pending-save').prop('disabled', false).text('保存到 WordPress');
			if (res.success) {
				var d = res.data;
				var labels = { draft:'草稿', publish:'已发布', future:'已定时' };
				$('#waisg-pending-msg').html(
					'✅ ' + (labels[d.status] || d.status)
					+ ' &nbsp;<a href="' + d.edit_url + '" target="_blank">编辑文章</a>'
					+ (d.view_url ? ' &nbsp;<a href="' + d.view_url + '" target="_blank">查看</a>' : '')
				).css('color','#0a6').show();
				// 从列表中移除该行
				$('#waisg-pending-row-' + historyId).fadeOut(400, function(){ $(this).remove(); });
			} else {
				$('#waisg-pending-msg').text('❌ ' + (res.data && res.data.message ? res.data.message : '保存失败')).css('color','#c00').show();
			}
		}).fail(function(){
			$('#waisg-pending-save').prop('disabled', false).text('保存到 WordPress');
			$('#waisg-pending-msg').text('❌ 请求失败').css('color','#c00').show();
		});
	});

	// ── 工具 ────────────────────────────────────────────────
	function row(label, val) {
		return '<tr><th style="width:120px;">' + label + '</th><td>' + val + '</td></tr>';
	}
	function esc(s) {
		return $('<div>').text(String(s || '')).html();
	}

	// ── 全选/反选 ─────────────────────────────────────────────
	$(document).on('change', '.waisg-check-all', function(){
		var tab = $(this).data('tab');
		$('.waisg-check-item[data-tab="' + tab + '"]').prop('checked', this.checked);
		updateBatchCount(tab);
	});
	$(document).on('change', '.waisg-check-item', function(){
		var tab = $(this).data('tab');
		var allCount  = $('.waisg-check-item[data-tab="' + tab + '"]').length;
		var checked   = $('.waisg-check-item[data-tab="' + tab + '"]:checked').length;
		$('.waisg-check-all[data-tab="' + tab + '"]').prop('checked', allCount > 0 && allCount === checked);
		updateBatchCount(tab);
	});
	function updateBatchCount(tab) {
		var count = $('.waisg-check-item[data-tab="' + tab + '"]:checked').length;
		$('#waisg-batch-count-' + tab).text(count);
		$('#waisg-batch-delete-' + tab).prop('disabled', count === 0);
		if (tab === 'pending') {
			$('#waisg-pending-batch-apply').prop('disabled', count === 0);
		}
	}

	// ── 批量删除 ──────────────────────────────────────────────
	$(document).on('click', '.waisg-batch-delete-btn', function(){
		var tab = $(this).data('tab');
		var ids = [];
		$('.waisg-check-item[data-tab="' + tab + '"]:checked').each(function(){ ids.push($(this).val()); });
		if (!ids.length || !confirm('确定删除选中的 ' + ids.length + ' 条记录？此操作不可撤销。')) return;
		var $btn = $(this).prop('disabled', true).text('删除中...');
		$.post(ajaxurl, {
			action: 'waisg_batch_delete_history',
			nonce:  nonce,
			ids:    ids.join(',')
		}, function(res){
			if (res.success) {
				ids.forEach(function(id){
					$('#waisg-pending-row-' + id + ', #waisg-history-row-' + id).fadeOut(200, function(){ $(this).remove(); });
				});
				updateBatchCount(tab);
				$('.waisg-check-all[data-tab="' + tab + '"]').prop('checked', false);
			} else {
				alert(res.data.message);
			}
			$btn.prop('disabled', false).text('批量删除选中');
		}).fail(function(){
			$btn.prop('disabled', false).text('批量删除选中');
		});
	});

	// ── 每页条数切换（备份 Tab 用按钮式，待处理 Tab 用表单提交） ──
	$('.waisg-per-page-btn').on('click', function(){
		var tab = $(this).data('tab') || 'backup';
		var val = parseInt($(this).siblings('.waisg-per-page-input').val(), 10) || 20;
		val = Math.max(10, Math.min(200, val));
		var url = new URL(window.location.href);
		url.searchParams.set('tab', tab);   // 显式锁定当前 Tab，避免 URL 缺 tab 时回落到默认的「待处理」
		url.searchParams.set('per_page', val);
		url.searchParams.set('paged', '1');
		window.location.href = url.toString();
	});

	// ── 待处理：批量应用到 WordPress ────────────────────────
	$('#waisg-pending-batch-status').on('change', function(){
		$('#waisg-pending-batch-date').toggle($(this).val() === 'future');
	});

	$('#waisg-pending-batch-apply').on('click', function(){
		var ids = [];
		$('.waisg-check-item[data-tab="pending"]:checked').each(function(){
			ids.push(parseInt($(this).val(), 10));
		});
		if (!ids.length) { alert('请先勾选待处理记录。'); return; }

		var target   = $('#waisg-pending-batch-status').val();
		var postDate = $('#waisg-pending-batch-date').val();
		if (target === 'future' && !postDate) { alert('请选择定时发布时间。'); return; }

		if (!confirm('确定批量应用选中的 ' + ids.length + ' 条记录到 WordPress？')) return;

		var $btn   = $(this).prop('disabled', true).text('应用中...');
		var $msg   = $('#waisg-pending-batch-msg').hide();
		var $bar   = $('#waisg-pending-batch-progress').show();
		var done = 0, fail = 0;
		var total = ids.length;
		var pending = ids.slice();

		function applyNext(){
			if (!pending.length) {
				$btn.prop('disabled', false).text('📥 批量应用到 WordPress');
				$bar.hide();
				var txt = '✅ ' + done + ' 条已应用' + (fail ? '，' + fail + ' 条失败' : '');
				$msg.text(txt).css('color', fail ? '#c00' : '#0a6').show();
				// 取消所有勾选并刷新批量按钮状态
				$('.waisg-check-item[data-tab="pending"]').prop('checked', false);
				$('.waisg-check-all[data-tab="pending"]').prop('checked', false);
				updateBatchCount('pending');
				return;
			}
			var id = pending.shift();
			$('#waisg-pending-progress-text').text(
				'正在处理第 ' + (done + fail + 1) + ' / ' + total + ' 条（ID: ' + id + '）...'
			);
			$.post(ajaxurl, {
				action:        'waisg_save_staged_to_wp',
				nonce:         nonce,
				history_id:    id,
				target_status: target,
				post_date:     postDate ? postDate.replace('T', ' ') + ':00' : '',
			}, function(res){
				if (res.success) {
					done++;
					$('#waisg-pending-row-' + id).fadeOut(200, function(){ $(this).remove(); });
				} else {
					fail++;
				}
				applyNext();
			}).fail(function(){
				fail++;
				applyNext();
			});
		}
		applyNext();
	});

})(jQuery);
</script>
