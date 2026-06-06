<?php
/**
 * 编辑页元框 + AJAX 处理
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Meta_Box {

	public function __construct() {
		add_action( 'add_meta_boxes',        array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX：生成文章
		add_action( 'wp_ajax_waisg_generate_article',  array( $this, 'ajax_generate_article' ) );
		// AJAX：优化全部
		add_action( 'wp_ajax_waisg_optimize_all',      array( $this, 'ajax_optimize_all' ) );
		// AJAX：仅优化 SEO 字段
		add_action( 'wp_ajax_waisg_optimize_seo_only', array( $this, 'ajax_optimize_seo_only' ) );
		// AJAX：单字段优化
		add_action( 'wp_ajax_waisg_optimize_single',   array( $this, 'ajax_optimize_single' ) );
		// AJAX：保存 SEO 字段
		add_action( 'wp_ajax_waisg_save_result',       array( $this, 'ajax_save_result' ) );
		// AJAX：获取 SEO 字段值
		// AJAX：获取文章当前优化次数（Gutenberg 保存后无刷新，通过此接口实时更新显示）
		add_action( 'wp_ajax_waisg_get_opt_count',     array( $this, 'ajax_get_opt_count' ) );
		// AJAX：从编辑器暂存到待处理
		add_action( 'wp_ajax_waisg_stage_from_editor', array( $this, 'ajax_stage_from_editor' ) );
		// AJAX：内链建议
		add_action( 'wp_ajax_waisg_suggest_links',     array( $this, 'ajax_suggest_links' ) );

		// pre_post_update：在 WordPress 写库前备份旧内容（这是唯一能读到旧数据的时机）
		add_action( 'pre_post_update', array( $this, 'before_post_update' ), 10, 2 );
		// save_post：写库完成后，增加计数 + 保存 SEO 字段
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
	}

	/**
	 * WordPress 写库前触发（pre_post_update）：备份旧版本内容。
	 * 此时 get_post() 读到的还是数据库里的旧数据，是正确的备份时机。
	 */
	public function before_post_update( $post_id, $data ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( empty( $_POST['waisg_save_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['waisg_save_nonce'] ) ), 'waisg_save_post' ) ) return;

		$ai_pending = absint( $_POST['_waisg_ai_pending'] ?? 0 );
		if ( ! $ai_pending ) return;

		$post = get_post( $post_id );
		if ( ! $post ) return;
		if ( ! in_array( $post->post_type, WAISG_Settings::get_post_types(), true ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// 此时数据库里还是旧版本，snapshot() 正确备份旧内容
		WAISG_History::snapshot( $post_id );
	}

	/**
	 * WordPress 写库后触发（save_post）：优化次数 +1，保存 SEO 字段。
	 */
	public function on_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! in_array( $post->post_type, WAISG_Settings::get_post_types(), true ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		if ( empty( $_POST['waisg_save_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['waisg_save_nonce'] ) ), 'waisg_save_post' ) ) {
			return;
		}

		$ai_pending = absint( $_POST['_waisg_ai_pending'] ?? 0 );
		if ( ! $ai_pending ) return;

		// 优化次数 +1
		$count = (int) get_post_meta( $post_id, '_waisg_opt_count', true );
		update_post_meta( $post_id, '_waisg_opt_count', $count + 1 );

		// 保存 SEO 字段（只保存用户明确配置的字段）
		$seo_title = sanitize_text_field( wp_unslash( $_POST['waisg_seo_title'] ?? '' ) );
		$seo_desc  = sanitize_textarea_field( wp_unslash( $_POST['waisg_seo_desc']  ?? '' ) );
		$seo_kw    = sanitize_text_field( wp_unslash( $_POST['waisg_seo_kw']    ?? '' ) );

		$title_key = WAISG_Settings::get( 'seo_title_field' );
		$desc_key  = WAISG_Settings::get( 'seo_description_field' );
		$kw_key    = WAISG_Settings::get( 'seo_keywords_field' );

		if ( ! empty( $title_key ) && $seo_title !== '' ) update_post_meta( $post_id, $title_key, $seo_title );
		if ( ! empty( $desc_key )  && $seo_desc  !== '' ) update_post_meta( $post_id, $desc_key,  $seo_desc );
		if ( ! empty( $kw_key )    && $seo_kw    !== '' ) update_post_meta( $post_id, $kw_key,    $seo_kw );

		// 清除待保存标记
		delete_post_meta( $post_id, '_waisg_ai_pending' );
	}

	/** 注册元框 */
	public function add_meta_boxes() {
		$post_types = WAISG_Settings::get_post_types();
		foreach ( $post_types as $pt ) {
			// 主功能区：放在编辑区正上方（normal + high），经典编辑器用户一打开就能看到
			add_meta_box(
				'waisg-meta-box',
				'🤖 AI SEO + GEO 智能优化',
				array( $this, 'render_meta_box' ),
				$pt,
				'normal',
				'high'
			);
			// 侧边栏快捷入口：Gutenberg 右侧边栏始终可见，方便 Gutenberg 用户
			add_meta_box(
				'waisg-sidebar-box',
				'🤖 AI 快捷操作',
				array( $this, 'render_sidebar_box' ),
				$pt,
				'side',
				'high'
			);
		}
	}

	/** 加载脚本/样式 */
	public function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, WAISG_Settings::get_post_types(), true ) ) return;

		wp_enqueue_style(
			'waisg-admin',
			WAISG_URL . 'assets/css/admin.css',
			array(),
			WAISG_VERSION
		);
		wp_enqueue_script(
			'waisg-admin',
			WAISG_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WAISG_VERSION,
			true
		);
		wp_localize_script( 'waisg-admin', 'waisgCfg', array(
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'waisg_nonce' ),
			'post_id'   => get_the_ID(),
		) );
	}

	/** 渲染右侧边栏快捷操作（Gutenberg 始终可见） */
	public function render_sidebar_box( $post ) {
		?>
		<div id="waisg-sidebar">
			<p style="margin:0 0 8px;font-size:12px;color:#646970;">AI 优化次数：<strong id="waisg-opt-count-side"><?php echo (int) get_post_meta( $post->ID, '_waisg_opt_count', true ); ?></strong></p>
			<div style="margin:0 0 8px;">
				<label for="waisg-model-override-side" style="font-size:12px;color:#646970;display:block;margin-bottom:3px;">🧠 使用模型</label>
				<?php echo $this->model_select_html( 'waisg-model-override-side', 'width:100%;' ); ?>
			</div>
			<button type="button" id="waisg-sidebar-btn-generate" class="button button-primary" style="width:100%;margin-bottom:6px;">
				✨ AI 生成文章
			</button>
			<button type="button" id="waisg-sidebar-btn-optimize" class="button" style="width:100%;margin-bottom:6px;">
				⚡ 一键优化全部
			</button>
			<button type="button" id="waisg-sidebar-btn-seo-only" class="button" style="width:100%;margin-bottom:6px;">
				🎯 仅优化 SEO
			</button>
			<p style="margin:6px 0 0;font-size:11px;color:#999;">
				完整功能请向下滚动查看「AI SEO+GEO 智能优化」区域
			</p>
		</div>
		<script>
		// 侧边栏按钮点击 → 触发主元框里对应的按钮，避免重复逻辑
		document.getElementById('waisg-sidebar-btn-generate') &&
		document.getElementById('waisg-sidebar-btn-generate').addEventListener('click', function(){
			var btn = document.getElementById('waisg-btn-generate');
			if (btn) { btn.scrollIntoView({behavior:'smooth', block:'center'}); btn.click(); }
			else { alert('请先向下滚动展开「AI SEO+GEO 智能优化」区域后再操作。'); }
		});
		document.getElementById('waisg-sidebar-btn-optimize') &&
		document.getElementById('waisg-sidebar-btn-optimize').addEventListener('click', function(){
			var btn = document.getElementById('waisg-btn-optimize-all');
			if (btn) { btn.scrollIntoView({behavior:'smooth', block:'center'}); btn.click(); }
			else { alert('请先向下滚动展开「AI SEO+GEO 智能优化」区域后再操作。'); }
		});
		document.getElementById('waisg-sidebar-btn-seo-only') &&
		document.getElementById('waisg-sidebar-btn-seo-only').addEventListener('click', function(){
			var btn = document.getElementById('waisg-btn-optimize-seo-only');
			if (btn) { btn.scrollIntoView({behavior:'smooth', block:'center'}); btn.click(); }
			else { alert('请先向下滚动展开「AI SEO+GEO 智能优化」区域后再操作。'); }
		});
		</script>
		<?php
	}

	/** 渲染元框内容 */
	public function render_meta_box( $post ) {
		$opt_count = (int) get_post_meta( $post->ID, '_waisg_opt_count', true );
		$seo_title = get_post_meta( $post->ID, $this->get_seo_field_name( 'title' ), true );
		$seo_desc  = get_post_meta( $post->ID, $this->get_seo_field_name( 'description' ), true );
		$seo_kw    = get_post_meta( $post->ID, $this->get_seo_field_name( 'keywords' ), true );
		?>
		<div id="waisg-box">

		<!-- 隐藏字段：随文章表单一起提交，用于 save_post 钩子处理 -->
		<!-- nonce 不绑定 post_id，兼容新建文章（post-new.php）和编辑文章 -->
		<?php wp_nonce_field( 'waisg_save_post', 'waisg_save_nonce' ); ?>
		<input type="hidden" id="waisg-ai-pending"  name="_waisg_ai_pending"  value="0" />
		<input type="hidden" id="waisg-seo-title-h" name="waisg_seo_title"   value="<?php echo esc_attr( $seo_title ); ?>" />
		<input type="hidden" id="waisg-seo-desc-h"  name="waisg_seo_desc"    value="<?php echo esc_attr( $seo_desc ); ?>" />
		<input type="hidden" id="waisg-seo-kw-h"    name="waisg_seo_kw"      value="<?php echo esc_attr( $seo_kw ); ?>" />

		<?php $tpl_list = WAISG_Settings::get_templates(); if ( ! empty( $tpl_list ) ) : ?>
		<div style="margin-bottom:8px;padding:6px 0;border-bottom:1px solid #f0f0f1;">
			<label for="waisg-opt-template" style="font-size:13px;margin-right:6px;">📐 内容结构模板：</label>
			<select id="waisg-opt-template" style="max-width:240px;">
				<option value="0">— 不使用模板（AI 自由发挥）—</option>
				<?php foreach ( $tpl_list as $tpl ) : ?>
				<option value="<?php echo absint( $tpl['id'] ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-settings' ) ); ?>#waisg-templates-card" target="_blank" style="margin-left:8px;font-size:12px;">管理模板</a>
		</div>
		<?php endif; ?>

			<!-- 使用模型（生成 + 优化共用，与右侧边栏联动同步） -->
			<div style="margin-bottom:8px;padding:6px 0;border-bottom:1px solid #f0f0f1;">
				<label for="waisg-model-override" style="font-size:13px;margin-right:6px;">🧠 使用模型：</label>
				<?php echo $this->model_select_html( 'waisg-model-override' ); ?>
				<span style="font-size:12px;color:#646970;margin-left:6px;">本页「生成 / 优化 / 仅SEO / 单字段」均使用此模型</span>
			</div>

			<!-- 生成文章 区域 -->
			<div class="waisg-section">
				<h3>📝 AI 生成文章（从零创作）</h3>
				<table class="waisg-table">
					<tr>
						<td><label>主题/要求</label></td>
						<td><input type="text" id="waisg-gen-topic" class="regular-text" placeholder="文章主题或具体要求..." /></td>
					</tr>
					<tr>
						<td><label>关键词</label></td>
						<td><input type="text" id="waisg-gen-keywords" class="regular-text" placeholder="关键词1,关键词2（可选）" /></td>
					</tr>
					<tr>
						<td><label>字数要求</label></td>
						<td><input type="number" id="waisg-gen-length" class="small-text" placeholder="0" min="0" /> 字（0=不限）</td>
					</tr>
				</table>
				<button type="button" id="waisg-btn-generate" class="button button-primary waisg-btn">
					✨ AI 生成文章
				</button>
			</div>

			<hr />

			<!-- 优化内容 区域 -->
			<div class="waisg-section">
				<h3>🚀 AI 优化内容（基于现有内容）</h3>

				<!-- 一键优化全部 -->
				<button type="button" id="waisg-btn-optimize-all" class="button button-primary waisg-btn">
					⚡ 一键优化全部
				</button>
				<button type="button" id="waisg-btn-optimize-seo-only" class="button waisg-btn" style="margin-left:8px;">
					🎯 仅优化 SEO
				</button>

				<div class="waisg-single-btns">
					<span class="waisg-label">单独优化：</span>
					<button type="button" class="button waisg-btn-single" data-field="title">标题</button>
					<button type="button" class="button waisg-btn-single" data-field="content">正文</button>
					<button type="button" class="button waisg-btn-single" data-field="excerpt">摘要</button>
					<button type="button" class="button waisg-btn-single" data-field="seo_title">SEO标题</button>
					<button type="button" class="button waisg-btn-single" data-field="seo_description">SEO描述</button>
					<button type="button" class="button waisg-btn-single" data-field="seo_keywords">SEO关键词</button>
				</div>
			</div>

			<!-- SEO 字段编辑区 -->
			<div class="waisg-section waisg-seo-section">
				<h3>🔍 SEO 字段（可直接编辑）</h3>
				<table class="waisg-table">
					<tr>
						<td><label for="waisg-seo-title">SEO 标题</label></td>
						<td>
							<input type="text" id="waisg-seo-title" class="regular-text"
								value="<?php echo esc_attr( $seo_title ); ?>"
								placeholder="Meta Title（留空则使用文章标题）" />
							<span class="waisg-char-count" id="waisg-seo-title-count">0/60</span>
						</td>
					</tr>
					<tr>
						<td><label for="waisg-seo-desc">SEO 描述</label></td>
						<td>
							<textarea id="waisg-seo-desc" class="regular-text" rows="2"
								placeholder="Meta Description（120-160字符）"><?php echo esc_textarea( $seo_desc ); ?></textarea>
							<span class="waisg-char-count" id="waisg-seo-desc-count">0/160</span>
						</td>
					</tr>
					<tr>
						<td><label for="waisg-seo-kw">SEO 关键词</label></td>
						<td>
							<input type="text" id="waisg-seo-kw" class="regular-text"
								value="<?php echo esc_attr( $seo_kw ); ?>"
								placeholder="关键词1,关键词2,关键词3" />
						</td>
					</tr>
				</table>
				<button type="button" id="waisg-btn-save-seo" class="button">
					💾 保存 SEO 字段到数据库
				</button>

				<!-- SEO 评分面板 -->
				<div id="waisg-seo-score" class="waisg-seo-score" style="display:none;margin-top:8px;">
					<span class="waisg-score-label">SEO 评分：</span>
					<span class="waisg-score-total" id="waisg-score-total">0</span><span style="color:#646970;"> / 100</span>
					&nbsp;
					<span class="waisg-score-items">
						<span class="waisg-score-item waisg-score-gray" id="waisg-score-title-len"  data-field="title"           title="标题长度（建议 20-60 字符）— 点击可让 AI 重新优化">标题</span>
						<span class="waisg-score-item waisg-score-gray" id="waisg-score-seo-t-len"  data-field="seo_title"       title="SEO标题长度（建议 30-60 字符）— 点击可让 AI 重新优化">SEO标题</span>
						<span class="waisg-score-item waisg-score-gray" id="waisg-score-seo-d-len"  data-field="seo_description" title="SEO描述长度（建议 120-160 字符）— 点击可让 AI 重新优化">SEO描述</span>
						<span class="waisg-score-item waisg-score-gray" id="waisg-score-kw-in-t"    data-field="title"           title="关键词是否出现在文章标题中 — 点击可让 AI 重新优化">关键词↑标题</span>
						<span class="waisg-score-item waisg-score-gray" id="waisg-score-kw-in-d"    data-field="seo_description" title="关键词是否出现在SEO描述中 — 点击可让 AI 重新优化">关键词↑描述</span>
					</span>
					<div style="font-size:12px; color:#666; margin-top:6px; line-height:1.5;">
						💡 <strong>绿色</strong>=可接受，<strong>黄色</strong>=需要改进，<strong>红色</strong>=建议优化。点击任意指示灯，AI 会重新优化该字段。
					</div>
				</div>
			</div>

			<!-- 状态栏 -->
			<div id="waisg-status" class="waisg-status" style="display:none;"></div>

			<!-- 暂存到待处理提示条 -->
			<div id="waisg-staging-bar" style="display:none;padding:6px 0;font-size:13px;color:#1d2327;">
				优化完成，是否要暂存到「待处理」？可在「优化历史」中稍后审阅。
				<button type="button" id="waisg-btn-stage" class="button button-small" style="margin-left:8px;">📥 暂存到待处理</button>
				<button type="button" id="waisg-btn-dismiss-stage" class="button-link" style="margin-left:8px;color:#646970;">关闭</button>
			</div>

			<!-- 内链建议 -->
			<div id="waisg-link-suggestions" style="display:none;margin-top:4px;"></div>

			<!-- 优化次数 -->
			<div class="waisg-stats">
				AI 优化次数：<strong id="waisg-opt-count"><?php echo $opt_count; ?></strong>
				<?php if ( $opt_count > 0 ) : ?>
					| <a href="<?php echo esc_url( admin_url( 'admin.php?page=waisg-history&tab=backup&post_id=' . $post->ID ) ); ?>">查看历史记录</a>
				<?php endif; ?>
			</div>

		</div>
		<?php
	}

	// =========================================================
	// AJAX 处理方法
	// =========================================================

	/** 权限检查 */
	private function check_permission() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}
	}

	/** 获取文章当前字段值 */
	private function get_post_vars( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return array();

		return array(
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => $post->post_excerpt,
			'seo_title'   => get_post_meta( $post_id, $this->get_seo_field_name( 'title' ), true ),
			'seo_desc'    => get_post_meta( $post_id, $this->get_seo_field_name( 'description' ), true ),
			'seo_kw'      => get_post_meta( $post_id, $this->get_seo_field_name( 'keywords' ), true ),
		);
	}

	/** AJAX：AI 生成文章 */
	public function ajax_generate_article() {
		$this->check_permission();

		$topic    = sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) );
		$keywords = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );
		$length   = absint( $_POST['length'] ?? 0 );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( empty( $topic ) ) {
			wp_send_json_error( array( 'message' => '请输入文章主题/要求。' ) );
		}

		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$prompts = WAISG_AI_API::build_generate_prompt( $topic, $keywords, $length, '', 'zh-CN', $template );
		$extra   = array();
		$this->apply_model_override( $extra, $model_override );
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( 0, 'generate', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$text = $result['text'];
		$data = WAISG_AI_API::parse_json_response( $text );
		if ( ! $data ) {
			WAISG_Logger::log( 0, 'generate', 'AI 返回格式异常（无法解析 JSON）' );
			wp_send_json_error( array( 'message' => 'AI 返回格式异常，请重试。原始内容：' . mb_substr( $text, 0, 200 ) ) );
		}

		wp_send_json_success( array(
			'title'           => $data['title']           ?? '',
			'content'         => $data['content']         ?? '',
			'excerpt'         => $data['excerpt']         ?? '',
			'seo_title'       => $data['seo_title']       ?? '',
			'seo_description' => $data['seo_description'] ?? '',
			'seo_keywords'    => $data['seo_keywords']    ?? '',
		) );
	}

	/** AJAX：一键优化全部 */
	public function ajax_optimize_all() {
		$this->check_permission();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效的文章 ID 或权限不足。' ) );
		}

		$template_id = absint( $_POST['template_id'] ?? 0 );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		$vars    = $this->get_post_vars( $post_id );

		// 保护原文中的图片，防止 AI 优化时丢失
		$img_protected = WAISG_AI_API::protect_images( $vars['content'] );
		$vars['content'] = $img_protected['html'];

		$prompts = WAISG_AI_API::build_optimize_all_prompt( $vars, $template );
		$extra   = WAISG_AI_API::build_long_content_extra( $vars['content'] );
		$this->apply_model_override( $extra, sanitize_key( $_POST['model_override'] ?? '' ) );
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( $post_id, 'optimize_all', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'] );
		if ( ! $data ) {
			WAISG_Logger::log( $post_id, 'optimize_all', 'AI 返回格式异常（无法解析 JSON）' );
			wp_send_json_error( array( 'message' => 'AI 返回格式异常，请重试。' ) );
		}

		// 还原图片占位符
		if ( ! empty( $data['content'] ) ) {
			$data['content'] = WAISG_AI_API::restore_images( $data['content'], $img_protected['map'] );
		}

		// 降低 AI 痕迹：先 humanize（如开启），再外层兜底 filter
		if ( ! empty( $data['content'] ) ) {
			if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$data['content'] = WAISG_AI_API::humanize( $data['content'] );
			}
			$data['content'] = WAISG_AI_API::filter_ai_phrases( $data['content'] );
		}

		// SEO 自动修复：评分不达标的字段自动修复至 ≥ 90 分
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $data['title']           ?? '',
			'seo_title' => $data['seo_title']       ?? '',
			'seo_desc'  => $data['seo_description'] ?? '',
			'seo_kw'    => $data['seo_keywords']    ?? '',
			'excerpt'   => $data['excerpt']          ?? '',
			'content'   => $data['content']          ?? '',
		), $post_id );
		$data['title']           = $fixed['title'];
		$data['seo_title']       = $fixed['seo_title'];
		$data['seo_description'] = $fixed['seo_desc'];
		$data['seo_keywords']    = $fixed['seo_kw'];
		$data['excerpt']         = $fixed['excerpt'];

		wp_send_json_success( array(
			'title'           => $data['title']           ?? '',
			'content'         => $data['content']         ?? '',
			'excerpt'         => $data['excerpt']         ?? '',
			'seo_title'       => $data['seo_title']       ?? '',
			'seo_description' => $data['seo_description'] ?? '',
			'seo_keywords'    => $data['seo_keywords']    ?? '',
		) );
	}

	/** AJAX：仅优化 SEO 字段（不处理正文，节省 Token） */
	public function ajax_optimize_seo_only() {
		$this->check_permission();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效的文章 ID 或权限不足。' ) );
		}

		$template_id = absint( $_POST['template_id'] ?? 0 );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		$vars    = $this->get_post_vars( $post_id );
		$prompts = WAISG_AI_API::build_optimize_seo_only_prompt( $vars, $template );

		// 按用户选择的模型（默认轻量，未配置则主模型）
		$extra = array( 'max_tokens' => 1024 );
		$this->apply_model_override( $extra, sanitize_key( $_POST['model_override'] ?? '' ) );

		$result = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( $post_id, 'optimize_seo_only', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'] );
		if ( ! $data ) {
			WAISG_Logger::log( $post_id, 'optimize_seo_only', 'AI 返回格式异常（无法解析 JSON）' );
			wp_send_json_error( array( 'message' => 'AI 返回格式异常，请重试。' ) );
		}

		// SEO 自动修复
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $data['title']           ?? '',
			'seo_title' => $data['seo_title']       ?? '',
			'seo_desc'  => $data['seo_description'] ?? '',
			'seo_kw'    => $data['seo_keywords']    ?? '',
			'excerpt'   => $data['excerpt']          ?? '',
			'content'   => $vars['content']          ?? '',
		), $post_id );
		$data['title']           = $fixed['title'];
		$data['seo_title']       = $fixed['seo_title'];
		$data['seo_description'] = $fixed['seo_desc'];
		$data['seo_keywords']    = $fixed['seo_kw'];
		$data['excerpt']         = $fixed['excerpt'];

		wp_send_json_success( array(
			'title'           => $data['title']           ?? '',
			'excerpt'         => $data['excerpt']         ?? '',
			'seo_title'       => $data['seo_title']       ?? '',
			'seo_description' => $data['seo_description'] ?? '',
			'seo_keywords'    => $data['seo_keywords']    ?? '',
		) );
	}

	/** AJAX：单字段优化 */
	public function ajax_optimize_single() {
		$this->check_permission();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$field   = sanitize_key( $_POST['field'] ?? '' );

		$allowed = array( 'title', 'content', 'excerpt', 'seo_title', 'seo_description', 'seo_keywords' );
		if ( ! in_array( $field, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => '无效的字段名。' ) );
		}
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效的文章 ID 或权限不足。' ) );
		}

		$vars = $this->get_post_vars( $post_id );
		$vars['title']   = sanitize_text_field( wp_unslash( $_POST['current_title']   ?? $vars['title'] ) );
		$vars['excerpt'] = sanitize_textarea_field( wp_unslash( $_POST['current_excerpt'] ?? $vars['excerpt'] ) );
		$vars['seo_title']   = sanitize_text_field( wp_unslash( $_POST['current_seo_title']   ?? $vars['seo_title'] ) );
		$vars['seo_desc']    = sanitize_textarea_field( wp_unslash( $_POST['current_seo_desc']    ?? $vars['seo_desc'] ) );
		$vars['seo_kw']      = sanitize_text_field( wp_unslash( $_POST['current_seo_kw']      ?? $vars['seo_kw'] ) );

		$field_var_map = array(
			'title'           => 'title',
			'content'         => 'content',
			'excerpt'         => 'excerpt',
			'seo_title'       => 'seo_title',
			'seo_description' => 'seo_desc',
			'seo_keywords'    => 'seo_kw',
		);
		$var_key = $field_var_map[ $field ];
		$vars[ $var_key ] = sanitize_textarea_field( wp_unslash( $_POST['current_value'] ?? $vars[ $var_key ] ) );

		// 快速预检：如果字段长度已合格且关键词已存在，提示用户无需再调用AI
		$skip_reason = $this->quick_seo_check( $field, $vars );
		if ( $skip_reason === true ) {
			wp_send_json_error( array( 'message' => '该字段当前已达标（长度合格、关键词已包含），无需再次消耗 Token 优化。如仍需改进表达，请手动编辑。' ) );
		}

		// 正文字段：保护图片，防止 AI 优化时丢失
		$img_protected_single = array( 'map' => array() );
		if ( $field === 'content' ) {
			$img_protected_single = WAISG_AI_API::protect_images( $vars['content'] );
			$vars['content'] = $img_protected_single['html'];
		}

		$prompts = WAISG_AI_API::build_single_field_prompt( $field, $vars );

		// 正文字段根据内容长度动态调整 timeout/max_tokens；模型按用户选择（默认轻量，未配置则主模型）
		$extra = array();
		if ( $field === 'content' ) {
			$extra = WAISG_AI_API::build_long_content_extra( $vars['content'] );
		}
		$this->apply_model_override( $extra, sanitize_key( $_POST['model_override'] ?? '' ) );

		$result = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( $post_id, 'optimize_single', $result->get_error_message() );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$value = $result['text'];

		// 正文字段：降低 AI 痕迹（先 humanize 如开启，再外层兜底 filter）
		if ( $field === 'content' && ! empty( $value ) ) {
			$value = WAISG_AI_API::restore_images( $value, $img_protected_single['map'] );
			if ( WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$value = WAISG_AI_API::humanize( $value );
			}
			$value = WAISG_AI_API::filter_ai_phrases( $value );
		}

		wp_send_json_success( array(
			'field' => $field,
			'value' => $value,
		) );
	}

	/**
	 * 快速 SEO 预检：判断字段是否已达标（避免不必要的 Token 消耗）
	 *
	 * @param string $field 字段名
	 * @param array  $vars  当前字段值
	 * @return true|string true=已达标可跳过，string=未达标原因
	 */
	private function quick_seo_check( $field, $vars ) {
		$kw = ! empty( $vars['seo_kw'] ) ? trim( explode( ',', $vars['seo_kw'] )[0] ) : '';
		$kw_lc = $kw ? mb_strtolower( $kw, 'UTF-8' ) : '';

		switch ( $field ) {
			case 'title':
				$len = mb_strlen( $vars['title'], 'UTF-8' );
				$in_range = ( $len >= 20 && $len <= 60 );
				$has_kw   = ( ! $kw_lc || mb_stripos( $vars['title'], $kw_lc ) !== false );
				return ( $in_range && $has_kw ) ? true : 'not_pass';

			case 'seo_title':
				$len = mb_strlen( $vars['seo_title'], 'UTF-8' );
				$in_range = ( $len >= 30 && $len <= 60 );
				$has_kw   = ( ! $kw_lc || mb_stripos( $vars['seo_title'], $kw_lc ) !== false );
				return ( $in_range && $has_kw ) ? true : 'not_pass';

			case 'seo_description':
				$len = mb_strlen( $vars['seo_desc'], 'UTF-8' );
				$in_range = ( $len >= 120 && $len <= 160 );
				$has_kw   = ( ! $kw_lc || mb_stripos( $vars['seo_desc'], $kw_lc ) !== false );
				return ( $in_range && $has_kw ) ? true : 'not_pass';

			default:
				// content, excerpt, seo_keywords 不做预检
				return 'not_pass';
		}
	}

	/** AJAX：保存 SEO 字段到数据库并记录优化次数 */
	public function ajax_save_result() {
		$this->check_permission();

		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$save_type = sanitize_key( $_POST['save_type'] ?? 'seo' ); // seo | count

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效的文章 ID 或权限不足。' ) );
		}

		if ( $save_type === 'seo' ) {
			$seo_title = sanitize_text_field( wp_unslash( $_POST['seo_title'] ?? '' ) );
			$seo_desc  = sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ?? '' ) );
			$seo_kw    = sanitize_text_field( wp_unslash( $_POST['seo_kw'] ?? '' ) );

			// 只使用用户明确配置的字段，不自动检测
			$title_key = WAISG_Settings::get( 'seo_title_field' );
			$desc_key  = WAISG_Settings::get( 'seo_description_field' );
			$kw_key    = WAISG_Settings::get( 'seo_keywords_field' );

			$skipped = array();
			$saved = array();

			// 只保存已配置的字段
			if ( ! empty( $title_key ) && ! empty( $seo_title ) ) {
				update_post_meta( $post_id, $title_key, $seo_title );
				$saved[] = 'SEO标题';
			} elseif ( empty( $title_key ) && ! empty( $seo_title ) ) {
				$skipped[] = 'SEO标题（未在基本设置中配置）';
			}

			if ( ! empty( $desc_key ) && ! empty( $seo_desc ) ) {
				update_post_meta( $post_id, $desc_key, $seo_desc );
				$saved[] = 'SEO描述';
			} elseif ( empty( $desc_key ) && ! empty( $seo_desc ) ) {
				$skipped[] = 'SEO描述（未在基本设置中配置）';
			}

			if ( ! empty( $kw_key ) && ! empty( $seo_kw ) ) {
				update_post_meta( $post_id, $kw_key, $seo_kw );
				$saved[] = 'SEO关键词';
			} elseif ( empty( $kw_key ) && ! empty( $seo_kw ) ) {
				$skipped[] = 'SEO关键词（未在基本设置中配置）';
			}

			$message = '';
			if ( ! empty( $saved ) ) {
				$message = '已保存：' . implode('、', $saved) . '。';
			}
			if ( ! empty( $skipped ) ) {
				$message .= ' 已跳过：' . implode('、', $skipped) . '。请在基本设置中配置对应字段。';
			}

			if ( empty( $saved ) && ! empty( $skipped ) ) {
				wp_send_json_error( array( 'message' => $message ?: '没有可保存的字段。' ) );
			}

			wp_send_json_success( array(
				'message' => $message ?: '所有 SEO 字段已保存。',
				'data' => array(
					'title_key' => $title_key,
					'desc_key'  => $desc_key,
					'kw_key'    => $kw_key,
				)
			) );
		}

		wp_send_json_error( array( 'message' => '无效操作。' ) );
	}


	/** AJAX：获取文章当前优化次数（Gutenberg 保存后不刷新页面，通过此接口实时更新次数显示） */
	public function ajax_get_opt_count() {
		$this->check_permission();
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error();

		$count = (int) get_post_meta( $post_id, '_waisg_opt_count', true );
		wp_send_json_success( array( 'count' => $count ) );
	}

	/** AJAX：从编辑器暂存优化结果到待处理 */
	public function ajax_stage_from_editor() {
		$this->check_permission();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '无效的文章 ID 或权限不足。' ) );
		}

		$post = get_post( $post_id );
		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'optimized',
			'post_id'      => $post_id,
			'post_title'   => sanitize_text_field( wp_unslash( $_POST['post_title']   ?? ( $post ? $post->post_title : '' ) ) ),
			'post_content' => WAISG_AI_API::sanitize_content( wp_unslash( $_POST['post_content'] ?? '' ) ),
			'post_excerpt' => sanitize_textarea_field( wp_unslash( $_POST['post_excerpt'] ?? '' ) ),
			'seo_title'    => sanitize_text_field( wp_unslash( $_POST['seo_title'] ?? '' ) ),
			'seo_desc'     => sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ?? '' ) ),
			'seo_kw'       => sanitize_text_field( wp_unslash( $_POST['seo_kw'] ?? '' ) ),
		) );

		if ( $history_id ) {
			wp_send_json_success( array( 'history_id' => $history_id ) );
		} else {
			wp_send_json_error( array( 'message' => '暂存失败，请重试。' ) );
		}
	}

	/** AJAX：内链建议 */
	public function ajax_suggest_links() {
		$this->check_permission();

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$keywords = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );

		if ( empty( $keywords ) ) {
			wp_send_json_success( array( 'links' => array() ) );
		}

		// 取第一个关键词搜索
		$parts = explode( ',', $keywords );
		$kw    = trim( $parts[0] );

		$query = new WP_Query( array(
			's'              => $kw,
			'post_type'      => WAISG_Settings::get_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'post__not_in'   => $post_id ? array( $post_id ) : array(),
			'fields'         => 'ids',
		) );

		$links = array();
		foreach ( $query->posts as $id ) {
			$links[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
			);
		}

		wp_send_json_success( array( 'links' => $links ) );
	}

	// =========================================================
	// 辅助方法
	// =========================================================

	/**
	 * 获取 SEO 字段 meta key
	 * 优先使用用户自定义配置，否则自动检测已安装的 SEO 插件
	 */
	public function get_seo_field_name( $type ) {
		$custom = array(
			'title'       => WAISG_Settings::get( 'seo_title_field' ),
			'description' => WAISG_Settings::get( 'seo_description_field' ),
			'keywords'    => WAISG_Settings::get( 'seo_keywords_field' ),
		);

		if ( ! empty( $custom[ $type ] ) ) {
			return $custom[ $type ];
		}

		// 自动检测
		$defaults = array(
			'title' => array(
				'_yoast_wpseo_title',    // Yoast SEO
				'rank_math_title',        // RankMath
				'_aioseo_title',          // AIOSEO
				'_seopress_titles_title', // SEOPress
			),
			'description' => array(
				'_yoast_wpseo_metadesc',       // Yoast SEO
				'rank_math_description',        // RankMath
				'_aioseo_description',          // AIOSEO
				'_seopress_titles_desc',        // SEOPress
			),
			'keywords' => array(
				'_yoast_wpseo_focuskw',         // Yoast SEO
				'rank_math_focus_keyword',       // RankMath
				'_aioseo_keywords',              // AIOSEO
				'_seopress_analysis_target_kw',  // SEOPress
			),
		);

		// 检测哪个 SEO 插件激活
		if ( defined( 'WPSEO_VERSION' ) ) {
			return $defaults[ $type ][0]; // Yoast
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return $defaults[ $type ][1]; // RankMath
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return $defaults[ $type ][2]; // AIOSEO
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return $defaults[ $type ][3]; // SEOPress
		}

		// 默认用 Yoast 字段名
		return $defaults[ $type ][0];
	}

	/**
	 * 渲染「使用模型」下拉框（主元框 + 侧边栏共用，两处通过 class 联动同步）
	 * 默认值：已配置轻量模型则默认选轻量（更省钱），否则只有主模型可选
	 *
	 * @param string $id    select 元素 id
	 * @param string $style 额外内联样式（侧边栏用 width:100%）
	 * @return string HTML
	 */
	private function model_select_html( $id, $style = '' ) {
		$lm_name   = WAISG_Settings::get( 'lightweight_model', '' );
		$main_name = WAISG_Settings::get( 'model', 'gpt-4o' );
		$default   = $lm_name ? 'lightweight' : 'main';
		ob_start();
		?>
		<select id="<?php echo esc_attr( $id ); ?>" class="waisg-model-override"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
			<option value="main" <?php selected( $default, 'main' ); ?>>主模型（<?php echo esc_html( $main_name ); ?>）</option>
			<?php if ( $lm_name ) : ?>
			<option value="lightweight" <?php selected( $default, 'lightweight' ); ?>>轻量模型（<?php echo esc_html( $lm_name ); ?>）</option>
			<?php endif; ?>
		</select>
		<?php
		return ob_get_clean();
	}

	/**
	 * 根据前端「使用模型」选择，设置 call_prompts 的 $extra['model']（按引用修改）
	 * - lightweight：用轻量模型（未配置则回退主模型，即不设 model）
	 * - main：用主模型（不设 model，call() 默认即主模型）
	 * - 空：回退默认（配置了轻量则用轻量，否则主模型）——与下拉框默认值一致
	 *
	 * @param array  $extra          按引用传入的 extra 数组
	 * @param string $model_override 'main' | 'lightweight' | ''
	 */
	private function apply_model_override( array &$extra, $model_override ) {
		$lm  = WAISG_Settings::get( 'lightweight_model', '' );
		$use = $model_override ? $model_override : ( $lm ? 'lightweight' : 'main' );
		if ( $use === 'lightweight' && $lm ) {
			$extra['model'] = $lm;
		}
		// main 或未配置轻量模型：不设 model，call() 使用主模型
	}
}
