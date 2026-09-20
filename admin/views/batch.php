<?php
/**
 * 批量优化页面视图
 * @var array  $post_types  已启用的文章类型
 * @var array  $categories  所有分类
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// 支持从文章列表批量操作跳转传入的 post_ids
$preset_ids = sanitize_text_field( $_GET['post_ids'] ?? '' );
?>
<div class="wrap">
	<h1>🤖 批量 AI 优化</h1>
	<p class="description">批量对多篇文章执行 AI 一键优化（优化全部字段），每篇之间有自动间隔防止 API 超载。</p>

	<div class="waisg-card">
		<h2>筛选文章</h2>
		<table class="form-table">
			<tr>
				<th>文章类型</th>
				<td>
					<select id="waisg-batch-post-type">
						<?php foreach ( $post_types as $pt ) :
							$obj = get_post_type_object( $pt );
							?>
							<option value="<?php echo esc_attr( $pt ); ?>">
								<?php echo esc_html( $obj ? $obj->labels->singular_name : $pt ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th>文章状态</th>
				<td>
					<select id="waisg-batch-status">
						<option value="publish">已发布</option>
						<option value="draft">草稿</option>
						<option value="pending">待审</option>
						<option value="any">全部</option>
					</select>
				</td>
			</tr>
			<tr>
				<th>分类（仅文章）</th>
				<td>
					<select id="waisg-batch-cat">
						<option value="0">全部分类</option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>">
								<?php echo esc_html( $cat->name ); ?> (<?php echo esc_html( $cat->count ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th>每次最多获取</th>
				<td>
					<input type="number" id="waisg-batch-limit" value="100" min="1" max="500" class="small-text" /> 篇
					<span class="description" style="margin-left:6px;">从数据库获取的上限，建议不超过 200，最高 500</span>
				</td>
			</tr>
			<tr>
				<th>每页显示</th>
				<td>
					<select id="waisg-batch-per-page">
						<option value="10">10 篇/页</option>
						<option value="20" selected>20 篇/页</option>
						<option value="50">50 篇/页</option>
						<option value="100">100 篇/页</option>
						<option value="200">200 篇/页</option>
					</select>
					<span class="description" style="margin-left:6px;">每页只渲染对应数量的行，减少页面卡顿</span>
				</td>
			</tr>
			<tr>
				<th>排除文章 ID</th>
				<td>
					<input type="text" id="waisg-batch-exclude" class="regular-text"
						value="<?php echo ''; ?>"
						placeholder="多个 ID 用英文逗号分隔，如：1,2,3" />
				</td>
			</tr>
			<?php $batch_tpl_list = WAISG_Settings::get_templates(); if ( ! empty( $batch_tpl_list ) ) : ?>
			<tr>
				<th>内容结构模板</th>
				<td>
					<select id="waisg-batch-template">
						<option value="0">— 不使用模板（AI 自由发挥）—</option>
						<?php foreach ( $batch_tpl_list as $tpl ) : ?>
						<option value="<?php echo absint( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">选择后 AI 将按照预设结构优化文章内容。</p>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th>优化范围</th>
				<td>
					<label>
						<input type="checkbox" id="waisg-batch-seo-only" value="1" />
						仅优化 SEO 字段（不修改正文内容，速度更快、Token 消耗更少）
					</label>
				</td>
			</tr>
			<tr>
				<th>使用模型</th>
				<td>
					<?php
					$batch_model_default = WAISG_Settings::get( 'batch_model', 'main' );
					$lm_name = WAISG_Settings::get( 'lightweight_model', '' );
					?>
					<select id="waisg-batch-model-override">
						<option value="main" <?php selected( $batch_model_default, 'main' ); ?>>主模型（<?php echo esc_html( WAISG_Settings::get( 'model', 'gpt-4o' ) ); ?>）</option>
						<?php if ( $lm_name ) : ?>
						<option value="lightweight" <?php selected( $batch_model_default, 'lightweight' ); ?>>轻量模型（<?php echo esc_html( $lm_name ); ?>）</option>
						<?php endif; ?>
					</select>
					<?php if ( ! $lm_name ) : ?>
					<p class="description" style="color:#c00;">⚠️ 未配置轻量模型，请先在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>">基本设置</a> 中填写轻量模型名称。</p>
					<?php else : ?>
					<p class="description">选择轻量模型可大幅降低 API 费用，适合批量任务。默认值跟随「设置 → 批量优化设置 → 批量任务模型」。</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>批量润色</th>
				<td>
					<?php $bt_humanize_on = (int) WAISG_Settings::get( 'humanize_enabled', 0 ); ?>
					<label style="<?php echo $bt_humanize_on ? '' : 'color:#999;'; ?>">
						<input type="checkbox" id="waisg-batch-skip-humanize" value="1"
							<?php disabled( $bt_humanize_on, 0 ); ?> />
						跳过二次润色（大幅加速，每篇少 1-3 次 API 调用）
					</label>
					<p class="description">
						<?php if ( $bt_humanize_on ) : ?>
							⚠️ 当前已全局开启「降低 AI 痕迹」，批量优化时每篇需额外 1-3 次 API 调用进行润色，<strong>是最大的耗时来源</strong>。
							勾选此项可在批量场景下跳过润色，优化后在「待处理」中逐篇手动润色。
						<?php else : ?>
							当前未开启「降低 AI 痕迹」，无需勾选（已自动禁用）。
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>SEO 评分保障</th>
				<td>
					<label>
						<input type="checkbox" id="waisg-batch-auto-fix-seo" value="1" checked />
						自动修复至 SEO ≥ 90 分（优化后自动修正不达标字段，零额外 API 调用）
					</label>
					<p class="description">
						开启后每篇优化完成自动检查 SEO 评分，对不达标字段（描述过短、标题缺关键词等）进行 PHP 本地修正。
						<strong>不消耗任何额外 Token</strong>，通过字符串补全/截断/关键词注入实现。
					</p>
				</td>
			</tr>
		</table>
		<button type="button" id="waisg-batch-load" class="button button-secondary">获取文章列表</button>
	</div>

	<!-- 文章列表 -->
	<div id="waisg-batch-list-wrap" class="waisg-card" style="display:none;">
		<h2>待优化文章 <span id="waisg-batch-total"></span></h2>
		<div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
			<label><input type="checkbox" id="waisg-batch-select-all" /> 全选当前页</label>
			<label><input type="checkbox" id="waisg-batch-select-all-pages" /> 全选所有页</label>
			<button type="button" id="waisg-batch-start" class="button button-primary">▶ 开始批量优化</button>
			<button type="button" id="waisg-batch-retry-failed" class="button" style="display:none;background:#fff3cd;border-color:#e6c200;">🔄 仅重试失败项 (<span id="waisg-batch-failed-count">0</span>)</button>
			<button type="button" id="waisg-batch-stop" class="button" style="display:none;">⏹ 停止</button>
		</div>
		<table class="wp-list-table widefat fixed striped" id="waisg-batch-table">
			<thead>
				<tr>
					<th width="40"><input type="checkbox" id="waisg-batch-check-all" /></th>
					<th width="60">ID</th>
					<th>文章标题（优化后）</th>
					<th width="100">优化状态</th>
					<th width="220">SEO 评分</th>
					<th width="120">操作</th>
				</tr>
			</thead>
			<tbody id="waisg-batch-tbody"></tbody>
		</table>

		<!-- 分页条 -->
		<div id="waisg-batch-pagination" style="display:none;margin-top:10px;display:flex;align-items:center;gap:6px;justify-content:center;">
			<button type="button" id="waisg-batch-first" class="button">«</button>
			<button type="button" id="waisg-batch-prev" class="button">‹ 上一页</button>
			<span id="waisg-batch-page-info" style="font-size:13px;color:#646970;padding:0 8px;">第 1 / 1 页</span>
			<button type="button" id="waisg-batch-next" class="button">下一页 ›</button>
			<button type="button" id="waisg-batch-last" class="button">»</button>
		</div>
	</div>

	<!-- 进度 -->
	<div id="waisg-batch-progress-wrap" class="waisg-card" style="display:none;">
		<h2>优化进度</h2>
		<div class="waisg-progress-bar-wrap">
			<div id="waisg-batch-progress-bar" class="waisg-progress-bar" style="width:0%">0%</div>
		</div>
		<p id="waisg-batch-progress-text">准备中...</p>
		<div id="waisg-batch-log" style="max-height:300px;overflow-y:auto;background:#f9f9f9;padding:10px;border:1px solid #ddd;font-family:monospace;font-size:12px;"></div>

		<!-- 批量应用操作栏（有文章优化完成后出现） -->
		<div id="waisg-batch-action-bar" style="display:none;margin-top:12px;padding:10px 14px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
			<strong style="font-size:13px;">批量应用到 WordPress（已勾选）：</strong>
			<select id="waisg-batch-action-status">
				<option value="draft">存为草稿</option>
				<option value="pending">保持待审</option>
				<option value="publish">立即发布</option>
				<option value="future">定时发布</option>
			</select>
			<input type="datetime-local" id="waisg-batch-action-date" style="display:none;width:195px;" />
			<button type="button" id="waisg-batch-action-apply" class="button button-primary">批量应用</button>
			<span id="waisg-batch-action-result" style="font-size:12px;color:#0a6;display:none;"></span>
		</div>
	</div>
</div>

<?php if ( $preset_ids ) : ?>
<script>
// 从文章列表批量操作跳转进来，预填入文章 ID
document.addEventListener('DOMContentLoaded', function(){
	var ids = '<?php echo esc_js( $preset_ids ); ?>'.split(',').map(Number).filter(Boolean);
	if (!ids.length) return;
	var tbody = document.getElementById('waisg-batch-tbody');
	var rows = '';
	ids.forEach(function(id){
		rows += '<tr id="waisg-batch-row-'+id+'" class="waisg-batch-row" data-page="1">' +
			'<td><input type="checkbox" class="waisg-batch-check" value="'+id+'" checked /></td>' +
			'<td>'+id+'</td>' +
			'<td>（ID：'+id+'）</td>' +
			'<td><span class="waisg-batch-status" id="waisg-batch-status-'+id+'">等待</span></td>' +
			'<td id="waisg-batch-score-'+id+'" style="font-size:11px;color:#999;">—</td>' +
			'<td id="waisg-batch-op-'+id+'"></td>' +
		'</tr>';
	});
	tbody.innerHTML = rows;
	document.getElementById('waisg-batch-total').textContent = '（共 ' + ids.length + ' 篇）';
	document.getElementById('waisg-batch-list-wrap').style.display = '';
});
</script>
<?php endif; ?>
