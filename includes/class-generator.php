<?php
/**
 * 批量生成图文文章
 * - 每次 AJAX 请求生成一篇（前端轮流调用，N 篇就调 N 次）
 * - 生成结果存入暂存区（history 表），不直接写入 WordPress
 * - 由用户在结果列表中审阅/编辑后，手动选择状态并保存到 WordPress
 * - 可选自动从 Pexels / Unsplash / AI 接口获取图片并插入正文
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_Generator {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_waisg_gen_article',         array( $this, 'ajax_gen_article' ) );
		add_action( 'wp_ajax_waisg_gen_fetch_images',    array( $this, 'ajax_fetch_images' ) );
		add_action( 'wp_ajax_waisg_update_post_status',  array( $this, 'ajax_update_post_status' ) );
		add_action( 'wp_ajax_waisg_rewrite_article',     array( $this, 'ajax_rewrite_article' ) );
	}

	/** 加载脚本 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'waisg-generator' ) === false ) return;

		wp_enqueue_style( 'waisg-admin', WAISG_URL . 'assets/css/admin.css', array(), WAISG_VERSION );
		wp_enqueue_script(
			'waisg-generator',
			WAISG_URL . 'assets/js/generator.js',
			array( 'jquery' ),
			WAISG_VERSION,
			true
		);
		wp_localize_script( 'waisg-generator', 'waisgGen', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'waisg_nonce' ),
		) );
	}

	// =========================================================
	// AJAX：生成单篇文章
	// =========================================================

	/**
	 * 生成一篇文章并存入暂存区（不直接写入 WordPress）
	 * POST 参数：
	 *   topic       主题/要求（可选，不填则以 keywords 为主题）
	 *   keywords    核心关键词（必填）
	 *   description 补充说明（可选）
	 *   length      字数要求（0=不限）
	 *   post_type   文章类型（post/page/...）
	 *   category_id 分类 ID（post 类型有效）
	 *   index       当前第几篇（用于前端显示进度）
	 *   total       总篇数（用于前端显示进度）
	 */
	public function ajax_gen_article() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$topic       = sanitize_text_field( wp_unslash( $_POST['topic']       ?? '' ) );
		$keywords    = sanitize_text_field( wp_unslash( $_POST['keywords']    ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$language    = sanitize_text_field( wp_unslash( $_POST['language']    ?? 'zh-CN' ) );
		$length      = absint( $_POST['length']   ?? 0 );
		$post_type   = sanitize_key( $_POST['post_type']   ?? 'post' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$index       = absint( $_POST['index'] ?? 1 );
		$total       = absint( $_POST['total'] ?? 1 );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$skip_humanize  = ! empty( $_POST['skip_humanize'] );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( empty( $keywords ) && empty( $topic ) ) {
			wp_send_json_error( array( 'message' => '关键词不能为空。' ) );
		}
		if ( empty( $topic ) ) {
			$topic = $keywords;
		}

		$allowed_types = WAISG_Settings::get_post_types();
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'post';
		}

		// 生成多篇时，加入差异化指令，避免 AI 输出相同标题和结构
		if ( $total > 1 ) {
			$diff_note   = "注意：这是同主题系列文章的第 {$index}/{$total} 篇，必须采用与其他篇完全不同的切入角度和标题，内容结构也要有明显区别。";
			$description = $description ? $description . "\n" . $diff_note : $diff_note;
		}

		$prompts = WAISG_AI_API::build_generate_prompt( $topic, $keywords, $length, $description, $language, $template );
		// 按目标字数动态调整 max_tokens 和 timeout（避免长文被默认 max_tokens 截断）
		$extra   = $length > 0 ? WAISG_AI_API::build_long_content_extra( str_repeat( '中', $length ) ) : array();
		// 前端传参 > 全局设置：确定使用的模型
		$use_model = $model_override ? $model_override : WAISG_Settings::get( 'batch_model', 'main' );
		if ( $use_model === 'lightweight' ) {
			$lm = WAISG_Settings::get( 'lightweight_model', '' );
			if ( $lm ) {
				$extra['model'] = $lm;
			}
		}
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( 0, 'generate', $result->get_error_message(), '第 ' . $index . ' 篇' );
			wp_send_json_error( array(
				'message' => '第 ' . $index . ' 篇 AI 请求失败：' . wp_strip_all_tags( $result->get_error_message() ),
				'index'   => $index,
			) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'], 'generate', 0 );
		if ( ! $data ) {
			WAISG_Logger::log( 0, 'generate', 'AI 返回格式异常（无法解析 JSON）', '第 ' . $index . ' 篇 ｜AI返回：' . mb_substr( $result['text'], 0, 300, 'UTF-8' ) );
			wp_send_json_error( array(
				'message' => '第 ' . $index . ' 篇 AI 返回格式异常，请重试。',
				'index'   => $index,
			) );
		}

		$post_title   = sanitize_text_field( $data['title']   ?? $topic );
		$post_content = WAISG_AI_API::sanitize_content( $data['content']  ?? '' );
		$post_excerpt = sanitize_textarea_field( $data['excerpt'] ?? '' );
		$seo_title    = sanitize_text_field( $data['seo_title']       ?? '' );
		$seo_desc     = sanitize_textarea_field( $data['seo_description'] ?? '' );
		$seo_kw       = sanitize_text_field( $data['seo_keywords']    ?? '' );

		// 降低 AI 痕迹：先二次润色（如开启且未跳过），再外层兜底替换 AI 高频词
		if ( ! empty( $post_content ) ) {
			if ( ! $skip_humanize && WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$post_content = WAISG_AI_API::humanize( $post_content, $use_model );
			}
			$post_content = WAISG_AI_API::filter_ai_phrases( $post_content );
		}

		// 如果需要插入图片，先获取图片并插入正文
		$image_source = WAISG_Settings::get( 'image_source', 'none' );
		$image_note  = ''; // 配图结果诊断（成功为空，失败带原因）
		if ( $image_source !== 'none' ) {
			$image_keyword = ! empty( $keywords ) ? $keywords : $topic;
			// 透传文章语言给配图逻辑——AI 生成图按语言切换 prompt 模板，避免中英混杂降低模型理解
			$post_language = sanitize_text_field( wp_unslash( $_POST['language'] ?? 'zh-CN' ) );
			$before_len    = strlen( $post_content );
			$post_content  = $this->insert_images_into_content( $post_content, $image_keyword, $post_language );
			// 若正文长度没变 = 一张图都没插进去——诊断原因给用户
			if ( strlen( $post_content ) === $before_len ) {
				$probe = $this->fetch_images( $image_keyword, 1, array(), $post_language );
				if ( is_wp_error( $probe ) ) {
					$image_note = '配图失败：' . wp_strip_all_tags( $probe->get_error_message() );
				} else {
					$image_note = '配图失败：API 正常但未返回图片（可能关键词太冷门或额度耗尽）';
				}
				WAISG_Logger::log( 0, 'generate', $image_note, '关键词：' . $image_keyword . ' ｜来源：' . $image_source );
			}
		}

		// SEO 本地修复（零 Token）
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $post_title,
			'seo_title' => $seo_title,
			'seo_desc'  => $seo_desc,
			'seo_kw'    => $seo_kw,
			'excerpt'   => $post_excerpt,
			'content'   => $post_content,
		) );
		$post_title   = $fixed['title'];
		$seo_title    = $fixed['seo_title'];
		$seo_desc     = $fixed['seo_desc'];
		$seo_kw       = $fixed['seo_kw'];
		$post_excerpt = $fixed['excerpt'];

		// 存入暂存区（history 表），不写入 WordPress
		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'generated',
			'post_id'      => 0,
			'keyword'      => $keywords,
			'post_type'    => $post_type,
			'category_id'  => $category_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
		) );

		wp_send_json_success( array(
			'index'        => $index,
			'total'        => $total,
			'history_id'   => $history_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
			'recovered'    => $data['_recovered'] ?? '',
			'image_note'   => $image_note,
		) );
	}

	// =========================================================
	// 图片插入
	// =========================================================

	/**
	 * 将图片 HTML 插入到文章正文中
	 * 策略：在每个 <h2> 或 <h3> 标题后面插入一张图片
	 *
	 * @param string $content  原始正文 HTML
	 * @param string $keyword  搜图关键词
	 * @return string 插入图片后的正文
	 */
	/**
	 * 在正文 <h2>/<h3> 处插入图片。
	 *
	 * 节级关键词优化：第 2+ 张图按所在小节的标题文本搜/生成，不再全文共用一个关键词——
	 * 每张图都贴合所在小节的主题，配图匹配度大幅提升。
	 * 第 1 张图仍用总关键词（文章开头配总图，统领全文主题）。
	 *
	 * @param string $content  原始正文 HTML
	 * @param string $keyword  总关键词（第 1 张图用）
	 * @param string $language 文章语言（zh-CN/en-US 等），透传给 AI 生成图按语言切换 prompt 模板
	 * @return string 插入图片后的正文
	 */
	private function insert_images_into_content( $content, $keyword, $language = 'zh-CN' ) {
		$images_per_post = (int) WAISG_Settings::get( 'images_per_post', 2 );
		if ( $images_per_post < 1 ) return $content;

		// 区分图源（v1.9.9）：AI 生图用原始中文标题直接做 prompt（AI 懂多语言，贴合主题具体场景），
		// Pexels/Unsplash 实景库才调 extract_and_translate 翻译成简洁英文搜图词（实景库要简洁名词才搜得到）。
		// 旧版不区分——翻译后简洁英文词喂给 AI 生图致主题具体场景丢失，画出来的图不贴合。
		$img_source = WAISG_Settings::get( 'image_source', 'none' );
		$is_ai_img  = ( $img_source === 'ai_image' );

		// AI 生图时用总关键词识别题材一次，透传给各张图——避免节标题不含题材特征词时
		// （如游戏攻略的"矿场南侧山腰房"不含游戏词）误回退通用扁平插画风。
		$img_category = '';
		if ( $is_ai_img ) {
			$img_category = self::image_style_hint( $keyword )['category'] ?? '';
		}

		// 先取各 <h2>/<h3> 小节的标题文本，作为节级关键词候选
		$section_titles = array();
		if ( preg_match_all( '#<h[23][^>]*>(.*?)</h[23]>#i', $content, $m ) ) {
			foreach ( $m[1] as $heading_html ) {
				// 剥 HTML 标签得到纯文本标题（标题里可能嵌套 <span>/<a> 等）
				$title = trim( wp_strip_all_tags( $heading_html ) );
				if ( $title !== '' ) $section_titles[] = $title;
			}
		}

		$inserted = 0;

		// 第 1 张：用总关键词，插到正文最开头
		// AI 生图用原始总关键词直接做 prompt；实景库用翻译后简洁英文搜图词
		$first_kw = $is_ai_img ? $keyword : self::extract_and_translate( $keyword, $language );
		$first_images = $this->fetch_images( $first_kw, 1, array(), $language, $img_category );
		if ( ! is_wp_error( $first_images ) && ! empty( $first_images ) ) {
			$first_img = $first_images[0];
			// Alt 用原始总关键词（中文文章配中文 Alt），不用翻译后英文搜图词也不用 Pexels/Unsplash 自带的英文 alt_description
			$content = sprintf(
				'<figure class="waisg-gen-image"><img src="%s" alt="%s" style="max-width:100%%;height:auto;" /></figure>',
				esc_url( $first_img['url'] ),
				esc_attr( $keyword )
			) . "\n" . $content;
			$inserted++;
		}

		// 后续张：按第 N 个小节标题搜/生成，插到该 <h2>/<h3> 之后
		// $inserted 跟已插入张数，$section_idx 跟取到哪一节的标题——两值独立，
		// 节级搜图失败时 $inserted 仍 ++（占配额），但 $section_idx 也 ++ 跳下一节，
		// 避免错位取标题；节标题用尽回退总关键词。
		$section_idx = 1; // 第 0 节留给第 1 张总图用过了
		while ( $inserted < $images_per_post ) {
			$raw_title = isset( $section_titles[ $section_idx ] ) ? $section_titles[ $section_idx ] : $keyword;
			$section_idx++;
			// AI 生图用原始中文节标题直接做 prompt（贴合该节具体场景）；
			// 实景库才调 extract_and_translate 产简洁英文搜图词。失败回退总关键词。
			$section_kw = $is_ai_img ? $raw_title : self::extract_and_translate( $raw_title, $language );
			$more_images = $this->fetch_images( $section_kw, 1, array(), $language, $img_category );
			if ( is_wp_error( $more_images ) || empty( $more_images ) ) {
				// 节级搜图失败不应阻断——跳过这张继续下一节
				$inserted++;
				continue;
			}
			$img = $more_images[0];
			// Alt 用节级原始中文标题（贴合该节主题），不用翻译后英文搜图词也不用 Pexels/Unsplash 自带的英文 alt_description
			$img_html = sprintf(
				'<figure class="waisg-gen-image"><img src="%s" alt="%s" style="max-width:100%%;height:auto;" /></figure>',
				esc_url( $img['url'] ),
				esc_attr( $raw_title )
			);
			// 插到第 (inserted) 个 <h2>/<h3> 之后——与旧行为一致的位置规则
			$content = $this->inject_after_heading( $content, $img_html, $inserted );
			$inserted++;
		}

		return $content;
	}

	/**
	 * 把 $inject 内容插入到第 $n 个 h2/h3 标签之后
	 */
	private function inject_after_heading( $content, $inject, $n ) {
		$count = 0;
		$result = preg_replace_callback(
			'#(<\/h[23]>)#i',
			function( $matches ) use ( $inject, $n, &$count ) {
				$count++;
				if ( $count === $n ) {
					return $matches[1] . "\n" . $inject;
				}
				return $matches[1];
			},
			$content
		);
		// 如果没有足够的标题，直接追加到末尾
		return ( $count >= $n ) ? $result : ( $content . "\n" . $inject );
	}

	/**
	 * 从 Pexels 或 Unsplash 获取图片列表
	 *
	 * @param string $keyword
	 * @param int    $count
	 * @return array [ ['url'=>'...', 'alt'=>'...'], ... ]
	 */
	/**
	 * 把搜图关键词翻成英文（Pexels/Unsplash 是英文索引库，中文关键词搜不出对图）。
	 * 调 AI 大模型轻量翻译，失败或已是英文则原样返回——不阻断配图。
	 */
	/**
	 * 一步从节级标题/中文关键词产出最适合搜图的英文关键词（提取核心名词 + 翻译合并）。
	 * 减少中间精度损失——比起"先提取再翻译"两步，一步让 AI 理解整句语义产出更准的搜图词。
	 * 失败回退原标题。非中文语言直接返回原词（英文文章用英文关键词搜英文库本来就对）。
	 */
	private static function extract_and_translate( $text, $language = 'zh-CN' ) {
		// 非中文语言直接返回，不翻译
		if ( strpos( $language, 'zh' ) !== 0 ) return $text;
		// 纯 ASCII 视为已是英文，省一次 API 调用
		if ( preg_match( '/^[\x20-\x7E]+$/', $text ) ) return $text;
		$light_model = WAISG_Settings::get( 'lightweight_model', '' );
		// max_tokens 走设置项（v1.9.9）：推理模型思考会吃配额，旧版写死 200 导致返空触发重试
		$kw_max = (int) WAISG_Settings::get( 'image_keyword_max_tokens', 200 );
		$extra = array( 'max_tokens' => $kw_max, 'temperature' => 0.1 );
		if ( $light_model !== '' ) $extra['model'] = $light_model;
		$resp = WAISG_AI_API::call(
			'You are finding the best IMAGE SEARCH keyword for a stock photo site (Pexels/Unsplash). '
			. 'Given a Chinese section title or keyword, output 1-3 English keywords that will find the most RELEVANT photo. '
			. 'Understand Chinese internet slang / gaming jargon — e.g. "吃鸡" means "battle royale game" (NOT "chicken dinner"), '
			. '"种草" means "product recommendation", "拔草" means "product review". '
			. 'Extract the MAIN SUBJECT and translate to English. Return ONLY the English keyword(s), nothing else. '
			. 'Input: ' . $text,
			'You output image search keywords only.',
			$extra
		);
		if ( is_wp_error( $resp ) ) return $text; // 失败回退原标题
		$en = trim( $resp['text'] ?? '' );
		$en = trim( $en, "\"'.,;!?。．，；！？" );
		return $en !== '' ? $en : $text;
	}

	/**
	 * 从节级标题（一整句话）里提取 1-3 个核心名词作搜图关键词。
	 * 节级标题如"为什么吃鸡还没凉？咱们看数据说话"直接当搜图词搜不准，
	 * 提取出核心主题"吃鸡"再搜才能配对图。调 AI 轻量模型，失败回退原标题。
	 *
	 * 已被 extract_and_translate() 取代（一步提取+翻译合并减少精度损失）——
	 * 此方法保留供未来单独调用场景，暂无调用方。
	 * @deprecated 3.0.0 使用 extract_and_translate() 替代
	 */
	private static function extract_image_keyword( $section_title, $language = 'zh-CN' ) {
		// 短标题（≤8 字符）直接当搜图词，省一次 API 调用
		if ( mb_strlen( $section_title, 'UTF-8' ) <= 8 ) return $section_title;
		$light_model = WAISG_Settings::get( 'lightweight_model', '' );
		$extra = array( 'max_tokens' => 100, 'temperature' => 0.1 );
		if ( $light_model !== '' ) $extra['model'] = $light_model;
		$prompt = 'Extract 1-3 core nouns (the main subject) from the following section title for IMAGE SEARCH. '
			. 'Return ONLY the extracted keywords, nothing else. '
			. 'Understand Chinese context — e.g. from "为什么吃鸡还没凉" extract "吃鸡" (the game genre). '
			. 'Title: ' . $section_title;
		$resp = WAISG_AI_API::call( $prompt, 'You extract image search keywords. Output only the keywords.', $extra );
		if ( is_wp_error( $resp ) ) return $section_title; // 失败回退原标题
		$kw = trim( $resp['text'] ?? '' );
		$kw = trim( $kw, "\"'.,;!?。．，；！？" );
		return $kw !== '' ? $kw : $section_title;
	}

	/**
	 * 把搜图关键词翻成英文（Pexels/Unsplash 是英文索引库，中文关键词搜不出对图）。
	 *
	 * 已被 extract_and_translate() 取代（一步提取+翻译合并减少精度损失）——
	 * 此方法保留供未来单独调用场景，暂无调用方。
	 * @deprecated 3.0.0 使用 extract_and_translate() 替代
	 */
	private static function translate_keyword_for_stock( $keyword, $language = 'zh-CN' ) {
		// 按输出语言判：非中文语言直接返回，不翻译（英文文章用英文关键词搜英文库本来就对）
		if ( strpos( $language, 'zh' ) !== 0 ) return $keyword;
		// 简判：纯 ASCII 视为已是英文（如中文文章但关键词恰好是英文词 PUBG），直接返回省一次 API 调用
		if ( preg_match( '/^[\x20-\x7E]+$/', $keyword ) ) return $keyword;
		// 翻译用轻量模型（非推理），不走主推理模型——推理模型会把 max_tokens 全用于思考导致返空
		$light_model = WAISG_Settings::get( 'lightweight_model', '' );
		// max_tokens 走设置项（v1.9.9）：推理模型思考会吃配额，旧版写死 200 导致返空触发重试
		$kw_max = (int) WAISG_Settings::get( 'image_keyword_max_tokens', 200 );
		$extra = array(
			'max_tokens'  => $kw_max,    // 翻译输出很短，但留够余量避免偶发截断
			'temperature' => 0.1,    // 翻译要稳定不要发散
		);
		if ( $light_model !== '' ) {
			$extra['model'] = $light_model;
		}
		$resp = WAISG_AI_API::call(
			'Translate the following Chinese keyword into English for IMAGE SEARCH on English stock photo sites (Pexels/Unsplash). '
			. 'Understand Chinese internet slang / gaming jargon — e.g. "吃鸡" means "battle royale game" (NOT "chicken dinner"), '
			. '"种草" means "product recommendation", "拔草" means "product review". '
			. 'Return ONLY the English keyword(s) for image search, nothing else. Keyword: ' . $keyword,
			'You are a translation API for image search keywords. Output only the translated English keyword.',
			$extra
		);
		if ( is_wp_error( $resp ) ) return $keyword; // 翻译失败不阻断，用原中文搜（Pexels 也能搜部分中文）
		$en = trim( $resp['text'] ?? '' );
		// 剥可能的引号/句末标点，只留关键词本身
		$en = trim( $en, "\"'.,;!?。．，；！？" );
		return $en !== '' ? $en : $keyword;
	}

	/**
	 * 获取图片。
	 * @param string $keyword  搜图关键词
	 * @param int    $count    每次获取张数
	 * @param array  $override 表单即时值（测试搜图按钮用，绕过数据库）
	 * @param string $language 文章语言（zh-CN/en-US 等），透传给 AI 生成图按语言切换 prompt 模板
	 * @return array|WP_Error  图片数组，或 WP_Error（API 拒绝/格式异常/未配置）
	 */
	public function fetch_images( $keyword, $count = 2, $override = array(), $language = 'zh-CN', $category_override = '' ) {
		$source = $override['image_source'] ?? WAISG_Settings::get( 'image_source', 'none' );

		if ( $source === 'none' ) return new WP_Error( 'no_source', '未选择图片来源，请在设置中先选 Pexels / Unsplash / AI 大模型生成。' );

		if ( $source === 'ai_image' ) {
			// 题材类别透传给 fetch_ai_images（节标题不含题材特征词时用总关键词识别的类别兜底）
			return $this->fetch_ai_images( $keyword, $count, $override, $language, $category_override );
		}

		// Pexels / Unsplash 各自独立的 Key 字段——按当前来源取对应那个，回退兼容旧共用 image_api_key 字段
		$api_key = '';
		if ( $source === 'pexels' ) {
			$api_key = $override['image_api_key_pexels'] ?? WAISG_Settings::get( 'image_api_key_pexels', '' );
		} elseif ( $source === 'unsplash' ) {
			$api_key = $override['image_api_key_unsplash'] ?? WAISG_Settings::get( 'image_api_key_unsplash', '' );
		}
		// 兼容旧用户：新字段为空时回退旧共用字段（迁移前数据）
		if ( empty( $api_key ) ) {
			$api_key = WAISG_Settings::get( 'image_api_key', '' );
		}
		if ( empty( $api_key ) ) return new WP_Error( 'no_key', '未配置图片 API Key。' );

		if ( $source === 'pexels' ) {
			// Pexels/Unsplash 是英文索引库，中文关键词搜不出对图——一步调 AI 产出最适合搜图的英文关键词
			// （提取核心名词+翻译合并，减少中间精度损失）。按输出语言判，中文才调，英文/其他直接用原词。
			$en_kw = self::extract_and_translate( $keyword, $language );
			return $this->fetch_pexels( $en_kw, $count, $api_key );
		}
		if ( $source === 'unsplash' ) {
			$en_kw = self::extract_and_translate( $keyword, $language );
			return $this->fetch_unsplash( $en_kw, $count, $api_key );
		}

		return new WP_Error( 'bad_source', '未知的图片来源：' . $source );
	}

	/**
	 * 调用 AI 大模型接口生成图片（兼容 OpenAI /v1/images/generations）
	 * @return array|WP_Error  图片数组（含 url/alt），或 WP_Error（API 拒绝/格式异常）
	 */
	/**
	 * 按关键词题材分派视觉风格指令（v1.9.9 新增）。
	 *
	 * 提升AI生成图与文章主题的贴合度——旧版笼统的"一张高质量插画"对题材描述不足，
	 * 模型产出偏抽象通用图。改用关键词匹配分派题材类别，每类给具体的视觉风格指令
	 * （视角/元素/画风/配色），让模型产出贴合主题的图。题材分类是有限枚举可硬编码，
	 * 零API调用，比让AI再识别题材更省。
	 *
	 * @param string $keyword 已经是搜图用的英文/中文关键词
	 * @return array {'zh'=>中文风格指令, 'en'=>英文风格指令}
	 */
	private static function image_style_hint( $keyword, $category_override = '' ) {
		// 题材表从面板设置项读（v1.9.9 新增）——留空用内置默认 14 套+default 兜底，
		// 用户改了存 waisg_settings['image_style_table']，运行时解析成题材数组。
		// 旧版硬编码题材表已迁到 class-settings.php::get_default_image_style_table。
		$table = WAISG_Settings::parse_image_style_table( WAISG_Settings::get( 'image_style_table', '' ) );

		// 调用方透传题材类别时直接用，避免节标题不含题材特征词时误回退通用风格
		// （如游戏攻略的"矿场南侧山腰房"节标题不含游戏词，但文章总关键词"和平精英"含）
		$category = $category_override;
		if ( $category === '' ) {
			// 按题材表里声明的顺序优先级匹配——用户可在面板上调整题材顺序控制命中优先级
			$kw_lower = mb_strtolower( $keyword, 'UTF-8' );
			foreach ( $table as $cat => $row ) {
				// default 行无特征词，跳过匹配
				if ( empty( $row['words'] ) ) continue;
				foreach ( $row['words'] as $w ) {
					if ( mb_stripos( $kw_lower, mb_strtolower( $w, 'UTF-8' ), 0, 'UTF-8' ) !== false ) {
						$category = $cat;
						break 2;
					}
				}
			}
		}

		// 命中题材：用该题材的中英双版风格指令
		if ( $category !== '' && isset( $table[ $category ] ) ) {
			$return = $table[ $category ];
			$return['category'] = $category;
			return $return;
		}

		// 未命中：用 default 兜底行（parse_image_style_table 保证必存在）
		$default = isset( $table['default'] ) ? $table['default'] : array( 'zh' => '', 'en' => '' );
		$default['category'] = '';
		return $default;
	}

	private function fetch_ai_images( $keyword, $count, $override = array(), $language = 'zh-CN', $category_override = '' ) {
		$api_url = $override['image_ai_url']   ?? WAISG_Settings::get( 'image_ai_url', '' );
		$api_key = $override['image_ai_key']   ?? WAISG_Settings::get( 'image_ai_key', '' );
		$model   = $override['image_ai_model'] ?? WAISG_Settings::get( 'image_ai_model', 'dall-e-3' );
		$size    = $override['image_ai_size']  ?? WAISG_Settings::get( 'image_ai_size', '1024x1024' );

		if ( empty( $api_url ) ) return new WP_Error( 'no_url', '未配置图片生成 API 地址。' );
		if ( empty( $api_key ) ) return new WP_Error( 'no_key', '未配置图片生成 API Key。' );

		// 按题材类别给具体视觉风格指令，而非笼统的"插画"——提升 AI 生成图与文章主题的贴合度（v1.9.9）。
		// 题材识别用关键词匹配分派（零 API 调用），未命中的回退通用风格。
		// 调用方可透传 $category_override（如 insert_images_into_content 入口用总关键词识别一次透传给各张图），
		// 避免节标题不含题材特征词时（如"矿场南侧山腰房"不含游戏词）误回退通用风格。
		$style_hint = self::image_style_hint( $keyword, $category_override );

		// 按文章语言切换 prompt 模板——避免中英混杂降低模型对关键词的理解精度。
		// 中文文章用中文 prompt，英文文章用英文 prompt，其他语言回退英文模板。
		if ( strpos( $language, 'zh' ) === 0 ) {
			$prompt = $keyword . '。' . $style_hint['zh'];
		} else {
			$prompt = $keyword . '. ' . $style_hint['en'];
		}

		// 按地址自动识别图片 API 协议（v1.9.9 新增）——和文章大模型一样支持多类型接口，
		// 避免只认 OpenAI 格式致其他平台填了地址也用不了。三分支各自构造请求体和解析响应。
		$proto = self::detect_image_protocol( $api_url );

		if ( $proto === 'gemini' ) {
			return self::fetch_gemini_image( $api_url, $api_key, $model, $prompt, $count, $size, $keyword );
		}
		if ( $proto === 'sdwebui' ) {
			return self::fetch_sdwebui_image( $api_url, $api_key, $prompt, $count, $size, $keyword );
		}

		// 默认 openai 兼容格式（/v1/images/generations）——OpenAI dall-e-3 / 国产中转 / 阿里通义万相 / 智谱等大多走此格式
		$body = array(
			'model'  => $model,
			'prompt' => $prompt,
			'n'      => min( $count, 10 ),
			'size'   => $size,
		);

		$response = wp_remote_post( $api_url, array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', '图片 API 请求失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$api_err = '';
			if ( is_array( $data ) ) {
				$api_err = $data['error']['message'] ?? $data['error']['code'] ?? $data['message'] ?? '';
			}
			$api_err = $api_err !== '' ? $api_err : mb_substr( $raw, 0, 200, 'UTF-8' );
			return new WP_Error( 'api_error', sprintf( '图片 API 返回 HTTP %d：%s', $code, $api_err ) );
		}
		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'bad_format', '图片 API 返回格式异常（缺少 data 字段）。' );
		}

		$images = array();
		foreach ( $data['data'] as $item ) {
			// OpenAI 格式优先取 url；部分中转平台把图放 b64_json 字段（base64 编码图）
			$url = $item['url'] ?? '';
			if ( empty( $url ) && ! empty( $item['b64_json'] ) ) {
				$url = 'data:image/png;base64,' . $item['b64_json'];
			}
			if ( empty( $url ) ) continue;
			$images[] = array( 'url' => $url, 'alt' => $keyword );
		}

		return $images;
	}

	/**
	 * 识别图片 API 协议（v1.9.9 新增）。
	 *
	 * 和文章大模型的 detect_protocol 思路一致——按 API 地址特征自动选协议分支，
	 * 避免只认 OpenAI 格式致其他平台填了地址也用不了。
	 *
	 * @param string $api_url 用户配置的图片生成 API 地址
	 * @return string 'openai'(默认) | 'gemini' | 'sdwebui'
	 */
	private static function detect_image_protocol( $api_url ) {
		$u = strtolower( trim( (string) $api_url ) );
		// Google Gemini Imagen：generativelanguage.googleapis.com + :predict 端
		if ( strpos( $u, 'googleapis' ) !== false
			&& ( strpos( $u, ':predict' ) !== false || strpos( $u, 'imagen' ) !== false ) ) {
			return 'gemini';
		}
		// Stable Diffusion WebUI（AUTOMATIC1111）：/sdapi/v1/txt2img 或 /sdapi/v1/img2img
		if ( strpos( $u, '/sdapi/' ) !== false || strpos( $u, 'txt2img' ) !== false || strpos( $u, 'img2img' ) !== false ) {
			return 'sdwebui';
		}
		return 'openai';
	}

	/**
	 * Google Gemini Imagen 接口（v1.9.9 新增）。
	 * 端点：POST https://generativelanguage.googleapis.com/v1beta/models/imagen-3.0:predict
	 * 鉴权：URL Query ?key=API_KEY
	 * 请求体：{"instances":[{"prompt":"..."}],"parameters":{"sampleCount":N}}
	 * 响应：{"predictions":[{"bytesBase64Encoded":"...","mimeType":"image/png"}]}
	 */
	private static function fetch_gemini_image( $api_url, $api_key, $model, $prompt, $count, $size, $keyword ) {
		// Gemini 鉴权用 Query key，不是 Bearer Header
		$endpoint = add_query_arg( array( 'key' => $api_key ), $api_url );

		// Gemini Imagen 当前不通过 API 支持 size 参数（固定 1024x1024），忽略用户填的 size
		$body = array(
			'instances' => array(
				array( 'prompt' => $prompt ),
			),
			'parameters' => array(
				'sampleCount' => min( $count, 4 ), // Imagen 上限 4 张
			),
		);

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Gemini 图片 API 请求失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$api_err = is_array( $data ) ? ( $data['error']['message'] ?? $data['message'] ?? '' ) : '';
			$api_err = $api_err !== '' ? $api_err : mb_substr( $raw, 0, 200, 'UTF-8' );
			return new WP_Error( 'api_error', sprintf( 'Gemini 图片 API 返回 HTTP %d：%s', $code, $api_err ) );
		}
		if ( empty( $data['predictions'] ) || ! is_array( $data['predictions'] ) ) {
			return new WP_Error( 'bad_format', 'Gemini 图片 API 返回格式异常（缺少 predictions 字段）。' );
		}

		$images = array();
		foreach ( $data['predictions'] as $pred ) {
			$b64 = $pred['bytesBase64Encoded'] ?? '';
			if ( empty( $b64 ) ) continue;
			$mime = $pred['mimeType'] ?? 'image/png';
			$images[] = array(
				'url' => 'data:' . $mime . ';base64,' . $b64,
				'alt' => $keyword,
			);
		}
		return $images;
	}

	/**
	 * Stable Diffusion WebUI（AUTOMATIC1111）接口（v1.9.9 新增）。
	 * 端点：POST http://localhost:7860/sdapi/v1/txt2img
	 * 鉴权：可选（启用 --auth 时用 Basic Auth，否则无鉴权）
	 * 请求体：{"prompt":"...","batch_size":N,"width":W,"height":H,"steps":20,"cfg_scale":7}
	 * 响应：{"images":["base64...","base64..."]}（base64 数组，无 data:// 前缀）
	 */
	private static function fetch_sdwebui_image( $api_url, $api_key, $prompt, $count, $size, $keyword ) {
		// 解析 "宽x高" 格式——SD WebUI 用 width/height 两个独立字段
		$width  = 1024;
		$height = 1024;
		if ( preg_match( '/^(\d{2,5})x(\d{2,5})$/i', $size, $m ) ) {
			$width  = (int) $m[1];
			$height = (int) $m[2];
		}

		$body = array(
			'prompt'      => $prompt,
			'batch_size'  => min( $count, 4 ), // SD WebUI batch_size 上限受显存约束，保守 4
			'width'       => $width,
			'height'      => $height,
			'steps'       => 20,      // 默认采样步数，够用不慢
			'cfg_scale'   => 7,       // 默认提示词相关性
			'sampler_name' => 'Euler a', // 快速稳定的采样器
		);

		// 鉴权：SD WebUI 启用 --auth 时用 Basic Auth，api_key 格式约定为 "user:pass"
		// 多数本地部署无鉴权，api_key 填空或不填 "user:pass" 都跳过
		$auth_header = null;
		if ( $api_key !== '' && strpos( $api_key, ':' ) !== false ) {
			$auth_header = 'Basic ' . base64_encode( $api_key );
		}

		$req = array(
			'timeout' => 120, // SD WebUI 生图比 dall-e 慢，放宽到 120s
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		);
		if ( $auth_header !== null ) {
			$req['headers']['Authorization'] = $auth_header;
		}

		$response = wp_remote_post( $api_url, $req );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'SD WebUI 图片 API 请求失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( $code < 200 || $code >= 300 ) {
			$api_err = is_array( $data ) ? ( $data['detail'] ?? $data['error'] ?? $data['message'] ?? '' ) : '';
			$api_err = $api_err !== '' ? $api_err : mb_substr( $raw, 0, 200, 'UTF-8' );
			return new WP_Error( 'api_error', sprintf( 'SD WebUI 图片 API 返回 HTTP %d：%s', $code, $api_err ) );
		}
		if ( empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
			return new WP_Error( 'bad_format', 'SD WebUI 图片 API 返回格式异常（缺少 images 字段）。' );
		}

		$images = array();
		foreach ( $data['images'] as $b64 ) {
			if ( ! is_string( $b64 ) || $b64 === '' ) continue;
			// SD WebUI 返回的 base64 不带 data:// 前缀，统一补上以适配 <img src>
			$images[] = array(
				'url' => 'data:image/png;base64,' . $b64,
				'alt' => $keyword,
			);
		}
		return $images;
	}

	/** 从 Pexels 获取图片。@return array|WP_Error */
	private function fetch_pexels( $keyword, $count, $api_key ) {
		// 加横向过滤（v1.9.9）：文章配图一般用横图，过滤掉竖图/正方图，提升可用度
		$url = add_query_arg( array(
			'query'      => rawurlencode( $keyword ),
			'per_page'   => $count,
			'locale'     => 'zh-CN',
			'orientation' => 'landscape',
		), 'https://api.pexels.com/v1/search' );

		$response = wp_remote_get( $url, array(
			'headers' => array( 'Authorization' => $api_key ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Pexels 请求失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$data = json_decode( $raw, true );
			$api_err = is_array( $data ) ? ( $data['error'] ?? '' ) : '';
			$api_err = $api_err !== '' ? $api_err : mb_substr( $raw, 0, 200, 'UTF-8' );
			// 401/403 = Key 无效；429 = 限速；其他原样透传
			$hint = ( $code === 401 || $code === 403 ) ? '（API Key 无效或已失效）' : '';
			return new WP_Error( 'api_error', sprintf( 'Pexels 返回 HTTP %d：%s%s', $code, $api_err, $hint ) );
		}

		$body = json_decode( $raw, true );
		$images = array();
		if ( ! empty( $body['photos'] ) ) {
			foreach ( $body['photos'] as $photo ) {
				$images[] = array(
					'url' => $photo['src']['large'] ?? $photo['src']['original'] ?? '',
					'alt' => $photo['alt'] ?? $keyword,
				);
			}
		}
		return $images;
	}

	/** 从 Unsplash 获取图片。@return array|WP_Error */
	private function fetch_unsplash( $keyword, $count, $api_key ) {
		// 加横向过滤（v1.9.9）：文章配图一般用横图，过滤掉竖图/正方图，提升可用度
		$url = add_query_arg( array(
			'query'       => rawurlencode( $keyword ),
			'per_page'    => $count,
			'client_id'   => $api_key,
			'orientation' => 'landscape',
		), 'https://api.unsplash.com/search/photos' );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'request_failed', 'Unsplash 请求失败：' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$data = json_decode( $raw, true );
			$api_err = is_array( $data ) ? ( $data['error'] ?? $data['message'] ?? '' ) : '';
			$api_err = $api_err !== '' ? $api_err : mb_substr( $raw, 0, 200, 'UTF-8' );
			$hint = ( $code === 401 || $code === 403 ) ? '（Access Key 无效或已失效）' : '';
			return new WP_Error( 'api_error', sprintf( 'Unsplash 返回 HTTP %d：%s%s', $code, $api_err, $hint ) );
		}

		$body = json_decode( $raw, true );
		$images = array();
		if ( ! empty( $body['results'] ) ) {
			foreach ( $body['results'] as $photo ) {
				$images[] = array(
					'url' => $photo['urls']['regular'] ?? $photo['urls']['full'] ?? '',
					'alt' => $photo['alt_description'] ?? $photo['description'] ?? $keyword,
				);
			}
		}
		return $images;
	}

	// =========================================================
	// AJAX：预览搜图结果（设置页测试用）
	// =========================================================
	public function ajax_fetch_images() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

		$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$count   = absint( $_POST['count'] ?? 3 );
		if ( empty( $keyword ) ) wp_send_json_error( array( 'message' => '请输入关键词。' ) );

		$images = $this->fetch_images( $keyword, $count );
		if ( is_wp_error( $images ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $images->get_error_message() ) ) );
		}
		wp_send_json_success( array( 'images' => $images ) );
	}

	// =========================================================
	// AJAX：更新文章发布状态（逐篇或批量，生成/优化完成后由前端调用）
	// =========================================================
	public function ajax_update_post_status() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => '权限不足。' ) );

		$raw_ids   = sanitize_text_field( $_POST['post_ids'] ?? '' );
		$post_ids  = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		$target    = sanitize_key( $_POST['target_status'] ?? 'draft' );
		$post_date = sanitize_text_field( wp_unslash( $_POST['post_date'] ?? '' ) );

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => '没有有效的文章 ID。' ) );
		}
		if ( ! in_array( $target, array( 'draft', 'publish', 'future' ), true ) ) {
			$target = 'draft';
		}

		$updated = array();
		foreach ( $post_ids as $pid ) {
			if ( ! current_user_can( 'edit_post', $pid ) ) continue;
			$arr = array( 'ID' => $pid, 'post_status' => $target );
			if ( $target === 'future' && ! empty( $post_date ) ) {
				$arr['post_date']     = $post_date;
				$arr['post_date_gmt'] = get_gmt_from_date( $post_date );
			}
			wp_update_post( $arr );
			$updated[] = $pid;
		}

		wp_send_json_success( array( 'updated' => $updated ) );
	}

	// =========================================================
	// AJAX：改写/伪原创
	// =========================================================

	/**
	 * 改写一篇文章并存入暂存区
	 * POST 参数：content, keywords, language, description, post_type, category_id, index
	 */
	public function ajax_rewrite_article() {
		check_ajax_referer( 'waisg_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => '权限不足。' ) );
		}

		$content     = wp_unslash( $_POST['content']     ?? '' );
		$keywords    = sanitize_text_field( wp_unslash( $_POST['keywords']    ?? '' ) );
		$language    = sanitize_text_field( wp_unslash( $_POST['language']    ?? 'zh-CN' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$post_type   = sanitize_key( $_POST['post_type']   ?? 'post' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$index       = absint( $_POST['index'] ?? 1 );
		$template_id = absint( $_POST['template_id'] ?? 0 );
		$model_override = sanitize_key( $_POST['model_override'] ?? '' );
		$skip_humanize  = ! empty( $_POST['skip_humanize'] );
		$template    = null;
		if ( $template_id ) {
			foreach ( WAISG_Settings::get_templates() as $tpl ) {
				if ( (int) $tpl['id'] === $template_id ) { $template = $tpl; break; }
			}
		}

		if ( empty( $content ) ) {
			wp_send_json_error( array( 'message' => '请粘贴原文内容。', 'index' => $index ) );
		}

		$allowed_types = WAISG_Settings::get_post_types();
		if ( ! in_array( $post_type, $allowed_types, true ) ) {
			$post_type = 'post';
		}

		// 保护原文中的图片，防止 AI 改写时丢失
		$img_protected = WAISG_AI_API::protect_images( $content );
		$safe_content  = $img_protected['html'];

		$prompts = WAISG_AI_API::build_rewrite_prompt( $safe_content, $keywords, $language, $description, $template );
		$extra   = WAISG_AI_API::build_long_content_extra( $safe_content );
		// 前端传参 > 全局设置：确定使用的模型
		$use_model = $model_override ? $model_override : WAISG_Settings::get( 'batch_model', 'main' );
		if ( $use_model === 'lightweight' ) {
			$lm = WAISG_Settings::get( 'lightweight_model', '' );
			if ( $lm ) {
				$extra['model'] = $lm;
			}
		}
		$result  = WAISG_AI_API::call_prompts( $prompts, $extra );

		if ( is_wp_error( $result ) ) {
			WAISG_Logger::log( 0, 'rewrite', $result->get_error_message(), '第 ' . $index . ' 篇' );
			wp_send_json_error( array(
				'message' => '改写请求失败：' . wp_strip_all_tags( $result->get_error_message() ),
				'index'   => $index,
			) );
		}

		$data = WAISG_AI_API::parse_json_response( $result['text'], 'rewrite', 0 );
		if ( ! $data ) {
			WAISG_Logger::log( 0, 'rewrite', 'AI 返回格式异常（无法解析 JSON）', '第 ' . $index . ' 篇 ｜AI返回：' . mb_substr( $result['text'], 0, 300, 'UTF-8' ) );
			wp_send_json_error( array( 'message' => 'AI 返回格式异常，请重试。', 'index' => $index ) );
		}

		$post_title   = sanitize_text_field( $data['title']           ?? '改写文章' );
		$post_content = WAISG_AI_API::sanitize_content( $data['content']                ?? '' );
		$post_excerpt = sanitize_textarea_field( $data['excerpt']     ?? '' );
		$seo_title    = sanitize_text_field( $data['seo_title']       ?? '' );
		$seo_desc     = sanitize_textarea_field( $data['seo_description'] ?? '' );
		$seo_kw       = sanitize_text_field( $data['seo_keywords']    ?? '' );

		// 降低 AI 痕迹：还原图片 + 二次润色（如开启且未跳过）+ 外层兜底 filter
		if ( ! empty( $post_content ) ) {
			$post_content = WAISG_AI_API::restore_images( $post_content, $img_protected['map'] );
			if ( ! $skip_humanize && WAISG_Settings::get( 'humanize_enabled', 0 ) ) {
				$post_content = WAISG_AI_API::humanize( $post_content, $use_model );
			}
			$post_content = WAISG_AI_API::filter_ai_phrases( $post_content );
		}

		// SEO 本地修复（零 Token）
		$fixed = WAISG_AI_API::auto_fix_seo( array(
			'title'     => $post_title,
			'seo_title' => $seo_title,
			'seo_desc'  => $seo_desc,
			'seo_kw'    => $seo_kw,
			'excerpt'   => $post_excerpt,
			'content'   => $post_content,
		) );
		$post_title   = $fixed['title'];
		$seo_title    = $fixed['seo_title'];
		$seo_desc     = $fixed['seo_desc'];
		$seo_kw       = $fixed['seo_kw'];
		$post_excerpt = $fixed['excerpt'];

		$history_id = WAISG_History::save_staged( array(
			'entry_type'   => 'generated',
			'post_id'      => 0,
			'keyword'      => $keywords,
			'post_type'    => $post_type,
			'category_id'  => $category_id,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_excerpt' => $post_excerpt,
			'seo_title'    => $seo_title,
			'seo_desc'     => $seo_desc,
			'seo_kw'       => $seo_kw,
		) );

		wp_send_json_success( array(
			'history_id' => $history_id,
			'index'      => $index,
			'title'      => $post_title,
			'detail'     => array(
				'post_title'   => $post_title,
				'post_content' => $post_content,
				'post_excerpt' => $post_excerpt,
				'seo_title'    => $seo_title,
				'seo_desc'     => $seo_desc,
				'seo_kw'       => $seo_kw,
			),
			'recovered'  => $data['_recovered'] ?? '',
		) );
	}
}
