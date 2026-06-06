<?php
/**
 * 设置页面视图
 * @var array $opts 当前设置值
 * @var array $all_post_types 所有公开文章类型
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$enabled_types = $opts['post_types'] ?? array( 'post', 'page' );
?>
<div class="wrap waisg-settings-wrap">
	<h1>🤖 AI SEO + GEO 智能优化 — 设置</h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'waisg_settings_group' ); ?>

		<!-- API 配置 -->
		<div class="waisg-card">
			<h2>一、AI 大模型接口配置</h2>
			<table class="form-table">
				<tr>
					<th><label for="waisg_api_url">API 地址（Base URL）</label></th>
					<td>
						<input type="url" id="waisg_api_url" name="waisg_settings[api_url]"
							value="<?php echo esc_attr( $opts['api_url'] ?? '' ); ?>"
							class="regular-text" placeholder="https://api.openai.com/v1" />
						<p class="description">兼容 OpenAI 格式的接口地址，例如 DeepSeek、文心等。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_api_key">API Key</label></th>
					<td>
						<span style="position:relative;display:inline-block;">
							<input type="password" id="waisg_api_key" name="waisg_settings[api_key]"
								value="<?php echo esc_attr( $opts['api_key'] ?? '' ); ?>"
								class="regular-text" autocomplete="new-password" style="padding-right:36px;" />
							<button type="button" id="waisg-toggle-api-key" title="显示 / 隐藏 API Key"
								style="position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;line-height:1;padding:2px;">👁</button>
						</span>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_model">模型名称</label></th>
					<td>
						<input type="text" id="waisg_model" name="waisg_settings[model]"
							value="<?php echo esc_attr( $opts['model'] ?? 'gpt-4o' ); ?>"
							class="regular-text" placeholder="gpt-4o" />
						<p class="description">例如：gpt-4o、deepseek-chat、ernie-4.0 等。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_lightweight_model">轻量模型（SEO 任务）</label></th>
					<td>
						<input type="text" id="waisg_lightweight_model" name="waisg_settings[lightweight_model]"
							value="<?php echo esc_attr( $opts['lightweight_model'] ?? '' ); ?>"
							class="regular-text" placeholder="留空则使用主模型" />
						<p class="description">用于 SEO 字段优化、单字段修复等简单任务，如 gpt-4o-mini，费用更低。留空则使用上方主模型。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_timeout">请求超时（秒）</label></th>
					<td>
						<input type="number" id="waisg_timeout" name="waisg_settings[timeout]"
							value="<?php echo absint( $opts['timeout'] ?? 60 ); ?>"
							min="10" max="300" class="small-text" /> 秒
					</td>
				</tr>
				<tr>
					<th><label for="waisg_temperature">温度</label></th>
					<td>
						<input type="number" id="waisg_temperature" name="waisg_settings[temperature]"
							value="<?php echo esc_attr( $opts['temperature'] ?? '0.3' ); ?>"
							min="0.1" max="0.7" step="0.1" class="small-text" />
						<p class="description">0.1 ~ 0.7，越低越稳定，推荐 0.3。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_max_tokens">最大 Tokens</label></th>
					<td>
						<input type="number" id="waisg_max_tokens" name="waisg_settings[max_tokens]"
							value="<?php echo absint( $opts['max_tokens'] ?? 4096 ); ?>"
							min="512" max="32000" class="small-text" />
					</td>
				</tr>
				<tr>
					<th>连通测试</th>
					<td>
						<button type="button" id="waisg-test-api" class="button">测试连接</button>
						<span id="waisg-test-api-result" style="margin-left:10px;font-size:13px;display:none;"></span>
						<p class="description">使用当前填写的 API 地址/Key/模型发送一条测试请求，无需保存设置。</p>
					</td>
				</tr>
				<tr id="waisg-lightweight-test-row" style="display:none;">
					<th>轻量模型测试</th>
					<td>
						<button type="button" id="waisg-test-lightweight-api" class="button">测试轻量模型</button>
						<span id="waisg-test-lightweight-result" style="margin-left:10px;font-size:13px;display:none;"></span>
						<p class="description">使用当前填写的 API 地址/Key + 轻量模型名称发送测试请求。</p>
					</td>
				</tr>
				<tr>
					<th>Token 用量统计</th>
					<td>
						<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
							<span>本月（<span id="waisg-token-month">—</span>）：<strong id="waisg-token-monthly">—</strong> Tokens</span>
							<span>累计：<strong id="waisg-token-total">—</strong> Tokens</span>
						</div>
						<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:8px;">
							<span style="color:#555;">🔵 主模型：本月 <strong id="waisg-token-monthly-main">—</strong> | 累计 <strong id="waisg-token-total-main">—</strong></span>
							<span style="color:#555;">🟢 轻量模型：本月 <strong id="waisg-token-monthly-lightweight">—</strong> | 累计 <strong id="waisg-token-total-lightweight">—</strong></span>
						</div>
						<button type="button" id="waisg-reset-tokens" class="button button-small" style="color:#c00;">重置统计</button>
						<p class="description">统计所有 AI 请求消耗的 Token 数（页面加载时自动更新）。</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- 系统提示词 -->
		<div class="waisg-card">
			<h2>二、系统提示词（角色 Prompt）</h2>

			<p class="description" style="margin-bottom:10px;">
				<strong>作用：</strong>相当于告诉 AI「你是谁、你的写作风格是什么、有哪些规则必须遵守」。每次调用 AI 时都会先发送这段内容，影响所有文章的优化和生成效果。留空则使用内置默认提示词。
			</p>

			<textarea id="waisg_system_prompt" name="waisg_settings[system_prompt]"
				rows="8" class="large-text"
				placeholder="留空则使用内置默认提示词。示例：&#10;你是一名专业的 WordPress 内容创作与 SEO 优化专家，拥有 10 年内容营销经验。&#10;写作风格：通俗易懂、逻辑严谨、数据翔实，深受搜索引擎和 AI 大模型青睐。&#10;必须遵守：文章必须对读者有实际价值，严禁堆砌关键词，严禁生成违法或低俗内容。"
			><?php echo esc_textarea( $opts['system_prompt'] ?? '' ); ?></textarea>

			<div style="margin-top:10px;padding:12px;background:#f0f6fc;border-left:3px solid #2271b1;border-radius:2px;font-size:13px;line-height:1.8;">
				<strong>可用变量（进阶用法）：</strong>以下变量会在发送前自动替换为当前文章的实际内容，<em>通常不需要用到</em>，适合对特定文章做个性化角色设定。<br>
				<code>[标题]</code> — 文章标题 &nbsp;
				<code>[内容]</code> — 文章正文 &nbsp;
				<code>[关键词]</code> — SEO 关键词 &nbsp;
				<code>[摘要]</code> — 文章摘要 &nbsp;
				<code>[描述]</code> — SEO 描述<br>
				<span style="color:#646970;">示例：<code>你是 [关键词] 领域的资深专家，请用专业权威的口吻优化这篇文章。</code></span>
				<div style="margin-top:8px;padding:8px 10px;background:#fff3cd;border-left:3px solid #d63638;border-radius:2px;font-size:12px;color:#7a4400;">
					⚠️ <strong>Token 消耗警告</strong>：<br>
					• <code>[内容]</code> 会插入完整正文，导致正文在系统提示词与用户提示词中<strong>各发送一次（Token 翻倍）</strong>，长文章尤其消耗大。<br>
					• 任何变量都会让系统提示词每次不同，<strong>破坏 OpenAI/Claude 的 prompt caching 命中</strong>（批量优化场景下损失最明显）。<br>
					• <strong>建议</strong>：留空或写静态角色描述（不含变量），插件内置 prompt 已经将文章信息正确传递给 AI。
				</div>
			</div>
		</div>

		<!-- 文章类型 -->
		<div class="waisg-card">
			<h2>三、支持的文章类型</h2>
			<p class="description">仅在已启用的文章类型编辑页显示 AI 功能按钮。</p>
			<fieldset>
				<?php foreach ( $all_post_types as $pt_slug => $pt_obj ) : ?>
					<label style="display:inline-block;margin-right:20px;margin-bottom:8px;">
						<input type="checkbox"
							name="waisg_settings[post_types][]"
							value="<?php echo esc_attr( $pt_slug ); ?>"
							<?php checked( in_array( $pt_slug, $enabled_types, true ) ); ?> />
						<?php echo esc_html( $pt_obj->labels->singular_name ); ?>
						<small>(<?php echo esc_html( $pt_slug ); ?>)</small>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>

		<!-- SEO 字段名 -->
		<div class="waisg-card">
			<h2>四、SEO 字段名配置（兼容所有 SEO 插件）</h2>			<p class="description">留空则使用默认值（自动兼容 Yoast、RankMath、AIOSEO）。</p>
			<table class="form-table">
				<tr>
					<th><label for="waisg_seo_title_field">SEO 标题字段名</label></th>
					<td>
						<input type="text" id="waisg_seo_title_field"
							name="waisg_settings[seo_title_field]"
							value="<?php echo esc_attr( $opts['seo_title_field'] ?? '' ); ?>"
							class="regular-text" placeholder="_yoast_wpseo_title" />
						<p class="description">
							Yoast: <code>_yoast_wpseo_title</code> ｜
							RankMath: <code>rank_math_title</code> ｜
							AIOSEO: <code>_aioseo_title</code>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_seo_description_field">SEO 描述字段名</label></th>
					<td>
						<input type="text" id="waisg_seo_description_field"
							name="waisg_settings[seo_description_field]"
							value="<?php echo esc_attr( $opts['seo_description_field'] ?? '' ); ?>"
							class="regular-text" placeholder="_yoast_wpseo_metadesc" />
						<p class="description">
							Yoast: <code>_yoast_wpseo_metadesc</code> ｜
							RankMath: <code>rank_math_description</code> ｜
							AIOSEO: <code>_aioseo_description</code>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_seo_keywords_field">SEO 关键词字段名</label></th>
					<td>
						<input type="text" id="waisg_seo_keywords_field"
							name="waisg_settings[seo_keywords_field]"
							value="<?php echo esc_attr( $opts['seo_keywords_field'] ?? '' ); ?>"
							class="regular-text" placeholder="_yoast_wpseo_focuskw" />
					</td>
				</tr>
			</table>
		</div>

		<!-- 批量设置 -->
		<div class="waisg-card">
			<h2>五、批量优化设置</h2>
			<table class="form-table">
				<tr>
					<th><label for="waisg_batch_interval">请求间隔（秒）</label></th>
					<td>
						<input type="number" id="waisg_batch_interval"
							name="waisg_settings[batch_interval]"
							value="<?php echo absint( $opts['batch_interval'] ?? 3 ); ?>"
							min="1" max="30" class="small-text" /> 秒
						<p class="description">批量优化时每篇文章之间的等待时间，防止服务器卡死。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_batch_model">批量任务模型</label></th>
					<td>
						<select id="waisg_batch_model" name="waisg_settings[batch_model]">
							<option value="main" <?php selected( $opts['batch_model'] ?? 'main', 'main' ); ?>>主模型</option>
							<option value="lightweight" <?php selected( $opts['batch_model'] ?? 'main', 'lightweight' ); ?>>轻量模型</option>
						</select>
						<p class="description">
							批量处理（批量优化、批量生成、批量改写）使用的模型。<br>
							• <strong>主模型</strong>：使用基本设置中的主模型，质量更高但费用较高<br>
							• <strong>轻量模型</strong>：使用轻量模型（需先在基本设置中配置），可大幅降低费用
						</p>
					</td>
				</tr>
				<tr>
					<th>降低 AI 痕迹</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[humanize_enabled]" value="1"
								<?php checked( $opts['humanize_enabled'] ?? 0, 1 ); ?> />
							启用内容润色（AI 生成/优化后自动进行二次润色，降低 AI 检测率）
						</label>
						<p class="description">
							开启后，所有生成和优化的正文内容会经过二次润色处理，打散 AI 写作痕迹。
							会额外消耗一次 API 调用（使用轻量模型），但能显著降低被 AI 检测工具识别的概率。
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_humanize_prompt">润色提示词</label></th>
					<td>
						<textarea id="waisg_humanize_prompt" name="waisg_settings[humanize_prompt]"
							rows="10" class="large-text" style="font-family:monospace;font-size:12px;"
							placeholder="留空则使用内置默认润色提示词。"
						><?php echo esc_textarea( $opts['humanize_prompt'] ?? '' ); ?></textarea>
						<p class="description">
							自定义润色阶段的用户提示词（user prompt），控制 AI 如何改写正文使其更像人类写作。<br>
							• 必须包含 <code>[正文]</code> 占位符，会被替换为待润色的 HTML 正文<br>
							• 留空则使用内置默认提示词（包含详细的人类写作特征规则）
						</p>
						<details style="margin-top:6px;">
							<summary style="cursor:pointer;color:#2271b1;font-size:13px;">📋 查看内置默认润色提示词</summary>
							<div style="margin-top:6px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:260px;overflow:auto;"><?php echo esc_html( WAISG_AI_API::get_default_humanize_prompt() ); ?></div>
							<button type="button" class="button button-small" style="margin-top:6px;" onclick="document.getElementById('waisg_humanize_prompt').value=this.previousElementSibling.textContent.trim();">恢复为默认</button>
						</details>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_ai_phrases">AI 高频词替换词库</label></th>
					<td>
						<p style="margin-top:0;">
							<label>
								<input type="checkbox" name="waisg_settings[ai_phrases_enabled]" value="1"
									<?php checked( ( $opts['ai_phrases_enabled'] ?? 1 ), 1 ); ?> />
								启用 AI 高频词自动替换
							</label>
						</p>
						<textarea id="waisg_ai_phrases" name="waisg_settings[ai_phrases_custom]"
							rows="12" class="large-text" style="font-family:monospace;font-size:12px;"
							placeholder="每行一条规则，格式：AI高频词|替换词&#10;替换词留空表示删除该词。&#10;&#10;示例：&#10;此外，|&#10;然而，|不过，&#10;至关重要|很关键"
						><?php echo esc_textarea( $opts['ai_phrases_custom'] ?? '' ); ?></textarea>
						<p class="description">
							每行一条替换规则，格式：<code>原词|替换词</code>（用英文竖线 <code>|</code> 分隔）。<br>
							• 替换词留空（只写 <code>原词|</code>）= 删除该词<br>
							• 空行和以 <code>#</code> 开头的行会被忽略（可作注释）<br>
							• 留空此文本框则使用内置默认词库
						</p>
						<details style="margin-top:8px;">
							<summary style="cursor:pointer;color:#2271b1;font-size:13px;">📋 查看/恢复内置默认词库</summary>
							<div style="margin-top:6px;padding:10px;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:300px;overflow:auto;"><?php echo esc_html( WAISG_AI_API::get_default_phrases_text() ); ?></div>
							<button type="button" class="button button-small" style="margin-top:6px;" onclick="document.getElementById('waisg_ai_phrases').value=this.previousElementSibling.textContent.trim();">恢复为默认词库</button>
						</details>
					</td>
				</tr>
			</table>
		</div>

		<!-- 图片配置 -->
		<div class="waisg-card">
			<h2>六、图片配置（批量生成文章时自动插入图片）</h2>
			<table class="form-table">
				<tr>
					<th><label for="waisg_image_source">图片来源</label></th>
					<td>
						<select id="waisg_image_source" name="waisg_settings[image_source]" onchange="waisgToggleImageFields(this.value)">
							<option value="none"     <?php selected( $opts['image_source'] ?? 'none', 'none' ); ?>>不插入图片</option>
							<option value="pexels"   <?php selected( $opts['image_source'] ?? 'none', 'pexels' ); ?>>Pexels（免费，需 API Key）</option>
							<option value="unsplash" <?php selected( $opts['image_source'] ?? 'none', 'unsplash' ); ?>>Unsplash（免费，需 API Key）</option>
							<option value="ai_image" <?php selected( $opts['image_source'] ?? 'none', 'ai_image' ); ?>>AI 大模型生成图片（自定义接口）</option>
						</select>
						<p class="description">
							Pexels 免费注册获取 API Key：<a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a>　|
							Unsplash 免费注册：<a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a>
						</p>
					</td>
				</tr>
				<!-- Pexels / Unsplash API Key -->
				<tr id="waisg-img-row-apikey">
					<th><label for="waisg_image_api_key" id="waisg-img-apikey-label">图片 API Key</label></th>
					<td>
						<input type="password" id="waisg_image_api_key" name="waisg_settings[image_api_key]"
							value="<?php echo esc_attr( $opts['image_api_key'] ?? '' ); ?>"
							class="regular-text" autocomplete="new-password" />
						<p class="description" id="waisg-img-apikey-desc">
							<span id="waisg-img-apikey-hint-pexels">Pexels：填写 API Key（在 <a href="https://www.pexels.com/api/" target="_blank">pexels.com/api</a> 申请）。</span>
							<span id="waisg-img-apikey-hint-unsplash" style="display:none;">Unsplash：填写 <strong>Access Key</strong>（不是 Application ID，也不是 Secret Key）。在 <a href="https://unsplash.com/developers" target="_blank">unsplash.com/developers</a> → Your Application → Keys 中查看。</span>
						</p>
					</td>
				</tr>
				<!-- AI 图片生成字段 -->
				<tr id="waisg-img-row-ai-url">
					<th><label for="waisg_image_ai_url">图片生成 API 地址</label></th>
					<td>
						<input type="text" id="waisg_image_ai_url" name="waisg_settings[image_ai_url]"
							value="<?php echo esc_attr( $opts['image_ai_url'] ?? '' ); ?>"
							class="regular-text" placeholder="https://api.openai.com/v1/images/generations" />
						<p class="description">兼容 OpenAI 格式的图片生成接口地址（/v1/images/generations）。</p>
					</td>
				</tr>
				<tr id="waisg-img-row-ai-key">
					<th><label for="waisg_image_ai_key">图片生成 API Key</label></th>
					<td>
						<input type="password" id="waisg_image_ai_key" name="waisg_settings[image_ai_key]"
							value="<?php echo esc_attr( $opts['image_ai_key'] ?? '' ); ?>"
							class="regular-text" autocomplete="new-password" />
					</td>
				</tr>
				<tr id="waisg-img-row-ai-model">
					<th><label for="waisg_image_ai_model">图片生成模型</label></th>
					<td>
						<input type="text" id="waisg_image_ai_model" name="waisg_settings[image_ai_model]"
							value="<?php echo esc_attr( $opts['image_ai_model'] ?? 'dall-e-3' ); ?>"
							class="regular-text" placeholder="dall-e-3" />
						<p class="description">如 dall-e-3、dall-e-2、flux 等，取决于接口支持的模型。</p>
					</td>
				</tr>
				<tr id="waisg-img-row-ai-size">
					<th><label for="waisg_image_ai_size">图片尺寸</label></th>
					<td>
						<select id="waisg_image_ai_size" name="waisg_settings[image_ai_size]">
							<option value="1024x1024" <?php selected( $opts['image_ai_size'] ?? '1024x1024', '1024x1024' ); ?>>1024×1024（正方形）</option>
							<option value="1792x1024" <?php selected( $opts['image_ai_size'] ?? '1024x1024', '1792x1024' ); ?>>1792×1024（横向）</option>
							<option value="1024x1792" <?php selected( $opts['image_ai_size'] ?? '1024x1024', '1024x1792' ); ?>>1024×1792（纵向）</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_images_per_post">每篇文章插入图片数</label></th>
					<td>
						<input type="number" id="waisg_images_per_post" name="waisg_settings[images_per_post]"
							value="<?php echo absint( $opts['images_per_post'] ?? 2 ); ?>"
							min="1" max="10" class="small-text" /> 张
						<p class="description">建议 2~3 张，图片会自动根据文章内容选取相关主题。</p>
					</td>
				</tr>
				<tr id="waisg-img-row-test" style="display:none;">
					<th>图片 API 测试</th>
					<td>
						<button type="button" id="waisg-test-image-api" class="button">测试搜图</button>
						<div id="waisg-test-image-result" style="margin-top:6px;display:none;"></div>
						<p class="description">使用当前配置搜索一张测试图片，验证 API Key 是否有效。</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- 定时自动优化 -->
		<div class="waisg-card">
			<h2>七、定时自动优化</h2>
			<p class="description">定期自动对旧文章执行 AI 优化，优化结果存入「优化历史→待处理」，由你手动审阅后决定是否应用。</p>
			<table class="form-table">
				<tr>
					<th>启用定时优化</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[cron_enabled]" value="1"
								<?php checked( ! empty( $opts['cron_enabled'] ) ); ?> id="waisg-cron-enabled" />
							启用
						</label>
					</td>
				</tr>
				<tr>
					<th>执行频率</th>
					<td>
						<select name="waisg_settings[cron_frequency]">
							<option value="daily"       <?php selected( $opts['cron_frequency'] ?? 'daily', 'daily' ); ?>>每天</option>
							<option value="twicedaily"  <?php selected( $opts['cron_frequency'] ?? 'daily', 'twicedaily' ); ?>>每天两次</option>
							<option value="weekly"      <?php selected( $opts['cron_frequency'] ?? 'daily', 'weekly' ); ?>>每周</option>
							<option value="biweekly"    <?php selected( $opts['cron_frequency'] ?? 'daily', 'biweekly' ); ?>>每两周</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>每次优化篇数</th>
					<td>
						<input type="number" name="waisg_settings[cron_per_run]"
							value="<?php echo absint( $opts['cron_per_run'] ?? 5 ); ?>"
							min="1" max="20" class="small-text" /> 篇
						<p class="description">每次定时任务触发时最多优化的文章数，建议 3~10 篇。</p>
					</td>
				</tr>
				<tr>
					<th>优化目标</th>
					<td>
						<select name="waisg_settings[cron_target]">
							<option value="oldest"          <?php selected( $opts['cron_target'] ?? 'oldest', 'oldest' ); ?>>最早发布的文章（最旧的优先）</option>
							<option value="least_optimized" <?php selected( $opts['cron_target'] ?? 'oldest', 'least_optimized' ); ?>>优化次数最少的文章</option>
							<option value="random"          <?php selected( $opts['cron_target'] ?? 'oldest', 'random' ); ?>>随机选取</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>优化文章类型</th>
					<td>
						<?php
						$cron_types = $opts['cron_post_types'] ?? array();
						foreach ( $all_post_types as $pt_slug => $pt_obj ) :
						?>
						<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
							<input type="checkbox" name="waisg_settings[cron_post_types][]"
								value="<?php echo esc_attr( $pt_slug ); ?>"
								<?php checked( in_array( $pt_slug, $cron_types, true ) || empty( $cron_types ) ); ?> />
							<?php echo esc_html( $pt_obj->labels->singular_name ); ?>
						</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th>上次执行</th>
					<td>
						<?php
						$cron_log = get_option( 'waisg_cron_log', array() );
						if ( ! empty( $cron_log['last_run'] ) ) {
							echo esc_html( $cron_log['last_run'] ) . '，共优化 ' . absint( $cron_log['last_count'] ?? 0 ) . ' 篇';
						} else {
							echo '<span style="color:#646970;">尚未执行过</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th>下次执行</th>
					<td>
						<span id="waisg-cron-next-run" style="color:#646970;">—</span>
					</td>
				</tr>
				<tr>
					<th>手动触发</th>
					<td>
						<button type="button" class="button" id="waisg-cron-run-now">立即执行一次</button>
						<span id="waisg-cron-run-result" style="margin-left:10px;"></span>
						<p class="description">立即触发一次定时优化，优化结果存入「待处理」，不影响既有定时计划。</p>
					</td>
				</tr>
			</table>
		</div>
		<script>
		function waisgToggleImageFields(val) {
			var isStock = (val === 'pexels' || val === 'unsplash');
			var isAi    = (val === 'ai_image');
			var hasImg  = isStock || isAi;
			document.getElementById('waisg-img-row-apikey').style.display   = isStock ? '' : 'none';
			document.getElementById('waisg-img-row-ai-url').style.display   = isAi ? '' : 'none';
			document.getElementById('waisg-img-row-ai-key').style.display   = isAi ? '' : 'none';
			document.getElementById('waisg-img-row-ai-model').style.display = isAi ? '' : 'none';
			document.getElementById('waisg-img-row-ai-size').style.display  = isAi ? '' : 'none';
			document.getElementById('waisg-img-row-test').style.display     = hasImg ? '' : 'none';
			// 切换 Unsplash / Pexels 提示文字
			if (isStock) {
				var isUnsplash = (val === 'unsplash');
				document.getElementById('waisg-img-apikey-hint-pexels').style.display    = isUnsplash ? 'none' : '';
				document.getElementById('waisg-img-apikey-hint-unsplash').style.display  = isUnsplash ? '' : 'none';
			}
		}
		document.addEventListener('DOMContentLoaded', function(){
			waisgToggleImageFields(document.getElementById('waisg_image_source').value);
		});
		</script>

		<!-- Schema 结构化数据 -->
		<div class="waisg-card">
			<h2>八、Schema 结构化数据（JSON-LD）</h2>
			<p class="description">自动在页面 <code>&lt;head&gt;</code> 中输出结构化数据，帮助搜索引擎更好地理解网站内容。</p>
			<table class="form-table">
				<tr>
					<th>FAQPage Schema</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[schema_faq_enabled]" value="1"
								<?php checked( ! empty( $opts['schema_faq_enabled'] ) ); ?> />
							启用 FAQPage Schema 自动注入
						</label>
						<p class="description">
							自动提取文章正文中的 FAQ 区块（<code>&lt;h3&gt;</code> 问题 + 紧跟的段落作为答案），生成 FAQPage JSON-LD Schema。
							配合 AI 优化/生成时自动产出的 FAQ 区块使用。
						</p>
					</td>
				</tr>
				<tr>
					<th>全站基础 Schema</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[schema_base_enabled]" value="1"
								<?php checked( ! empty( $opts['schema_base_enabled'] ) ); ?> id="waisg-schema-base-toggle" />
							启用全站基础 Schema 自动注入
						</label>
						<p class="description">
							输出 WebSite、BreadcrumbList、Article/WebPage 结构化数据。
							<strong>如果主题已有 Schema 输出（如 functions.php 中的自动 Schema 代码），请先禁用主题侧的，避免重复。</strong>
						</p>
					</td>
				</tr>
				<tr id="waisg-schema-types-row">
					<th>Schema 类型开关</th>
					<td>
						<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
							<input type="checkbox" name="waisg_settings[schema_website]" value="1"
								<?php checked( ( $opts['schema_website'] ?? 1 ), 1 ); ?> />
							WebSite
						</label>
						<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
							<input type="checkbox" name="waisg_settings[schema_breadcrumb]" value="1"
								<?php checked( ( $opts['schema_breadcrumb'] ?? 1 ), 1 ); ?> />
							BreadcrumbList（面包屑）
						</label>
						<label style="display:inline-block;margin-right:16px;margin-bottom:6px;">
							<input type="checkbox" name="waisg_settings[schema_article]" value="1"
								<?php checked( ( $opts['schema_article'] ?? 1 ), 1 ); ?> />
							Article / WebPage
						</label>
					</td>
				</tr>
				<tr id="waisg-schema-default-image-row">
					<th><label for="waisg_schema_default_image">默认图 URL</label></th>
					<td>
						<input type="url" id="waisg_schema_default_image" name="waisg_settings[schema_default_image]"
							value="<?php echo esc_attr( $opts['schema_default_image'] ?? '' ); ?>"
							class="regular-text" placeholder="https://example.com/default-og.jpg" />
						<p class="description">
							Article Schema 图片四层兜底：特色图 → 正文第一张图 → 站点 Logo → 此默认图。<br>
							若此处留空且前三层均无图片，则 image 字段不输出（避免无效链接）。
						</p>
					</td>
				</tr>
				<tr>
					<th>Canonical URL</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[canonical_enabled]" value="1"
								<?php checked( ! empty( $opts['canonical_enabled'] ) ); ?> />
							自动为所有页面添加 <code>&lt;link rel="canonical"&gt;</code> 标签
						</label>
						<p class="description">
							防止搜索引擎因带参数的重复 URL（如 <code>?utm_source=</code>、<code>?page=2</code>）导致权重分散。
							输出当前页面的标准 URL（不含查询参数）。<br>
							<strong>注意</strong>：如果你已使用 Yoast / RankMath / AIOSEO 等 SEO 插件，它们通常已自动输出 canonical，请勿重复开启。
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- 内容结构模板 -->
		<div class="waisg-card" id="waisg-templates-card">
			<h2>九、内容结构模板</h2>
			<p class="description">预定义文章结构规范。AI 生成/优化时选择模板后，将按照预设结构组织内容。可用 <code>{关键词}</code> 作为关键词占位符。</p>

			<table class="widefat striped" style="margin-bottom:12px;">
				<thead>
					<tr>
						<th style="width:180px;">模板名称</th>
						<th>结构预览</th>
						<th style="width:140px;">操作</th>
					</tr>
				</thead>
				<tbody id="waisg-tpl-tbody">
				<?php $tpl_list = WAISG_Settings::get_templates(); ?>
				<?php if ( empty( $tpl_list ) ) : ?>
					<tr id="waisg-tpl-empty"><td colspan="3" style="color:#646970;text-align:center;padding:16px;">暂无模板，点击下方「新增模板」创建第一个。</td></tr>
				<?php else : ?>
				<?php foreach ( $tpl_list as $tpl ) : ?>
					<tr data-id="<?php echo absint( $tpl['id'] ); ?>">
						<td><strong><?php echo esc_html( $tpl['name'] ); ?></strong></td>
						<td><code style="white-space:pre-wrap;font-size:11px;word-break:break-all;"><?php
							$preview = mb_substr( $tpl['structure'], 0, 120 );
							echo esc_html( $preview . ( mb_strlen( $tpl['structure'] ) > 120 ? '...' : '' ) );
						?></code></td>
						<td>
							<button type="button" class="button button-small waisg-tpl-edit"
								data-id="<?php echo absint( $tpl['id'] ); ?>"
								data-name="<?php echo esc_attr( $tpl['name'] ); ?>"
								data-structure="<?php echo esc_attr( $tpl['structure'] ); ?>"
								data-extra="<?php echo esc_attr( $tpl['extra'] ?? '' ); ?>">编辑</button>
							<button type="button" class="button button-small waisg-tpl-delete"
								data-id="<?php echo absint( $tpl['id'] ); ?>"
								style="color:#c00;margin-left:4px;">删除</button>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<button type="button" id="waisg-tpl-new" class="button">＋ 新增模板</button>

			<!-- 编辑表单 -->
			<div id="waisg-tpl-form" style="display:none;margin-top:16px;padding:16px;background:#f9f9f9;border:1px solid #dcdcde;border-radius:4px;">
				<input type="hidden" id="waisg-tpl-id" value="0" />
				<table class="form-table" style="margin-top:0;">
					<tr>
						<th style="width:120px;"><label for="waisg-tpl-name">模板名称 <span style="color:#c00;">*</span></label></th>
						<td><input type="text" id="waisg-tpl-name" class="regular-text" placeholder="如：产品介绍标准模板" /></td>
					</tr>
					<tr>
						<th><label for="waisg-tpl-structure">文章结构 <span style="color:#c00;">*</span></label></th>
						<td>
							<textarea id="waisg-tpl-structure" class="large-text" rows="10"
								placeholder="每行一个章节，可用 {关键词} 占位符，支持 Markdown 标题格式。示例：&#10;## {关键词}简介&#10;介绍{关键词}的基本概念&#10;## {关键词}的核心优势&#10;### 优势一&#10;### 优势二&#10;## 使用场景&#10;## 常见问题&#10;## 总结"></textarea>
							<p class="description"><code>{关键词}</code> 会被替换为当前文章的实际关键词。每行对应一个章节或段落要求。</p>
						</td>
					</tr>
					<tr>
						<th><label for="waisg-tpl-extra">附加要求</label></th>
						<td>
							<textarea id="waisg-tpl-extra" class="large-text" rows="3"
								placeholder="可选。如：每章节不少于200字；语气专业正式；必须包含对比表格..."></textarea>
						</td>
					</tr>
				</table>
				<button type="button" id="waisg-tpl-save" class="button button-primary">保存模板</button>
				<button type="button" id="waisg-tpl-cancel" class="button" style="margin-left:8px;">取消</button>
				<span id="waisg-tpl-msg" style="margin-left:10px;font-size:13px;"></span>
			</div>
		</div>

		<!-- 十、AI 错误日志 -->
		<div class="waisg-card" id="waisg-error-log-card">
			<h2>十、AI 错误日志</h2>
			<table class="form-table">
				<tr>
					<th>启用错误日志</th>
					<td>
						<label>
							<input type="checkbox" name="waisg_settings[error_log_enabled]" value="1"
								<?php checked( ! empty( $opts['error_log_enabled'] ?? 1 ) ); ?> />
							记录 AI 操作出错信息（文章 ID、场景、错误原因）
						</label>
						<p class="description">出错记录可在 <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-error-logs' ) ); ?>">AI 错误日志</a> 页面查看、清除、导出。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_error_log_days">自动清除天数</label></th>
					<td>
						<input type="number" id="waisg_error_log_days" name="waisg_settings[error_log_days]"
							value="<?php echo absint( $opts['error_log_days'] ?? 30 ); ?>"
							min="0" max="3650" class="small-text" /> 天
						<p class="description">超过此天数的日志在新记录写入时自动清除。<strong>0 = 不按天数清除</strong>。</p>
					</td>
				</tr>
				<tr>
					<th><label for="waisg_error_log_max">最多保留条数</label></th>
					<td>
						<input type="number" id="waisg_error_log_max" name="waisg_settings[error_log_max]"
							value="<?php echo absint( $opts['error_log_max'] ?? 200 ); ?>"
							min="0" max="10000" class="small-text" /> 条
						<p class="description">只保留最新的 N 条日志，更早的自动丢弃。<strong>0 = 不限条数</strong>。</p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( '保存设置' ); ?>
	</form>
</div>
