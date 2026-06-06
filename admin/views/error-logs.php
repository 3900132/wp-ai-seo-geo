<?php
/**
 * AI 错误日志页面视图
 * @var array $logs 错误日志（最新在前）
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap">
	<h1>🐞 AI 错误日志</h1>
	<p class="description">
		记录 AI 操作（生成 / 优化 / 改写 / 批量 / 定时）出错的明细，便于排查问题。
		保留策略可在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>#waisg-error-log-card">基本设置 → 十、AI 错误日志</a> 中调整。
	</p>

	<div class="waisg-card">
		<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
			<span style="font-size:13px;color:#646970;">共 <strong id="waisg-log-total"><?php echo count( $logs ); ?></strong> 条记录</span>
			<button type="button" id="waisg-log-export" class="button">📤 导出 CSV</button>
			<button type="button" id="waisg-log-clear" class="button button-link-delete">🗑 清空全部</button>
			<span id="waisg-log-msg" style="font-size:13px;display:none;"></span>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th width="150">时间</th>
					<th width="90">文章 ID</th>
					<th width="120">场景</th>
					<th>错误原因</th>
					<th width="140">额外信息</th>
				</tr>
			</thead>
			<tbody id="waisg-log-tbody">
				<?php if ( empty( $logs ) ) : ?>
					<tr><td colspan="5" style="text-align:center;color:#646970;padding:20px;">暂无错误记录。AI 操作一切正常 🎉</td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $row ) :
						$post_id  = absint( $row['post_id'] ?? 0 );
						$edit_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
					?>
					<tr>
						<td><?php echo esc_html( $row['time'] ?? '' ); ?></td>
						<td>
							<?php if ( $post_id && $edit_url ) : ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" target="_blank">#<?php echo $post_id; ?></a>
							<?php elseif ( $post_id ) : ?>
								#<?php echo $post_id; ?>
							<?php else : ?>
								<span style="color:#999;">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( WAISG_Logger::context_label( $row['context'] ?? '' ) ); ?></td>
						<td style="color:#c00;"><?php echo esc_html( $row['message'] ?? '' ); ?></td>
						<td style="color:#646970;"><?php echo esc_html( $row['extra'] ?? '' ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<script>
(function ($) {
	var nonce = '<?php echo esc_js( wp_create_nonce( 'waisg_nonce' ) ); ?>';

	// 清空全部
	$('#waisg-log-clear').on('click', function () {
		if (!confirm('确定清空全部错误日志？此操作不可撤销。')) return;
		var $btn = $(this).prop('disabled', true);
		$.post(ajaxurl, { action: 'waisg_clear_error_logs', nonce: nonce }, function (res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$('#waisg-log-tbody').html('<tr><td colspan="5" style="text-align:center;color:#646970;padding:20px;">暂无错误记录。AI 操作一切正常 🎉</td></tr>');
				$('#waisg-log-total').text('0');
				$('#waisg-log-msg').text('✅ ' + res.data.message).css('color', '#0a6').show();
			} else {
				$('#waisg-log-msg').text('❌ ' + (res.data && res.data.message || '清空失败')).css('color', '#c00').show();
			}
		});
	});

	// 导出 CSV
	$('#waisg-log-export').on('click', function () {
		var $btn = $(this).prop('disabled', true).text('导出中...');
		$.post(ajaxurl, { action: 'waisg_export_error_logs', nonce: nonce }, function (res) {
			$btn.prop('disabled', false).text('📤 导出 CSV');
			if (!res.success) {
				$('#waisg-log-msg').text('❌ ' + (res.data && res.data.message || '导出失败')).css('color', '#c00').show();
				return;
			}
			var blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8;' });
			var url  = URL.createObjectURL(blob);
			var a    = document.createElement('a');
			a.href = url;
			a.download = 'ai-error-logs-' + new Date().toISOString().slice(0, 10) + '.csv';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(url);
		});
	});
})(jQuery);
</script>
