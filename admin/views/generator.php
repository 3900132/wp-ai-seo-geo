<?php
/**
 * 批量生成文章页面视图
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$image_source = WAISG_Settings::get( 'image_source', 'none' );
// 按 image_source 取对应独立字段判断是否已配置 Key（v1.9.7 拆字段后不再用共用 image_api_key）
$stock_key = '';
if ( $image_source === 'pexels' ) {
	$stock_key = WAISG_Settings::get( 'image_api_key_pexels', '' );
} elseif ( $image_source === 'unsplash' ) {
	$stock_key = WAISG_Settings::get( 'image_api_key_unsplash', '' );
}
// 兼容旧用户：新字段为空时回退旧共用字段（迁移前数据）
if ( $stock_key === '' ) {
	$stock_key = WAISG_Settings::get( 'image_api_key', '' );
}
$has_image    = $image_source !== 'none' && (
	$image_source === 'ai_image'
		? ( ! empty( WAISG_Settings::get( 'image_ai_url', '' ) ) && ! empty( WAISG_Settings::get( 'image_ai_key', '' ) ) )
		: ! empty( $stock_key )
);

$post_types = WAISG_Settings::get_post_types();
$pt_objects = get_post_types( array( 'public' => true ), 'objects' );
$categories = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );

// 公共设置区域 HTML（post_type / category / image / 字数）
ob_start();
?>
<table class="form-table" style="margin-top:0;">
	<tr>
		<th><label>使用模型</label></th>
		<td>
			<?php
			$gen_batch_model_default = WAISG_Settings::get( 'batch_model', 'main' );
			$gen_lm_name = WAISG_Settings::get( 'lightweight_model', '' );
			?>
			<select class="gen-model-override-input">
				<option value="main" <?php selected( $gen_batch_model_default, 'main' ); ?>>主模型（<?php echo esc_html( WAISG_Settings::get( 'model', 'gpt-4o' ) ); ?>）</option>
				<?php if ( $gen_lm_name ) : ?>
				<option value="lightweight" <?php selected( $gen_batch_model_default, 'lightweight' ); ?>>轻量模型（<?php echo esc_html( $gen_lm_name ); ?>）</option>
				<?php endif; ?>
			</select>
			<?php if ( ! $gen_lm_name ) : ?>
			<p class="description" style="color:#c00;">⚠️ 未配置轻量模型，请先在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>">基本设置</a> 中填写轻量模型名称。</p>
			<?php else : ?>
			<p class="description">选择轻量模型可大幅降低 API 费用，适合批量生成。默认值跟随「设置 → 批量优化设置 → 批量任务模型」。</p>
			<?php endif; ?>
		</td>
	</tr>
	<tr>
		<th>批量润色</th>
		<td>
			<?php $gen_humanize_on = (int) WAISG_Settings::get( 'humanize_enabled', 0 ); ?>
			<label style="<?php echo $gen_humanize_on ? '' : 'color:#999;'; ?>">
				<input type="checkbox" class="gen-skip-humanize-input" value="1"
					<?php disabled( $gen_humanize_on, 0 ); ?> />
				跳过二次润色（大幅加速，每篇少 1-3 次 API 调用）
			</label>
			<p class="description">
				<?php if ( $gen_humanize_on ) : ?>
					⚠️ 当前已全局开启「降低 AI 痕迹」，每篇需额外 1-3 次 API 调用进行润色，<strong>是最大的耗时来源</strong>。
					勾选此项可在批量生成场景下跳过润色，生成后可在「待处理」中逐篇手动润色。
				<?php else : ?>
					当前未开启「降低 AI 痕迹」，无需勾选（已自动禁用）。
				<?php endif; ?>
			</p>
		</td>
	</tr>
	<tr>
		<th><label>每篇字数</label></th>
		<td>
			<input type="number" class="gen-length-input small-text" value="0" min="0" max="10000" />
			<span>字（0 = 不限制）</span>
		</td>
	</tr>
	<tr>
		<th><label>输出语言</label></th>
		<td>
			<select class="gen-language-input">
				<option value="zh-CN">中文（简体）</option>
				<option value="zh-TW">中文（繁体）</option>
				<option value="en">English</option>
				<option value="ja">日本語</option>
				<option value="ko">한국어</option>
				<option value="es">Español</option>
				<option value="fr">Français</option>
			</select>
		</td>
	</tr>
	<tr>
		<th><label>保存为</label></th>
		<td>
			<select class="gen-post-type-input">
				<?php foreach ( $post_types as $pt_slug ) :
					$pt_obj = $pt_objects[ $pt_slug ] ?? null;
					if ( ! $pt_obj ) continue;
				?>
				<option value="<?php echo esc_attr( $pt_slug ); ?>">
					<?php echo esc_html( $pt_obj->labels->singular_name ); ?>（<?php echo esc_html( $pt_slug ); ?>）
				</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<tr class="gen-category-row">
		<th><label>文章分类</label></th>
		<td>
			<select class="gen-category-input">
				<option value="0">— 不指定分类 —</option>
				<?php foreach ( $categories as $cat ) : ?>
				<option value="<?php echo absint( $cat->term_id ); ?>">
					<?php echo esc_html( $cat->name ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<?php if ( $has_image ) : ?>
	<tr>
		<th>图片</th>
		<td>
			<p style="margin:0;">✅ 已配置 <strong><?php echo esc_html( $image_source === 'ai_image' ? 'AI 大模型' : strtoupper( $image_source ) ); ?></strong>，每篇自动插入 <strong><?php echo absint( WAISG_Settings::get( 'images_per_post', 2 ) ); ?></strong> 张图片。</p>
		</td>
	</tr>
	<?php endif; ?>
	<?php $gen_tpl_list = WAISG_Settings::get_templates(); if ( ! empty( $gen_tpl_list ) ) : ?>
	<tr>
		<th><label>内容结构模板</label></th>
		<td>
			<select class="gen-template-input">
				<option value="0">— 不使用模板（AI 自由发挥）—</option>
				<?php foreach ( $gen_tpl_list as $tpl ) : ?>
				<option value="<?php echo absint( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description">选择模板后，AI 将按照预设结构组织文章内容。</p>
		</td>
	</tr>
	<?php endif; ?>
</table>
<?php
$common_settings_html = ob_get_clean();
?>

<div class="wrap waisg-settings-wrap">
	<h1>AI文章生成</h1>

	<?php if ( ! WAISG_Settings::get( 'api_url' ) || ! WAISG_Settings::get( 'api_key' ) ) : ?>
	<div class="notice notice-error inline"><p>⚠️ 请先 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>">配置 AI 接口信息</a> 后再使用本功能。</p></div>
	<?php endif; ?>

	<?php if ( ! $has_image ) : ?>
	<div class="notice notice-info inline" style="margin-bottom:8px;">
		<p>💡 未配置图片 API，文章将不含图片。如需图文并茂，请在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>">基本设置 → 图片配置</a> 中完成配置。</p>
	</div>
	<?php endif; ?>

	<!-- 模式切换 Tab -->
	<div style="margin-bottom:0;border-bottom:1px solid #c3c4c7;">
		<button type="button" class="gen-tab-btn gen-tab-active" data-tab="manual"
			style="border:1px solid #c3c4c7;border-bottom:1px solid #fff;border-radius:4px 4px 0 0;background:#fff;padding:8px 20px;cursor:pointer;font-size:14px;margin-bottom:-1px;position:relative;">
			✏️ 手动输入
		</button>
		<button type="button" class="gen-tab-btn" data-tab="import"
			style="border:1px solid transparent;border-radius:4px 4px 0 0;background:transparent;padding:8px 20px;cursor:pointer;font-size:14px;color:#646970;margin-left:4px;margin-bottom:-1px;position:relative;">
			📋 批量导入关键词
		</button>
		<button type="button" class="gen-tab-btn" data-tab="rewrite"
			style="border:1px solid transparent;border-radius:4px 4px 0 0;background:transparent;padding:8px 20px;cursor:pointer;font-size:14px;color:#646970;margin-left:4px;margin-bottom:-1px;position:relative;">
			✂️ 改写/伪原创
		</button>
	</div>

	<!-- ===================== Tab 1：手动输入 ===================== -->
	<div id="gen-tab-manual" class="waisg-card" style="border-top:none;border-radius:0 4px 4px 4px;margin-top:0;">
		<p class="description" style="margin-bottom:12px;">手动填写关键词，AI 按照同一主题生成 N 篇内容各不相同的文章。</p>
		<table class="form-table" style="margin-top:0;">
			<tr>
				<th><label for="gen-topic">文章主题 / 要求</label></th>
				<td>
					<input type="text" id="gen-topic" class="large-text"
						placeholder="可选。如「如何挑选家用净水器」；不填则以关键词为主题" />
				</td>
			</tr>
			<tr>
				<th><label for="gen-keywords">核心关键词 <span style="color:#c00;">*</span></label></th>
				<td>
					<input type="text" id="gen-keywords" class="large-text"
						placeholder="必填。如：净水器,滤芯,家用净水（英文逗号分隔）" />
					<p class="description">AI 会在每篇文章中重点体现这些关键词。</p>
				</td>
			</tr>
			<tr>
				<th><label for="gen-description">补充说明</label></th>
				<td>
					<textarea id="gen-description" class="large-text" rows="2"
						placeholder="可选。填写文章风格、目标受众、特殊要求等..."></textarea>
				</td>
			</tr>
			<tr>
				<th><label for="gen-count">生成篇数 <span style="color:#c00;">*</span></label></th>
				<td>
					<input type="number" id="gen-count" class="small-text" value="3" min="1" max="50" />
					<span>篇（最多 50 篇）</span>
					<p class="description">AI 会生成 N 篇内容各不相同的文章（主题相同，角度各异）。</p>
				</td>
			</tr>
		</table>
		<?php echo $common_settings_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div style="margin-top:16px;">
			<button type="button" id="gen-start-manual" class="button button-primary button-large">✨ 开始生成文章</button>
			<button type="button" class="gen-stop-btn button button-large" style="display:none;margin-left:10px;">⏹ 停止</button>
		</div>
	</div>

	<!-- ===================== Tab 2：批量导入关键词 ===================== -->
	<div id="gen-tab-import" class="waisg-card" style="border-top:none;border-radius:0 4px 4px 4px;margin-top:0;display:none;">
		<p class="description" style="margin-bottom:12px;">导入关键词列表，可单独为每个关键词设置生成文章篇数。</p>

		<!-- 导入区 -->
		<div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:16px;">
			<div style="flex:1;">
				<label style="font-weight:600;display:block;margin-bottom:6px;">粘贴关键词（每行一个）</label>
				<textarea id="gen-import-text" class="large-text" rows="6"
					placeholder="净水器&#10;滤芯更换&#10;家用净水器选购指南&#10;反渗透净水器优缺点&#10;..."></textarea>
			</div>
			<div style="padding-top:24px;">
				<button type="button" id="gen-import-btn" class="button button-primary" style="white-space:nowrap;">
					📋 导入关键词
				</button>
				<p style="margin:8px 0 0;font-size:11px;color:#999;text-align:center;">↑ 点击导入</p>
			</div>
		</div>

		<!-- 关键词列表（导入后显示） -->
		<div id="gen-kw-list-wrap" style="display:none;">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
				<strong id="gen-kw-count-label">已导入 0 个关键词，合计生成 0 篇</strong>
			</div>

			<table class="widefat striped" id="gen-kw-table">
				<thead>
					<tr>
						<th style="width:40px;">序号</th>
						<th>关键词</th>
						<th style="width:120px;text-align:center;">
							生成篇数
							<span style="font-weight:400;font-size:11px;color:#646970;display:block;">每个关键词</span>
						</th>
						<th style="width:60px;">操作</th>
					</tr>
				</thead>
				<tbody id="gen-kw-body"></tbody>
			</table>

			<div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
				<span style="font-size:12px;color:#646970;">批量设置篇数：</span>
				<button type="button" class="button button-small gen-kw-set-count" data-count="1">每个 1 篇</button>
				<button type="button" class="button button-small gen-kw-set-count" data-count="3">每个 3 篇</button>
				<button type="button" class="button button-small gen-kw-set-count" data-count="5">每个 5 篇</button>
				<button type="button" class="button button-small gen-kw-set-count" data-count="10">每个 10 篇</button>
				<button type="button" class="button button-small gen-kw-set-count" data-count="20">每个 20 篇</button>
				<button type="button" id="gen-kw-clear" class="button button-small" style="margin-left:auto;color:#c00;">清空列表</button>
			</div>
		</div>

		<!-- 导入模式的补充说明（可选） -->
		<div id="gen-import-extra" style="display:none;margin-top:16px;">
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="gen-import-topic-prefix">主题前缀（可选）</label></th>
					<td>
						<input type="text" id="gen-import-topic-prefix" class="regular-text"
							placeholder="如：如何选购 → AI 会把「如何选购 + 关键词」作为文章主题" />
						<p class="description">留空则直接以关键词作为文章主题。</p>
					</td>
				</tr>
				<tr>
					<th><label for="gen-import-description">通用补充说明（可选）</label></th>
					<td>
						<textarea id="gen-import-description" class="large-text" rows="2"
							placeholder="适用于所有关键词的通用要求，如风格、受众等..."></textarea>
					</td>
				</tr>
			</table>
			<?php echo $common_settings_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>

		<div style="margin-top:16px;">
			<button type="button" id="gen-start-import" class="button button-primary button-large" style="display:none;">
				✨ 开始批量生成文章
			</button>
			<button type="button" class="gen-stop-btn button button-large" style="display:none;margin-left:10px;">⏹ 停止</button>
		</div>
	</div>

	<!-- ===================== Tab 3：改写/伪原创 ===================== -->
	<div id="gen-tab-rewrite" class="waisg-card" style="border-top:none;border-radius:0 4px 4px 4px;margin-top:0;display:none;">
		<p class="description" style="margin-bottom:12px;">粘贴原文，AI 将改写为主题相同但句式、结构、表达方式全部不同的全新文章（适合伪原创）。</p>
		<table class="form-table" style="margin-top:0;">
			<tr>
				<th><label for="gen-rewrite-content">原文内容 <span style="color:#c00;">*</span></label></th>
				<td>
					<textarea id="gen-rewrite-content" class="large-text" rows="10"
						placeholder="粘贴需要改写的原文正文（纯文本或 HTML 均可）..."></textarea>
				</td>
			</tr>
			<tr>
				<th><label for="gen-rewrite-keywords">核心关键词（可选）</label></th>
				<td>
					<input type="text" id="gen-rewrite-keywords" class="regular-text"
						placeholder="关键词1,关键词2（AI 会在改写中体现这些关键词）" />
				</td>
			</tr>
			<tr>
				<th><label for="gen-rewrite-description">改写要求（可选）</label></th>
				<td>
					<textarea id="gen-rewrite-description" class="large-text" rows="2"
						placeholder="风格要求、目标受众、特殊写法等..."></textarea>
				</td>
			</tr>
			<tr>
				<th><label>输出语言</label></th>
				<td>
					<select id="gen-rewrite-language">
						<option value="zh-CN">中文（简体）</option>
						<option value="zh-TW">中文（繁体）</option>
						<option value="en">English</option>
						<option value="ja">日本語</option>
						<option value="ko">한국어</option>
						<option value="es">Español</option>
						<option value="fr">Français</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label>保存为</label></th>
				<td>
					<select id="gen-rewrite-post-type">
						<?php foreach ( $post_types as $pt_slug ) :
							$pt_obj = $pt_objects[ $pt_slug ] ?? null;
							if ( ! $pt_obj ) continue;
						?>
						<option value="<?php echo esc_attr( $pt_slug ); ?>">
							<?php echo esc_html( $pt_obj->labels->singular_name ); ?>（<?php echo esc_html( $pt_slug ); ?>）
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label>文章分类</label></th>
				<td>
					<select id="gen-rewrite-category">
						<option value="0">— 不指定分类 —</option>
						<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->term_id ); ?>">
							<?php echo esc_html( $cat->name ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php if ( ! empty( $gen_tpl_list ) ) : ?>
			<tr>
				<th><label>内容结构模板</label></th>
				<td>
					<select id="gen-rewrite-template">
						<option value="0">— 不使用模板（AI 自由发挥）—</option>
						<?php foreach ( $gen_tpl_list as $tpl ) : ?>
						<option value="<?php echo absint( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label>使用模型</label></th>
				<td>
					<select class="gen-model-override-input">
						<option value="main" <?php selected( $gen_batch_model_default, 'main' ); ?>>主模型（<?php echo esc_html( WAISG_Settings::get( 'model', 'gpt-4o' ) ); ?>）</option>
						<?php if ( $gen_lm_name ) : ?>
						<option value="lightweight" <?php selected( $gen_batch_model_default, 'lightweight' ); ?>>轻量模型（<?php echo esc_html( $gen_lm_name ); ?>）</option>
						<?php endif; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th>批量润色</th>
				<td>
					<?php $rw_humanize_on = (int) WAISG_Settings::get( 'humanize_enabled', 0 ); ?>
					<label style="<?php echo $rw_humanize_on ? '' : 'color:#999;'; ?>">
						<input type="checkbox" class="gen-skip-humanize-input" value="1"
							<?php disabled( $rw_humanize_on, 0 ); ?> />
						跳过二次润色（大幅加速，每篇少 1-3 次 API 调用）
					</label>
					<p class="description">
						<?php if ( $rw_humanize_on ) : ?>
							⚠️ 当前已全局开启「降低 AI 痕迹」，每篇需额外 1-3 次 API 调用进行润色，<strong>是最大的耗时来源</strong>。
						<?php else : ?>
							当前未开启「降低 AI 痕迹」，无需勾选（已自动禁用）。
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>
		<div style="margin-top:16px;">
			<button type="button" id="gen-start-rewrite" class="button button-primary button-large">✂️ 开始改写文章</button>
		</div>
	</div>

	<!-- ===================== 进度区域（两种模式共用） ===================== -->
	<div id="gen-progress-wrap" class="waisg-card" style="display:none;">
		<h2 style="margin-top:0;">生成进度</h2>
		<div style="background:#f0f0f0;border-radius:4px;height:22px;margin-bottom:10px;overflow:hidden;">
			<div id="gen-progress-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.4s ease;border-radius:4px;"></div>
		</div>
		<p id="gen-progress-text" style="margin:0 0 12px;font-weight:600;color:#2271b1;">准备中...</p>

		<table class="widefat fixed" id="gen-result-table" style="display:none;">
			<thead>
				<tr>
					<th style="width:32px;"><input type="checkbox" id="gen-result-check-all" title="全选/取消" /></th>
					<th style="width:40px;">序号</th>
					<th style="width:140px;">关键词</th>
					<th>文章标题</th>
					<th style="width:80px;text-align:center;">生成状态</th>
					<th style="width:110px;text-align:center;">操作</th>
				</tr>
			</thead>
			<tbody id="gen-result-body"></tbody>
		</table>

		<!-- 结果列表分页条 -->
		<div id="gen-result-pagination" style="display:none;margin-top:10px;display:flex;align-items:center;gap:6px;justify-content:center;">
			<button type="button" id="gen-result-first" class="button">«</button>
			<button type="button" id="gen-result-prev" class="button">‹ 上一页</button>
			<span id="gen-result-page-info" style="font-size:13px;color:#646970;padding:0 8px;">第 1 / 1 页</span>
			<button type="button" id="gen-result-next" class="button">下一页 ›</button>
			<button type="button" id="gen-result-last" class="button">»</button>
		</div>

		<div id="gen-batch-action-bar" style="display:none;margin-top:10px;padding:10px 14px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
			<strong style="font-size:13px;">批量保存到 WordPress（已勾选）：</strong>
			<select id="gen-batch-status">
				<option value="draft">存为草稿</option>
				<option value="publish">立即发布</option>
				<option value="future">定时发布</option>
			</select>
			<input type="datetime-local" id="gen-batch-post-date" style="display:none;width:195px;" />
			<button type="button" id="gen-batch-apply" class="button button-primary">批量保存</button>
			<span id="gen-batch-result" style="font-size:12px;color:#0a6;display:none;"></span>
		</div>

		<div style="margin-top:12px;">
			<details>
				<summary style="cursor:pointer;font-weight:600;color:#646970;">查看详细日志</summary>
				<div id="gen-log" style="margin-top:8px;background:#1d2327;color:#f0f0f0;padding:12px;border-radius:4px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;white-space:pre-wrap;"></div>
			</details>
		</div>
	</div>
</div>
