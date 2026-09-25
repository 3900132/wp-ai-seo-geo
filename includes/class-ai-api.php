<?php
/**
 * AI API 调用类
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WAISG_AI_API {

	/**
	 * 发送请求到 AI 接口
	 *
	 * @param string $user_prompt   用户提示词
	 * @param string $system_prompt 系统提示词
	 * @param array  $extra         额外请求参数（覆盖默认值，如 model/temperature/max_tokens/cache_user_prefix）
	 *   - cache_user_prefix (string)：user prompt 的可缓存前缀（Claude 显式缓存用）。
	 *     设置后，user 的 cache_user_prefix 部分会标记 cache_control，剩余动态部分不缓存。
	 * @return array|WP_Error 成功返回 ['text'=>string, 'tokens'=>int]，失败返回 WP_Error
	 */
	public static function call( $user_prompt, $system_prompt = '', $extra = array() ) {
		$api_url     = WAISG_Settings::get( 'api_url' );
		$api_key     = WAISG_Settings::get( 'api_key' );
		$model       = $extra['model']       ?? WAISG_Settings::get( 'model', 'gpt-4o' );
		$timeout     = $extra['timeout']     ?? WAISG_Settings::get( 'timeout', 60 );
		$temperature = floatval( $extra['temperature'] ?? WAISG_Settings::get( 'temperature', 0.3 ) );
		$max_tokens  = absint( $extra['max_tokens']    ?? WAISG_Settings::get( 'max_tokens', 4096 ) );
		$cache_user_prefix = $extra['cache_user_prefix'] ?? '';

		// 推理模型：思考 token 计入 max_tokens 输出上限，思考用时较长时偶发
		// "思考用完配额、最终答案未输出"导致优化失败。这里提前追加推理预算，
		// 并放宽 timeout（推理模型思考较慢），从源头避免绝大多数偶发。
		$configured_max_tokens = absint( WAISG_Settings::get( 'max_tokens', 4096 ) );
		$is_reasoning = self::is_reasoning_model( $model );
		if ( $is_reasoning ) {
			$max_tokens = $max_tokens + $configured_max_tokens;
		}

		// 限制 timeout 范围：最低 30 秒，最高 600 秒
		$timeout = max( 30, min( 600, absint( $timeout ) ) );

		// 同步放宽 PHP 执行时间，避免 PHP 端先超时（部分主机禁用该函数时静默失败）
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 30 );
		}

		if ( empty( $api_url ) ) {
			return new WP_Error( 'no_api_url', '请先在设置中配置 API 地址。' );
		}
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', '请先在设置中配置 API Key。' );
		}

		// 识别协议（按 API 地址）
		$protocol = self::detect_protocol( $api_url );

		// ── 协议：Google Gemini（model 在 URL、x-goog-api-key 头、contents/parts 格式）──
		if ( $protocol === 'gemini' ) {
			$endpoint   = self::build_gemini_endpoint( $api_url, $model );
			$headers    = array(
				'Content-Type'    => 'application/json',
				'x-goog-api-key'  => $api_key,
			);
			$strategies = self::build_gemini_strategies( $system_prompt, $user_prompt, $temperature, $max_tokens );
			return self::dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens );
		}

		// ── 协议：Anthropic 原生（x-api-key + anthropic-version，system 顶层字段）──
		if ( $protocol === 'anthropic' ) {
			$endpoint   = self::normalize_anthropic_endpoint( $api_url );
			$headers    = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			);
			$strategies = self::build_anthropic_strategies( $model, $system_prompt, $user_prompt, $temperature, $max_tokens );
			return self::dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens );
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);

		// ── 协议：OpenAI Responses API（/responses 端点，input 请求体）──
		if ( $protocol === 'responses' ) {
			$endpoint   = self::normalize_endpoint( $api_url );
			$strategies = self::build_responses_strategies( $model, $system_prompt, $user_prompt, $temperature, $max_tokens );
			return self::dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens );
		}

		// ── 协议：OpenAI Chat Completions（默认；含 Claude 中转的 cache_control）──
		$endpoint  = self::normalize_endpoint( $api_url );
		$is_claude = self::is_claude_api( $model, $api_url );
		$messages  = self::build_messages( $system_prompt, $user_prompt, $cache_user_prefix, $is_claude );

		if ( $is_claude ) {
			// Claude 走单策略 request_once，为兼容推理兜底也包一层重试逻辑
			$strategies = array( array( 'label' => 'standard', 'body' => array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			) ) );
			return self::dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens );
		}

		$strategies = self::build_openai_compatible_strategies( $model, $system_prompt, $user_prompt, $messages, $temperature, $max_tokens );
		return self::dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens );
	}

	/**
	 * 判断指定模型是否为推理（reasoning）模型。
	 *
	 * 数据来源：用户在「基本设置」点「测试连接」时，插件检测 API 返回中
	 * 是否含 reasoning_content 字段，命中则把模型名存入 waisg_settings['reasoning_models']。
	 * 本方法只查这份"实测名单"，不靠模型名猜测——避免漏判新模型或误判同名变体。
	 *
	 * @param string $model 模型名
	 * @return bool
	 */
	private static function is_reasoning_model( $model ) {
		$model = strtolower( (string) $model );
		if ( $model === '' ) return false;
		$known = WAISG_Settings::get( 'reasoning_models', array() );
		if ( ! is_array( $known ) ) $known = array();
		foreach ( $known as $m ) {
			if ( strtolower( (string) $m ) === $model ) return true;
		}
		return false;
	}

	/**
	 * 运行时自愈：正式请求中若实测到模型返回了推理内容（reasoning_content），
	 * 而它还不在推理名单里，就自动补进名单。此后 call() 入口会为它叠加推理预算，
	 * 不再依赖用户手动「测试连接」探测——解决"测试用 hi 打招呼、模型没启动思考、
	 * 因而漏判"的场景。
	 *
	 * 只增不删（运行时单次响应不足以证明"不是推理模型"，删除交给测试连接）；
	 * 已在名单则直接跳过，避免每次请求都写库。
	 *
	 * @param string $model    模型名
	 * @param bool   $is_test  是否测试连接场景（测试由 ajax 单独处理，这里跳过）
	 */
	private static function maybe_learn_reasoning_model( $model, $is_test ) {
		if ( $is_test ) return;
		if ( self::is_reasoning_model( $model ) ) return;
		if ( class_exists( 'WAISG_Settings' ) ) {
			WAISG_Settings::update_reasoning_model( $model, true );
		}
	}

	/**
	 * 统一执行请求，并为推理模型提供"截断兜底重试"。
	 *
	 * 所有协议分支（OpenAI 兼容 / Claude / Gemini / Responses）都走此方法，
	 * 避免在多处重复实现重试逻辑。命中 reasoning_only_response 时，
	 * 自动放大 max_tokens 重试一次；重试仍失败才把错误抛给上层。
	 *
	 * @param string  $endpoint
	 * @param array   $headers
	 * @param array   $strategies       请求策略列表
	 * @param int     $timeout          当前超时（秒）
	 * @param string  $model            模型名
	 * @param int     $max_tokens       当前 max_tokens
	 * @param int     $retry_count      内部用，防止无限重试
	 * @return array|WP_Error
	 */
	private static function dispatch_with_reasoning_retry( $endpoint, $headers, $strategies, $timeout, $model, $max_tokens, $retry_count = 0 ) {
		$result = self::run_strategies( $endpoint, $headers, $strategies, $timeout, $model );

		// 兜底：推理模型思考耗尽 max_tokens、未输出最终答案时，放大配额重试一次
		if ( is_wp_error( $result )
			&& $result->get_error_code() === 'reasoning_only_response'
			&& $retry_count < 1
		) {
			$new_max_tokens = absint( $max_tokens ) * 2;
			$new_timeout    = min( 600, $timeout + 60 );

			if ( class_exists( 'WAISG_Logger' ) ) {
				WAISG_Logger::log( 0, 'retry_reasoning', sprintf(
					'模型 %s 推理耗尽 max_tokens=%d，放大到 %d 重试',
					$model, $max_tokens, $new_max_tokens
				) );
			}

			// 用放大后的配额重建策略列表。不同协议的 max_tokens 字段名不同：
			//   OpenAI Chat / Anthropic：顶层 max_tokens
			//   OpenAI Responses API：顶层 max_output_tokens
			//   Gemini：generationConfig.maxOutputTokens
			// 只更新原本就带配额字段的策略；no_max_tokens / messages_only 等故意不带的保持原样
			$rebuilt = array();
			foreach ( $strategies as $strategy ) {
				$body = $strategy['body'];
				if ( isset( $body['max_tokens'] ) ) {
					$body['max_tokens'] = $new_max_tokens;
				} elseif ( isset( $body['max_output_tokens'] ) ) {
					$body['max_output_tokens'] = $new_max_tokens;
				} elseif ( isset( $body['generationConfig']['maxOutputTokens'] ) ) {
					$body['generationConfig']['maxOutputTokens'] = $new_max_tokens;
				}
				$rebuilt[] = array( 'label' => $strategy['label'], 'body' => $body );
			}

			return self::dispatch_with_reasoning_retry( $endpoint, $headers, $rebuilt, $new_timeout, $model, $new_max_tokens, $retry_count + 1 );
		}

		return $result;
	}

	private static function request_once( $endpoint, $headers, $request_body, $timeout, $model, $strategy_label = 'standard', $is_test = false ) {
		$response = wp_remote_post( $endpoint, array(
			'timeout' => $timeout,
			'headers' => $headers,
			'body'    => wp_json_encode( $request_body ),
		) );

		if ( is_wp_error( $response ) ) {
			$err_msg = $response->get_error_message();
			if ( stripos( $err_msg, 'timed out' ) !== false || stripos( $err_msg, 'timeout' ) !== false ) {
				$err_msg .= '（建议：在「基本设置 → 请求超时」中将超时时间调大到 180~300 秒；或对长文章使用「仅优化 SEO」功能减少处理量）';
			}
			return new WP_Error( 'request_failed', 'API 请求失败：' . $err_msg );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		$api_error = self::extract_api_error_message( $code, $data, $body );
		if ( $api_error !== '' ) {
			return new WP_Error( 'api_error', 'API 返回错误：' . $api_error );
		}

		$text         = self::extract_text_from_response( $data );
		$tokens       = self::extract_total_tokens( $data );
		$finish_reason = self::extract_finish_reason( $data );
		$reasoning    = self::extract_reasoning_text( $data );

		if ( empty( $text ) ) {
			$reasoning_json = self::extract_json_from_text( $reasoning );
			if ( $reasoning_json !== '' ) {
				$text = $reasoning_json;
			} else {
				// 测试连接场景：只要 API 有正常响应（拿到推理内容或有 token 计数），
				// 就说明认证/协议/网络全部打通——即使推理模型思考没输出最终答案，也算连接成功。
				// 这样推理模型（如龙猫）不会因"仅返回推理内容且被截断"而卡在测试环节。
				if ( $is_test && ( $reasoning !== '' || $tokens > 0 ) ) {
					return array(
						'text'        => $reasoning !== '' ? '（推理模型，测试仅验证连通性）' : '（连接正常）',
						'tokens'      => $tokens,
						'is_reasoning' => $reasoning !== '',
					);
				}

				WAISG_Logger::log(
					0,
					'empty_response',
					sprintf( 'AI 返回内容为空（模型 %s，策略 %s，finish_reason：%s）', $model, $strategy_label, $finish_reason ?: 'unknown' ),
					sprintf( 'has_reasoning：%s，响应 body（前 500 字符）：%s', $reasoning !== '' ? 'yes' : 'no', mb_substr( $body, 0, 500, 'UTF-8' ) )
				);

				if ( $reasoning !== '' ) {
					// 运行时自愈：模型明确返回了推理内容（哪怕被截断），学进推理名单，
					// 下次 call() 入口自动加预算，从源头避免再次被截断
					self::maybe_learn_reasoning_model( $model, $is_test );

					$message = 'AI 仅返回推理内容，未返回最终答案';
					if ( $finish_reason === 'length' ) {
						$message .= '，且输出已被截断，请提高 max_tokens 或切换模型。';
					} else {
						$message .= '，请切换兼容策略或模型。';
					}
					return new WP_Error( 'reasoning_only_response', $message );
				}

				if ( $finish_reason === 'length' ) {
					return new WP_Error( 'truncated_response', 'AI 输出被截断，请提高 max_tokens 或切换模型。' );
				}

				return new WP_Error( 'empty_response', 'AI 返回内容为空，请检查 API 配置或提示词。' );
			}
		}

		if ( $tokens > 0 ) {
			// 判断当前模型属于主模型还是轻量模型
			$lm  = WAISG_Settings::get( 'lightweight_model', '');
			$tag = ( ! empty( $lm ) && strtolower( $model ) === strtolower( $lm ) ) ? 'lightweight' : 'main';
			WAISG_Settings::record_tokens( $tokens, $tag );
		}

		// 运行时自愈：本次成功响应带推理内容，把模型学进推理名单（下次自动加预算）
		if ( $reasoning !== '' ) {
			self::maybe_learn_reasoning_model( $model, $is_test );
		}

		return array( 'text' => trim( $text ), 'tokens' => $tokens, 'is_reasoning' => $reasoning !== '' );
	}

	private static function build_openai_compatible_strategies( $model, $system_prompt, $user_prompt, $messages, $temperature, $max_tokens ) {
		$single_user_content = $system_prompt ? ( $system_prompt . "\n\n" . $user_prompt ) : $user_prompt;

		return array(
			array(
				'label' => 'standard',
				'body'  => array(
					'model'       => $model,
					'messages'    => $messages,
					'temperature' => $temperature,
					'max_tokens'  => $max_tokens,
				),
			),
			array(
				'label' => 'no_temperature',
				'body'  => array(
					'model'      => $model,
					'messages'   => $messages,
					'max_tokens' => $max_tokens,
				),
			),
			array(
				'label' => 'no_max_tokens',
				'body'  => array(
					'model'       => $model,
					'messages'    => $messages,
					'temperature' => $temperature,
				),
			),
			array(
				'label' => 'messages_only',
				'body'  => array(
					'model'    => $model,
					'messages' => $messages,
				),
			),
			array(
				'label' => 'single_user_only',
				'body'  => array(
					'model'    => $model,
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => $single_user_content,
						),
					),
				),
			),
		);
	}

	private static function should_retry_compatible_request( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$code    = $result->get_error_code();
		$message = $result->get_error_message();
		if ( $code === 'empty_response' ) {
			return true;
		}
		if ( $code !== 'api_error' ) {
			return false;
		}

		$retry_signals = array(
			'Model not support',
			'not support',
			'unsupported',
			'invalid_request',
			'invalid parameter',
			'unknown parameter',
			'invalid messages',
			'bad request',
		);
		foreach ( $retry_signals as $signal ) {
			if ( stripos( $message, $signal ) !== false ) {
				return true;
			}
		}

		return false;
	}

	// ================================================================
	// 多协议适配层（OpenAI Chat / OpenAI Responses / Anthropic / Gemini）
	// ================================================================

	/**
	 * 根据 API 地址识别协议
	 * @return string 'gemini' | 'anthropic' | 'responses' | 'openai'
	 */
	private static function detect_protocol( $api_url ) {
		$u = strtolower( trim( (string) $api_url ) );
		if ( strpos( $u, 'generativelanguage.googleapis' ) !== false
			|| strpos( $u, ':generatecontent' ) !== false
			|| strpos( $u, ':streamgeneratecontent' ) !== false ) {
			return 'gemini';
		}
		if ( strpos( $u, 'api.anthropic.com' ) !== false || preg_match( '#/messages/?$#', $u ) ) {
			return 'anthropic';
		}
		if ( preg_match( '#/responses/?$#', $u ) ) {
			return 'responses';
		}
		return 'openai';
	}

	/**
	 * 规范化 OpenAI 系（Chat/Responses）endpoint。
	 * - 完整端点（/chat/completions、/responses、/messages 结尾）→ 原样使用，绝不改动
	 * - 仅填到 base（如 .../v1）→ 兜底补 /chat/completions（兼容旧配置）
	 */
	private static function normalize_endpoint( $api_url ) {
		$endpoint = rtrim( trim( (string) $api_url ), '/' );
		if ( $endpoint === '' ) return '';
		if ( preg_match( '#/(chat/completions|responses|messages)$#i', $endpoint ) ) {
			return $endpoint;
		}
		return $endpoint . '/chat/completions';
	}

	/** Anthropic endpoint：完整 /messages 原样；填到 base 则补 */
	private static function normalize_anthropic_endpoint( $api_url ) {
		$endpoint = rtrim( trim( (string) $api_url ), '/' );
		if ( preg_match( '#/messages$#i', $endpoint ) ) {
			return $endpoint;
		}
		if ( preg_match( '#/v\d+$#i', $endpoint ) ) {
			return $endpoint . '/messages';
		}
		return $endpoint . '/v1/messages';
	}

	/** Gemini endpoint：拼成 .../v{n}beta/models/{model}:generateContent（model 在 URL 里） */
	private static function build_gemini_endpoint( $api_url, $model ) {
		$base = rtrim( trim( (string) $api_url ), '/' );
		if ( stripos( $base, ':generatecontent' ) !== false ) {
			return $base; // 用户已填完整端点
		}
		$base = preg_replace( '#/models/.*$#i', '', $base );
		$base = rtrim( $base, '/' );
		if ( ! preg_match( '#/v\d+(beta)?$#i', $base ) ) {
			$base .= '/v1beta';
		}
		return $base . '/models/' . rawurlencode( $model ) . ':generateContent';
	}

	/** OpenAI Responses API 请求体策略（含降级） */
	private static function build_responses_strategies( $model, $system_prompt, $user_prompt, $temperature, $max_tokens ) {
		$input = array();
		if ( $system_prompt !== '' ) {
			$input[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$input[] = array( 'role' => 'user', 'content' => $user_prompt );
		$merged = $system_prompt ? ( $system_prompt . "\n\n" . $user_prompt ) : $user_prompt;

		return array(
			array( 'label' => 'standard', 'body' => array(
				'model' => $model, 'input' => $input,
				'temperature' => $temperature, 'max_output_tokens' => $max_tokens,
			) ),
			array( 'label' => 'no_temperature', 'body' => array(
				'model' => $model, 'input' => $input, 'max_output_tokens' => $max_tokens,
			) ),
			array( 'label' => 'input_only', 'body' => array(
				'model' => $model, 'input' => $input,
			) ),
			array( 'label' => 'merged_string', 'body' => array(
				'model' => $model, 'input' => $merged,
			) ),
		);
	}

	/** Anthropic Messages API 请求体策略（system 顶层字段，max_tokens 必填） */
	private static function build_anthropic_strategies( $model, $system_prompt, $user_prompt, $temperature, $max_tokens ) {
		// Anthropic max_tokens 必填，不同 Claude 模型上限不同（3.5 Sonnet 8192~64000，3 Opus 4096）。
		// 此处不再硬编码 8192 封顶——推理预算叠加后可能超过此值，硬封顶会导致
		// dispatch_with_reasoning_retry 重建策略时首次与重试配额不一致。
		// 交由 Anthropic API 自身校验模型上限（超限会返回 api_error，走正常错误流程）。
		$mt = max( 1, (int) $max_tokens );
		$base = array(
			'model'      => $model,
			'max_tokens' => $mt,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
		);
		if ( $system_prompt !== '' ) {
			$base['system'] = $system_prompt;
		}
		$with_temp = $base;
		$with_temp['temperature'] = $temperature;

		return array(
			array( 'label' => 'standard', 'body' => $with_temp ),
			array( 'label' => 'no_temperature', 'body' => $base ),
		);
	}

	/** Google Gemini 请求体策略（model 不在 body，在 URL） */
	private static function build_gemini_strategies( $system_prompt, $user_prompt, $temperature, $max_tokens ) {
		$body = array(
			'contents' => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $user_prompt ) ),
				),
			),
			'generationConfig' => array(
				'temperature'     => $temperature,
				'maxOutputTokens' => $max_tokens > 0 ? (int) $max_tokens : 4096,
			),
		);
		if ( $system_prompt !== '' ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => $system_prompt ) ),
			);
		}
		$no_temp = $body;
		unset( $no_temp['generationConfig']['temperature'] );

		return array(
			array( 'label' => 'standard', 'body' => $body ),
			array( 'label' => 'no_temperature', 'body' => $no_temp ),
		);
	}

	/**
	 * 依次尝试多个请求策略：成功即返回（附带命中的 strategy 标签）；
	 * 失败若属于"参数/格式不被支持"则降级重试，否则立即返回错误。
	 */
	private static function run_strategies( $endpoint, $headers, $strategies, $timeout, $model, $is_test = false ) {
		$last_error = null;
		foreach ( $strategies as $strategy ) {
			$result = self::request_once( $endpoint, $headers, $strategy['body'], $timeout, $model, $strategy['label'], $is_test );
			if ( ! is_wp_error( $result ) ) {
				$result['strategy'] = $strategy['label'];
				return $result;
			}
			$last_error = $result;
			if ( ! self::should_retry_compatible_request( $result ) ) {
				return $result;
			}
		}
		return $last_error ? $last_error : new WP_Error( 'request_failed', 'API 请求失败：未获得有效响应。' );
	}

	/**
	 * 从响应中提取总 token（兼容各家 usage 字段）
	 * - OpenAI Chat/Responses: usage.total_tokens
	 * - Anthropic/Responses: usage.input_tokens + output_tokens
	 * - Gemini: usageMetadata.totalTokenCount
	 */
	private static function extract_total_tokens( $data ) {
		if ( ! is_array( $data ) ) return 0;
		if ( isset( $data['usageMetadata']['totalTokenCount'] ) ) {
			return (int) $data['usageMetadata']['totalTokenCount'];
		}
		$u = ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) ? $data['usage'] : array();
		if ( isset( $u['total_tokens'] ) ) {
			return (int) $u['total_tokens'];
		}
		if ( isset( $u['input_tokens'] ) || isset( $u['output_tokens'] ) ) {
			return (int) ( ( $u['input_tokens'] ?? 0 ) + ( $u['output_tokens'] ?? 0 ) );
		}
		return 0;
	}

	public static function test_connection( $api_url, $api_key, $model ) {
		$protocol = self::detect_protocol( $api_url );
		$system   = '';
		// 用一道需要动脑的小题做探测：推理模型面对 "hi" 这类招呼往往不启动思考、
		// 不吐 reasoning_content，会被漏判为普通模型。给一道简单推理题能让它在
		// 测试阶段就暴露 reasoning_content，从而正确写入推理名单。
		$user     = '1+1 等于几？只回答数字。';
		$timeout  = 30; // 推理模型思考较慢，测试超时放宽到 30s
		// 测试用 max_tokens 放大到 512：推理（reasoning）模型会先消耗大量 token 思考，
		// 给太小（旧版 16/32）会导致"仅返回推理内容且被截断"，测试永远不通过
		$test_tokens = 512;

		if ( $protocol === 'gemini' ) {
			$endpoint   = self::build_gemini_endpoint( $api_url, $model );
			$headers    = array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $api_key );
			$strategies = self::build_gemini_strategies( $system, $user, 0.3, $test_tokens );
			return self::run_strategies( $endpoint, $headers, $strategies, $timeout, $model, true );
		}

		if ( $protocol === 'anthropic' ) {
			$endpoint   = self::normalize_anthropic_endpoint( $api_url );
			$headers    = array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			);
			$strategies = self::build_anthropic_strategies( $model, $system, $user, 0.3, $test_tokens );
			return self::run_strategies( $endpoint, $headers, $strategies, $timeout, $model, true );
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);

		if ( $protocol === 'responses' ) {
			$endpoint   = self::normalize_endpoint( $api_url );
			$strategies = self::build_responses_strategies( $model, $system, $user, 0.3, $test_tokens );
			return self::run_strategies( $endpoint, $headers, $strategies, $timeout, $model, true );
		}

		// OpenAI Chat Completions（默认）
		$endpoint  = self::normalize_endpoint( $api_url );
		$is_claude = self::is_claude_api( $model, $api_url );
		$messages  = self::build_messages( $system, $user, '', $is_claude );

		if ( $is_claude ) {
			return self::request_once( $endpoint, $headers, array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => 0.3,
				'max_tokens'  => $test_tokens,
			), $timeout, $model, 'test_standard', true );
		}

		$strategies = self::build_openai_compatible_strategies( $model, $system, $user, $messages, 0.3, $test_tokens );
		return self::run_strategies( $endpoint, $headers, $strategies, $timeout, $model, true );
	}


	/**
	 * 从 API 响应数据中提取文本内容（兼容多种返回格式）
	 *
	 * 支持的格式：
	 *   - OpenAI 标准：choices[0].message.content
	 *   - Claude 原生：content[0].text
	 *   - 旧版 Completions：choices[0].text
	 *   - 其他兼容 API：output.text / output.content / result / response
	 *
	 * @param array $data 解码后的 API 响应数据
	 * @return string 提取到的文本，找不到返回空字符串
	 */
	private static function extract_text_from_response( $data ) {
		if ( ! is_array( $data ) ) return '';

		// 1. OpenAI 标准格式：choices[0].message.content
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$content = $data['choices'][0]['message']['content'];
			if ( is_string( $content ) && $content !== '' ) {
				return $content;
			}
			if ( is_array( $content ) ) {
				$text = '';
				foreach ( $content as $item ) {
					if ( is_array( $item ) && ! empty( $item['text'] ) ) {
						$text .= $item['text'];
					} elseif ( is_string( $item ) ) {
						$text .= $item;
					}
				}
				if ( $text !== '' ) {
					return $text;
				}
			}
		}

		// 1.1 某些推理模型把最终 JSON 放在 reasoning_content
		$reasoning_json = self::extract_json_from_text( self::extract_reasoning_text( $data ) );
		if ( $reasoning_json !== '' ) {
			return $reasoning_json;
		}

		// 2. 旧版 Completions 格式：choices[0].text
		if ( ! empty( $data['choices'][0]['text'] ) ) {
			return $data['choices'][0]['text'];
		}

		// 3. Claude 原生格式：content[0].text
		if ( ! empty( $data['content'][0]['text'] ) ) {
			return $data['content'][0]['text'];
		}
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			$text = '';
			foreach ( $data['content'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['text'] ) ) {
					$text .= $item['text'];
				}
			}
			if ( $text !== '' ) {
				return $text;
			}
		}

		// 3.5 OpenAI Responses API：顶层 output_text 便利字段
		if ( ! empty( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return $data['output_text'];
		}
		// 3.6 OpenAI Responses API：output 为消息数组（取 message 块里的 text，跳过 reasoning）
		if ( isset( $data['output'] ) && is_array( $data['output'] ) && isset( $data['output'][0] ) ) {
			$rtext = '';
			foreach ( $data['output'] as $item ) {
				if ( ! is_array( $item ) ) continue;
				if ( isset( $item['type'] ) && $item['type'] === 'reasoning' ) continue;
				if ( isset( $item['content'] ) && is_array( $item['content'] ) ) {
					foreach ( $item['content'] as $c ) {
						if ( is_array( $c ) && ! empty( $c['text'] ) ) {
							$rtext .= $c['text'];
						} elseif ( is_string( $c ) ) {
							$rtext .= $c;
						}
					}
				}
			}
			if ( $rtext !== '' ) return $rtext;
		}
		// 3.7 Google Gemini：candidates[0].content.parts[].text
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			$gtext = '';
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$gtext .= $part['text'];
				}
			}
			if ( $gtext !== '' ) return $gtext;
		}

		// 4. 某些兼容 API：output 字段
		if ( ! empty( $data['output']['text'] ) ) {
			return $data['output']['text'];
		}
		if ( ! empty( $data['output']['content'] ) ) {
			if ( is_string( $data['output']['content'] ) ) {
				return $data['output']['content'];
			}
			if ( is_array( $data['output']['content'] ) ) {
				$text = '';
				foreach ( $data['output']['content'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['text'] ) ) {
						$text .= $item['text'];
					} elseif ( is_string( $item ) ) {
						$text .= $item;
					}
				}
				if ( $text !== '' ) {
					return $text;
				}
			}
		}
		if ( isset( $data['output'] ) && is_string( $data['output'] ) && $data['output'] !== '' ) {
			return $data['output'];
		}

		// 5. 简单格式：result / response / text
		if ( ! empty( $data['result'] ) && is_string( $data['result'] ) ) {
			return $data['result'];
		}
		if ( ! empty( $data['response'] ) && is_string( $data['response'] ) ) {
			return $data['response'];
		}
		if ( ! empty( $data['text'] ) && is_string( $data['text'] ) ) {
			return $data['text'];
		}

		return '';
	}

	private static function extract_reasoning_text( $data ) {
		if ( ! is_array( $data ) ) return '';

		// OpenAI 风格：choices[0].message.reasoning_content / reasoning
		if ( ! empty( $data['choices'][0]['message']['reasoning_content'] ) && is_string( $data['choices'][0]['message']['reasoning_content'] ) ) {
			return $data['choices'][0]['message']['reasoning_content'];
		}

		if ( ! empty( $data['choices'][0]['message']['reasoning'] ) && is_string( $data['choices'][0]['message']['reasoning'] ) ) {
			return $data['choices'][0]['message']['reasoning'];
		}

		// 顶层 reasoning_content / reasoning（部分中转 API）
		if ( ! empty( $data['reasoning_content'] ) && is_string( $data['reasoning_content'] ) ) {
			return $data['reasoning_content'];
		}

		if ( ! empty( $data['reasoning'] ) && is_string( $data['reasoning'] ) ) {
			return $data['reasoning'];
		}

		// Gemini 风格：candidates[0].content.parts[] 中 thought=true 的块的 text
		// gemini-2.5-flash-thinking 等推理模型把思考内容放在 thought 标记的 part 里
		if ( ! empty( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			$thought_text = '';
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && ! empty( $part['thought'] ) && ! empty( $part['text'] ) && is_string( $part['text'] ) ) {
					$thought_text .= $part['text'];
				}
			}
			if ( $thought_text !== '' ) {
				return $thought_text;
			}
		}

		return '';
	}

	private static function extract_finish_reason( $data ) {
		if ( ! is_array( $data ) ) return '';

		// OpenAI 风格
		if ( ! empty( $data['choices'][0]['finish_reason'] ) && is_string( $data['choices'][0]['finish_reason'] ) ) {
			return self::normalize_finish_reason( $data['choices'][0]['finish_reason'] );
		}
		if ( ! empty( $data['finish_reason'] ) && is_string( $data['finish_reason'] ) ) {
			return self::normalize_finish_reason( $data['finish_reason'] );
		}

		// Gemini 风格：candidates[0].finishReason（如 MAX_TOKENS / STOP / SAFETY）
		if ( ! empty( $data['candidates'][0]['finishReason'] ) && is_string( $data['candidates'][0]['finishReason'] ) ) {
			return self::normalize_finish_reason( $data['candidates'][0]['finishReason'] );
		}

		return '';
	}

	/**
	 * 归一化 finish_reason：MAX_TOKENS → length（统一后续判断），其余转小写
	 */
	private static function normalize_finish_reason( $fr ) {
		$fr = (string) $fr;
		if ( strcasecmp( $fr, 'MAX_TOKENS' ) === 0 ) {
			return 'length';
		}
		return strtolower( $fr );
	}

	private static function extract_json_from_text( $text ) {
		if ( ! is_string( $text ) || $text === '' ) return '';
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );

		$start = strpos( $text, '{' );
		if ( $start === false ) return '';

		$depth     = 0;
		$in_string = false;
		$escape    = false;
		$end       = -1;
		$len       = strlen( $text );
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $escape ) { $escape = false; continue; }
			if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
			if ( $ch === '"' ) { $in_string = ! $in_string; continue; }
			if ( $in_string ) continue;
			if ( $ch === '{' ) { $depth++; continue; }
			if ( $ch === '}' ) {
				$depth--;
				if ( $depth === 0 ) { $end = $i; break; }
			}
		}

		if ( $end === -1 ) return '';
		$json_str = substr( $text, $start, $end - $start + 1 );
		$data     = json_decode( $json_str, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			return $json_str;
		}
		return '';
	}

	/**
	 * 从 API 响应中提取错误信息（兼容 HTTP 200 但业务失败的接口）
	 *
	 * @param int          $code     HTTP 状态码
	 * @param array|mixed  $data     解码后的响应
	 * @param string       $raw_body 原始响应体
	 * @return string 错误信息；空字符串表示未识别到错误
	 */
	private static function extract_api_error_message( $code, $data, $raw_body = '' ) {
		if ( $code !== 200 ) {
			if ( is_array( $data ) ) {
				if ( ! empty( $data['error']['message'] ) ) {
					return $data['error']['message'];
				}
				if ( ! empty( $data['msg'] ) ) {
					return $data['msg'];
				}
			}
			return 'HTTP ' . $code . ' 错误';
		}

		if ( ! is_array( $data ) ) {
			return $raw_body === '' ? '响应格式异常' : '响应格式异常：' . mb_substr( $raw_body, 0, 200, 'UTF-8' );
		}

		if ( ! empty( $data['error']['message'] ) ) {
			return $data['error']['message'];
		}

		if ( isset( $data['status'] ) ) {
			$status = (string) $data['status'];
			if ( $status !== '' && $status !== '200' && $status !== '0' ) {
				$msg = ! empty( $data['msg'] ) ? $data['msg'] : ( '状态码 ' . $status );
				return $msg;
			}
		}

		if ( isset( $data['success'] ) && $data['success'] === false ) {
			if ( ! empty( $data['message'] ) ) {
				return $data['message'];
			}
			if ( ! empty( $data['msg'] ) ) {
				return $data['msg'];
			}
			return '接口返回 success=false';
		}

		if ( array_key_exists( 'body', $data ) && $data['body'] === null ) {
			if ( ! empty( $data['msg'] ) ) {
				return $data['msg'];
			}
		}

		return '';
	}

	/**
	 * 检测是否为 Claude API（model 名含 claude 或 API URL 含 anthropic）
	 *
	 * @param string $model
	 * @param string $api_url
	 * @return bool
	 */
	private static function is_claude_api( $model, $api_url ) {
		if ( stripos( $model, 'claude' ) !== false ) return true;
		if ( stripos( $api_url, 'anthropic' ) !== false ) return true;
		return false;
	}

	/**
	 * 构建 messages 数组（含 cache_control 标记）
	 *
	 * - Claude API：system_prompt（≥500字符）和 cache_user_prefix（≥500字符）会被标记为可缓存
	 * - OpenAI API：保持简单字符串结构，依赖 OpenAI 自动缓存（前缀完全相同时命中）
	 *
	 * @param string $system_prompt
	 * @param string $user_prompt
	 * @param string $cache_user_prefix user prompt 中的可缓存前缀
	 * @param bool   $is_claude
	 * @return array
	 */
	private static function build_messages( $system_prompt, $user_prompt, $cache_user_prefix, $is_claude ) {
		$messages = array();

		// Claude 显式缓存：system + user_prefix 标记 cache_control（≥500 字符约对应 ~300 tokens，再小不值得）
		if ( $is_claude ) {
			if ( ! empty( $system_prompt ) ) {
				if ( mb_strlen( $system_prompt, 'UTF-8' ) >= 500 ) {
					$messages[] = array(
						'role'    => 'system',
						'content' => array(
							array(
								'type'          => 'text',
								'text'          => $system_prompt,
								'cache_control' => array( 'type' => 'ephemeral' ),
							),
						),
					);
				} else {
					$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
				}
			}

			if ( ! empty( $cache_user_prefix ) && mb_strlen( $cache_user_prefix, 'UTF-8' ) >= 500
				&& strpos( $user_prompt, $cache_user_prefix ) === 0 ) {
				$dynamic_part = substr( $user_prompt, strlen( $cache_user_prefix ) );
				$messages[]   = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'          => 'text',
							'text'          => $cache_user_prefix,
							'cache_control' => array( 'type' => 'ephemeral' ),
						),
						array( 'type' => 'text', 'text' => $dynamic_part ),
					),
				);
			} else {
				$messages[] = array( 'role' => 'user', 'content' => $user_prompt );
			}

			return $messages;
		}

		// OpenAI 自动缓存：保持原 string 格式
		if ( ! empty( $system_prompt ) ) {
			$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_prompt );

		return $messages;
	}

	/**
	 * 替换提示词中的变量
	 *
	 * 注意：[内容] 会插入完整正文，可能导致正文在 system 和 user 中
	 * 各发送一次（token 翻倍）。仅在你确实需要在系统提示词中引用正文时使用。
	 * 此外，包含动态变量的 system_prompt 会破坏 prompt caching 命中率。
	 *
	 * @param string $prompt   含变量的提示词模板
	 * @param array  $vars     键值对：title, content, keywords, excerpt, description
	 * @return string
	 */
	public static function replace_vars( $prompt, $vars ) {
		// 归一化全角方括号［］→[]，兼容中文输入法下用户写［内容］［标题］等
		$prompt = str_replace( array( '［', '］' ), array( '[', ']' ), $prompt );
		$map = array(
			'[标题]'   => $vars['title']       ?? '',
			'[内容]'   => $vars['content']     ?? '',
			'[关键词]' => $vars['keywords']    ?? '',
			'[摘要]'   => $vars['excerpt']     ?? '',
			'[描述]'   => $vars['description'] ?? '',
		);
		return str_replace( array_keys( $map ), array_values( $map ), $prompt );
	}

	/**
	 * 归一化关键词分隔符：把全角逗号、顿号、分号等统一为半角逗号
	 * 中国用户常输入「，」「、」「；」，导致 explode(',') 取第一个关键词失败
	 *
	 * @param string $keywords 原始关键词字符串
	 * @return string 归一化后的字符串（分隔符统一为半角逗号）
	 */
	public static function normalize_keywords( $keywords ) {
		$keywords = (string) $keywords;
		$keywords = str_replace( array( '，', '、', '；', ';', '｜', '|' ), ',', $keywords );
		return $keywords;
	}

	/**
	 * 取关键词字符串的第一个关键词（已做分隔符归一化）
	 *
	 * @param string $keywords 关键词字符串
	 * @return string 第一个关键词
	 */
	public static function first_keyword( $keywords ) {
		$keywords = self::normalize_keywords( $keywords );
		$first = trim( explode( ',', $keywords )[0] ?? '' );
		return $first;
	}

	/**
	 * 用 build_*_prompt 返回的 prompts 数组发起 AI 调用
	 * 自动把 cache_user_prefix 注入到 $extra 实现 prompt caching
	 *
	 * @param array $prompts { system, user, cache_user_prefix? }
	 * @param array $extra
	 * @return array|WP_Error
	 */
	public static function call_prompts( $prompts, $extra = array() ) {
		if ( ! empty( $prompts['cache_user_prefix'] ) && empty( $extra['cache_user_prefix'] ) ) {
			$extra['cache_user_prefix'] = $prompts['cache_user_prefix'];
		}
		// 短字段重新优化场景使用更高温度，避免 AI 返回近似原文
		if ( ! empty( $prompts['_suggest_temperature'] ) && empty( $extra['temperature'] ) ) {
			$extra['temperature'] = $prompts['_suggest_temperature'];
		}
		return self::call( $prompts['user'], $prompts['system'] ?? '', $extra );
	}

	/**
	 * 返回 anti-AI 写作风格规则（注入到所有涉及正文的 Prompt 中）
	 */
	private static function get_writing_style_rules() {
		$r  = "\n【风格】长短句交错（短句≤8字偶尔独立成行）；偶尔用反问句或感叹句；像真人博主，允许口语化表达（「说实话」「我觉得」等）。\n";
		$r .= "【数据保护】原文中的数字、数据、单位（如「500亿美元」「3.2亿用户」「增长15%」）必须原样保留，标题和正文中都不得缩写、省略或改写单位；年份按【时间基准】处理：仅时效性年份（「XX年最新」「截至XX年」等）更新为当前年份，历史事实/统计数据的年份原样保留。\n";
		$r .= "【过渡词】把书面过渡词换成口语连接（此外→另外、然而→不过、因此→所以、综上所述→总之），保持句子之间的逻辑连贯，不要生硬删除连接词导致句子断裂。\n";
		$r .= "【正文边界】content 仅输出 <body> 内的正文片段，禁止包含 <html>/<head>/<body>/<meta>/<title>/<link>/<script>/<style>/<!DOCTYPE> 等文档级标签。\n";
		$r .= "【空段落】禁止输出空标签（如 <p></p>、<p>&nbsp;</p>、<p><br></p>、<h2></h2>），每个标签内必须有实际文字内容。\n";
		$r .= "【包装标签】禁止使用 <div>/<span>/<font>/<section> 等无语义包装标签和内联 style 属性；正文只使用语义标签：h2/h3/h4、p、ul/ol/li、strong/em、a、img、table/tr/td、blockquote、hr。\n";
		return $r;
	}

	/**
	 * 时间基准指令：告诉 AI 真实当前日期，时效性年份以当前年份为准
	 *
	 * 调用方把它拼进 user prompt 的动态区（cache 前缀之外）：
	 * 内容随日期变化，放进可缓存前缀会击穿 Prompt 缓存。
	 *
	 * @return string
	 */
	private static function get_time_base_rule() {
		$today = current_time( 'Y年n月j日' );
		$year  = current_time( 'Y' );
		return "\n【时间基准】今天是{$today}。「今年」「最新」「截至目前」等时间表述一律以今天为准；"
			. "标题和正文中表达时效性的年份（如「{$year}年最新」「{$year}年榜单/趋势/排行」「截至{$year}年」）一律使用{$year}年，不得沿用旧年份；"
			. "历史事件与统计数据的年份（如「2024年全球市场规模达500亿美元」）属于事实本身，保持原样，不得虚构数据。\n";
	}

	/**
	 * 正文净化入口
	 *
	 * 本插件仅在后台、由管理员操作，正文内容由站长/AI 产生且可信；管理员拥有
	 * unfiltered_html 权限，WordPress 自身保存时本就不过滤。为保证 AI 优化前后
	 * 内容字符级一致——任何正文级标签（H2/H3、figure/img、iframe/video/svg、
	 * 短代码、自定义标签等）都不被删改，此处不做白名单过滤。
	 *
	 * 但仍兜底剥除「文档级标签」——AI 偶发输出完整 HTML 文档结构
	 * （<html><head><body><meta><title><link><style><script> 等），这些标签
	 * 不属于正文内容，如果原样保留会破坏前台页面渲染（head/body 嵌入正文导致
	 * 布局错乱、meta/title 重复）。用正则剥除文档骨架，只保留 body 内的正文片段。
	 *
	 * @param string         $html              正文 HTML
	 * @param bool|null      $strip_wrapper_tags 是否剥除无语义包装标签（null = 读取设置 strip_wrapper_tags，默认开）
	 * @return string 剥除文档级标签后的正文 HTML
	 */
	public static function sanitize_content( $html, $strip_wrapper_tags = null ) {
		if ( null === $html ) return '';
		$html = (string) $html;
		if ( $html === '' ) return '';

		$original = $html;  // 保留原始文本，正则回溯溢出时回退（避免长文被清空）

		// 如果含 <body> 标签，只提取 body 内部内容（丢弃 head 及文档骨架）
		if ( stripos( $html, '<body' ) !== false ) {
			$m = preg_match( '#<body[^>]*>(.*)</body>#is', $html, $match );
			if ( $m === 1 ) {
				$html = $match[1];
			}
		}

		// 剥除文档级标签（开标签 + 闭合标签 + 自闭合标签），保留正文内容
		// html/head/body 是文档骨架不属于正文；meta/title/link/base 是 head 专属标签；
		// script/style 剥标签及内容（防 XSS / 防样式泄漏到正文外层页面）
		$stripped = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		if ( $stripped !== null ) $html = $stripped;  // null = 回溯溢出，保留原值

		$stripped = preg_replace( '#</?(html|head|body|meta|title|link|base|!doctype|!DOCTYPE)[^>]*>#i', '', $html );
		if ( $stripped !== null ) $html = $stripped;  // null = 回溯溢出，保留原值

		// 剥除无语义包装标签（v2.0.11 新增，设置页「清理无用标签」可关）。
		// AI 常用 <div>/<p>/<span>/<section>/<font> 包装正文，写入 WP 后这些标签在块编辑器里
		// 让全篇落入「经典块」且前台布局样式失控；而 p 段落由 wpautop 依据空行在前台自动重建，
		// 无需 AI 显式输出。规则：
		//   块级包装（p/div/section/article/main/aside/nav/header/footer）闭合标签 → 空行（保留段落
		//   结构，前台 wpautop 自动重建 <p>），开标签（含属性/自闭合斜杠）直接剥除；
		//   行内包装（span/font）开闭标签直接剥除，不产生换行。
		// v2.0.15 起额外清理「保留标签」上的 class 与 data-* 属性（data-src/data-srcset 懒加载先提升为
		// src/srcset 再剥除，避免图片失效）。
		// HTML 注释（图片/嵌入占位符 <!--WAISG_IMG_N-->、Gutenberg 块注释、代码占位符）不含
		// 标签名，正则天然不匹配，原样保留；pre/code 内容先摘出最后还原，防止代码示例里的
		// 同名标签（字面文本）被误剥。保留 h1-h6、列表、表格、img/a/strong/em/blockquote 等语义标签。
		if ( null === $strip_wrapper_tags ) {
			$strip_wrapper_tags = (bool) WAISG_Settings::get( 'strip_wrapper_tags', 1 );
		}
		if ( $strip_wrapper_tags ) {
			// pre/code 内容先摘出（代码示例中可能出现同名标签，属字面文本不可剥）
			$code_blocks = array();
			if ( stripos( $html, '<pre' ) !== false || stripos( $html, '<code' ) !== false ) {
				$guarded = preg_replace_callback(
					'#<(pre|code)\b[^>]*>.*?</\1>#is',
					function ( $m ) use ( &$code_blocks ) {
						$key = '<!--WAISG_CODE_' . count( $code_blocks ) . '-->';
						$code_blocks[ $key ] = $m[0];
						return $key;
					},
					$html
				);
				if ( $guarded !== null ) $html = $guarded;  // null = 回溯溢出，保留原值
			}

			// 块级包装标签：闭合 → 空行；开标签（含属性）剥除。\b 防止误伤 <picture> 等 p 开头的标签
			$block_wrappers = 'p|div|section|article|main|aside|nav|header|footer';
			$stripped = preg_replace( '#</(?:' . $block_wrappers . ')\b[^>]*>#i', "\n\n", $html );
			if ( $stripped !== null ) $html = $stripped;
			$stripped = preg_replace( '#<(?:' . $block_wrappers . ')\b[^>]*>#i', '', $html );
			if ( $stripped !== null ) $html = $stripped;

			// 行内包装标签：开闭均剥除，不产生换行
			$stripped = preg_replace( '#</?(?:span|font)\b[^>]*>#i', '', $html );
			if ( $stripped !== null ) $html = $stripped;

			// —— 属性级清理（v2.0.15 新增）——
			// 「清理无用标签」不仅要剥掉无语义标签，也要清掉「保留标签」（img/a/h2/table/li…）上
			// 残留的 class="…" 与 data-src/data-srcset 等懒加载属性，否则脏属性会随语义标签写入正文，
			// 在块编辑器里仍触发「经典块」、前台样式失控。因 pre/code 已在前面摘出为占位符，此处不会
			// 误伤代码示例里的字面 class=/data-src=。
			// 图片懒加载常把真实地址放 data-src、src 放占位图，故先把 data-src/data-srcset 提升为
			// src/srcset（仅当 src 缺失或为 data:/blank/placeholder/lazy/spacer 等占位）再统一剥除 data-*，
			// 避免清理后图片失效。
			$stripped = preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( $m ) {
					$tag = $m[0];
					$get = function ( $attr ) use ( $tag ) {
						return preg_match( '#\s' . $attr . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $x )
							? trim( $x[1], "\"'" ) : null;
					};
					$dsrc    = $get( 'data-src' );
					$dsrcset = $get( 'data-srcset' );
					$src     = $get( 'src' );
					// data-src → src：无 src 直接补；src 为空或占位图时顶替
					if ( null !== $dsrc && '' !== $dsrc ) {
						$is_placeholder = ( null === $src || '' === $src
							|| preg_match( '#^data:|blank|placeholder|lazy|spacer|1x1|loading#i', $src ) );
						if ( $is_placeholder ) {
							if ( null === $src ) {
								$tag = preg_replace( '#<img\b#i', '<img src="' . esc_attr( $dsrc ) . '"', $tag, 1 );
							} else {
								$tag = preg_replace( '#\ssrc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', ' src="' . esc_attr( $dsrc ) . '"', $tag, 1 );
							}
						}
					}
					// data-srcset → srcset：仅当原无 srcset
					if ( null !== $dsrcset && '' !== $dsrcset && ! preg_match( '#\ssrcset\s*=#i', $tag ) ) {
						$tag = preg_replace( '#<img\b#i', '<img srcset="' . esc_attr( $dsrcset ) . '"', $tag, 1 );
					}
					return $tag;
				},
				$html
			);
			if ( $stripped !== null ) $html = $stripped;  // null = 回溯溢出，保留原值

			// 统一剥除保留标签上的 class 与全部 data-* 属性（含 data-src/data-srcset 等懒加载）
			$stripped = preg_replace( '#\sclass\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
			if ( $stripped !== null ) $html = $stripped;
			$stripped = preg_replace( '#\sdata-[\w-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
			if ( $stripped !== null ) $html = $stripped;

			// 剥除后孤立成行的 &nbsp;（原 <p>&nbsp;</p> 空段落残留）整行删除，避免幽灵空白行；
			// 行中间的 &nbsp;（正常间距）不受影响——仅匹配整行只有空白实体的行。
			// 注意 \# 转义：&#160; 中的 # 会被当成正则分隔符
			$stripped = preg_replace( '#^[ \t]*(?:&nbsp;|&\#160;|&\#xa0;)+[ \t]*$#im', '', $html );
			if ( $stripped !== null ) $html = $stripped;

			// 剥除产生的 3+ 连续空行压成单个空行（段落分隔），避免成片空白
			$collapsed = preg_replace( '#(?:\r?\n[ \t]*){3,}#', "\n\n", $html );
			if ( $collapsed !== null ) $html = $collapsed;

			// 还原 pre/code 块
			if ( $code_blocks ) {
				$html = strtr( $html, $code_blocks );
			}
		}

		// 清理「空标签」——AI 返回正文里常见 <p></p>、<p>&nbsp;</p>、<p><br></p> 这类
		// 空段落（无效嵌套经解析纠正后也会产生幽灵空 p），渲染出来是成片多余空行。
		// 只删「剥掉标签和空白后没有任何文字」的空壳；含图片/iframe 等媒体、占位符
		// 注释、短代码文本的块原样保留，保证图片还原与内容一致性不受影响。
		$cleaned = preg_replace_callback(
			'#<(p|h[1-6]|li|blockquote)([^>]*)>(.*?)</\1>#is',
			function ( $m ) {
				// 含注释（图片/嵌入占位符 <!--WAISG_IMG_x-->、Gutenberg 块注释）一律保留，
				// 否则占位符会连同空壳标签一起被删，restore_images 无法还原图片
				if ( strpos( $m[0], '<!--' ) !== false ) return $m[0];
				// 内含媒体/嵌入/结构化标签（图、表、列表、分隔线、布局 div 等）一律保留
				if ( preg_match( '#<(img|iframe|video|audio|svg|object|embed|figure|table|ul|ol|blockquote|hr|div|input|button|form)\b#i', $m[3] ) ) return $m[0];
				// 剩余内容剥掉标签与空白（含 &nbsp; 实体 / 全角空格）后为空 → 整个标签删除
				$inner = strip_tags( $m[3] );
				$inner = str_replace( array( '&nbsp;', '&#160;', '&#xa0;' ), '', $inner );
				$inner = preg_replace( '/[\s\x{00A0}]+/u', '', $inner );
				return ( $inner === '' ) ? '' : $m[0];
			},
			$html
		);
		if ( $cleaned !== null ) $html = $cleaned;  // null = 回溯溢出，保留原值

		return trim( $html );
	}

	/**
	 * 将正文中「易被 AI 改写时丢失」的内容替换为占位符
	 *
	 * 不仅图片：还包括 iframe/embed/object 等嵌入、video/audio 媒体块，
	 * 以及短代码 [xxx]…[/xxx] / [xxx]。AI 只会看到占位符注释，碰不到原内容，
	 * 优化完成后由 restore_images() 原样还原，确保内容零缺失。
	 * （方法名沿用 protect_images 以兼容多处调用与文档。）
	 *
	 * @param string $html 正文 HTML
	 * @return array { 'html' => 替换后的 HTML, 'map' => 占位符 => 原始内容映射 }
	 */
	public static function protect_images( $html ) {
		$map = array();

		// 用 DOMDocument 解析，按节点序逐个替换为占位符注释。
		// 旧版用正则 `.*?</figure>` 对嵌套标签会贪婪到第一个结束符，导致外层被截断、
		// 内层裸露（如 <figure><figure>..</figure></figure> 只护到内层，外层拆开）。
		// DOM 解析天然处理嵌套，且能覆盖 figure/iframe/video/audio/object/embed/img 全标签。
		if ( ! class_exists( 'DOMDocument' ) || empty( $html ) ) {
			// 兜底：DOMDocument 不可用（极罕见）时退回正则，至少单层标签能护住
			$tag_pattern = '#('
				. '<figure[^>]*>.*?</figure>'
				. '|<iframe[^>]*>.*?</iframe>'
				. '|<video[^>]*>.*?</video>'
				. '|<audio[^>]*>.*?</audio>'
				. '|<object[^>]*>.*?</object>'
				. '|<embed[^>]*/?>'
				. '|<img[^>]*/?>'
				. ')#is';
			$result = preg_replace_callback(
				$tag_pattern,
				function ( $m ) use ( &$map ) {
					$i = count( $map );
					$placeholder = "<!--WAISG_IMG_{$i}-->";
					$map[ $placeholder ] = $m[0];
					return $placeholder;
				},
				$html
			);
		} else {
			$doc = new DOMDocument();
			// UTF-8 声明避免中文乱码；压制 loadHTML 的不规范标签警告
			@$doc->loadHTML(
				'<?xml encoding="UTF-8"><div>' . $html . '</div>',
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
			);

			// 收集要替换的节点（先收集再替换，避免在遍历中修改 NodeList 引起错位）
			$targets  = array();
			$tagNames = array( 'figure', 'iframe', 'video', 'audio', 'object', 'embed', 'img' );
			$xpath    = new DOMXPath( $doc );
			foreach ( $tagNames as $tagName ) {
				foreach ( $xpath->query( '//' . $tagName ) as $node ) {
					$targets[] = $node;
				}
			}

			// 逐个用占位符注释替换。外层 figure 内含 img 时，外层先入 targets 则整体护住，
			// 内层 img 已是外层的后代不会再独立出现于 DOM 树（DOM 遍历不会重复同一节点）。
			foreach ( $targets as $node ) {
				// 跳过已被祖先节点包含的——若祖先也是 targets，整体由祖先护住，子孙不重复占位
				$ancestor = $node->parentNode;
				$skip     = false;
				while ( $ancestor ) {
					if ( in_array( $ancestor->nodeName, $tagNames, true ) ) {
						$skip = true;
						break;
					}
					$ancestor = $ancestor->parentNode;
				}
				if ( $skip ) continue;

				$i          = count( $map );
				$placeholder = "<!--WAISG_IMG_{$i}-->";
				// 保存原始 HTML（saveHTML 会规范化自闭合标签，与原文略有差异但语义等价）
				$map[ $placeholder ] = $doc->saveHTML( $node );
				// 用注释节点替换原节点
				$comment = $doc->createComment( str_replace( array( '<!--', '-->' ), '', $placeholder ) );
				$node->parentNode->replaceChild( $comment, $node );
			}

			// 提取改造后的 HTML（去掉 loadHTML 自动加的 div wrapper）
			$result = '';
			foreach ( $doc->documentElement->childNodes as $child ) {
				$result .= $doc->saveHTML( $child );
			}
		}

		// 短代码（含成对 [xx]...[/xx] 与自闭合 [xx]），优先用站点已注册的
		// 短代码精确正则；取不到则退回通用正则。转义的 [[...]] 不处理。
		$sc_regex = function_exists( 'get_shortcode_regex' ) ? get_shortcode_regex() : '';
		if ( $sc_regex !== '' ) {
			$sc_pattern = '/' . $sc_regex . '/s';
		} else {
			// 无已注册短代码时用通用正则（不会退化成空正则导致 OOM）
			$sc_pattern = '/\[(\[?)([a-zA-Z0-9_\-]+)(?![\w-])[^\]]*?(?:\](?:.*?\[\/\2\])?|\/\])(\]?)/s';
		}
		$result = preg_replace_callback(
			$sc_pattern,
			function ( $m ) use ( &$map ) {
				// get_shortcode_regex 下：$m[1]、$m[6] 为转义括号，存在表示是 [[..]] 转义，跳过
				if ( isset( $m[1], $m[6] ) && '[' === $m[1] && ']' === $m[6] ) {
					return $m[0];
				}
				$i = count( $map );
				$placeholder = "<!--WAISG_IMG_{$i}-->";
				$map[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$result
		);

		return array( 'html' => $result, 'map' => $map );
	}

	/**
	 * 将占位符还原为原始图片标签
	 * 如果 AI 丢弃了某些占位符，自动将丢失的图片补回正文
	 *
	 * @param string $html 含占位符的 HTML
	 * @param array  $map  protect_images 返回的映射
	 * @return string
	 */
	public static function restore_images( $html, $map ) {
		if ( empty( $map ) ) return $html;

		// 收集 AI 丢弃的图片（占位符不在返回内容中）
		$missing = array();
		foreach ( $map as $placeholder => $original_img ) {
			if ( strpos( $html, $placeholder ) === false ) {
				$missing[] = $original_img;
			}
		}

		// 还原存在的占位符
		$result = str_replace( array_keys( $map ), array_values( $map ), $html );

		// 把 AI 丢弃的图片补回正文
		if ( ! empty( $missing ) ) {
			foreach ( $missing as $img_html ) {
				$inserted = false;
				// 尝试插入到尚无图片紧跟的 h2/h3 标签后面
				$result = preg_replace_callback(
					'#(</h[23]>)(?!\s*<(?:figure|img))#i',
					function ( $m ) use ( $img_html, &$inserted ) {
						if ( $inserted ) return $m[0];
						$inserted = true;
						return $m[1] . "\n" . $img_html;
					},
					$result
				);
				// 没找到合适位置，追加到末尾
				if ( ! $inserted ) {
					$result .= "\n" . $img_html;
				}
			}
		}

		return $result;
	}

	/**
	 * 根据内容长度估算合理的 max_tokens
	 * 避免长文章因 token 上限被截断导致图片丢失
	 *
	 * @param string $content 正文内容
	 * @return int 建议的 max_tokens 值（不低于用户设置值）
	 */
	public static function estimate_max_tokens( $content ) {
		$configured = absint( WAISG_Settings::get( 'max_tokens', 4096 ) );
		if ( empty( $content ) ) return $configured;

		// 按内容里 ASCII / 非 ASCII 比例做加权估算，兼容英文/多语言站点。
		// - 中文（CJK）约 1 token / 1.5 字符
		// - 英文/拉丁字符约 1 token / 4 字符
		// 旧公式只按中文比例估算，英文站点长文会高算 max_tokens（浪费）或日韩文会低算（截断）。
		// 统计 UTF-8 多字节字符数 ≈ 非 ASCII（CJK/日韩等），单字节字符数 ≈ ASCII（英文/符号）。
		$total_len = mb_strlen( $content, 'UTF-8' );
		$ascii_len = strlen( preg_replace( '/[^\x00-\x7F]/', '', $content ) ); // 字节数 = ASCII 字符数
		$non_ascii = $total_len - $ascii_len;

		// 中文部分 token：1 token / 1.5 字符；英文部分 token：1 token / 4 字符
		$cjk_tokens  = (int) ceil( $non_ascii / 1.5 );
		$ascii_tokens = (int) ceil( $ascii_len / 4 );
		$base_tokens  = $cjk_tokens + $ascii_tokens;

		// 留 50% 扩写余量（AI 可能添加段落/FAQ/表格），+1500（SEO 字段 + HTML + JSON 结构开销）
		// 旧写法 ceil(len/1.5*1.5) 先除后乘自相抵消，余量从未生效，此处拆两步修正。
		$estimated = (int) ceil( $base_tokens * 1.5 ) + 1500;
		return max( $configured, $estimated );
	}

	/**
	 * 根据内容长度估算合理的 timeout
	 * 长文章生成耗时更长，需要更长的请求超时，避免 cURL 提前断开
	 *
	 * @param string $content 正文内容
	 * @return int 建议的 timeout 秒数（不低于用户设置值，上限 600 秒）
	 */
	public static function estimate_timeout( $content ) {
		$configured = absint( WAISG_Settings::get( 'timeout', 60 ) );
		if ( empty( $content ) ) return $configured;

		// 假设 AI 输出速度约 20 字符/秒（保守估计，考虑扩写），另加 40 秒首字延迟
		$estimated = (int) ceil( mb_strlen( $content, 'UTF-8' ) / 20 ) + 40;
		// 上限 600 秒
		return min( 600, max( $configured, $estimated ) );
	}

	/**
	 * 构建长文场景的 $extra 参数（同时设置 max_tokens 和 timeout）
	 *
	 * @param string $content 正文内容
	 * @return array
	 */
	public static function build_long_content_extra( $content ) {
		return array(
			'max_tokens' => self::estimate_max_tokens( $content ),
			'timeout'    => self::estimate_timeout( $content ),
		);
	}

	/**
	 * 替换正文中的 AI 高频词为更自然的表达
	 * 优先使用用户自定义词库（设置中配置），留空则使用内置默认词库
	 *
	 * @param string $text 正文 HTML
	 * @return string
	 */
	public static function filter_ai_phrases( $text ) {
		if ( empty( $text ) ) return $text;

		// 兜底剥除文档级标签（html/head/body/meta/title 等）——AI 偶发输出完整文档结构，
		// sanitize_content() 在写入数据库时才会被调用，但 AJAX 返回前端时走的是这条链路，
		// 如果不在这里兜底，编辑器里就会看到 <body> 等文档标签。
		// 放在最前面：先剥文档标签再做词库替换，避免文档标签干扰替换逻辑。
		$text = self::sanitize_content( $text );

		// 如果用户关闭了 AI 高频词替换，直接返回
		if ( ! WAISG_Settings::get( 'ai_phrases_enabled', 1 ) ) {
			return $text;
		}

		// 保护不应被替换的块：code/pre/kbd/samp（代码示例）、a 标签的 href/属性
		// 用占位符暂存，替换完再还原，避免破坏代码示例里的"此外，"或 URL 里的"十分"等
		$original_text = $text;  // 保留原始文本，preg_replace_callback 失败时回退
		$protect_pattern = '#(<(?:code|pre|kbd|samp)[^>]*>.*?</(?:code|pre|kbd|samp)>|<a\s[^>]*>.*?</a>)#is';
		$protect_map = array();
		$text = preg_replace_callback(
			$protect_pattern,
			function ( $m ) use ( &$protect_map ) {
				$key = '<!--WAISG_PROT_' . count( $protect_map ) . '-->';
				$protect_map[ $key ] = $m[0];
				return $key;
			},
			$text
		);
		// preg_replace_callback 在正则回溯溢出时返回 null，回退到原始文本避免清空正文
		if ( $text === null ) {
			return $original_text;
		}

		// 获取替换规则（优先用户自定义，为空则用内置默认）
		$replacements = self::get_phrase_replacements();

		if ( ! empty( $replacements ) ) {
			// 用 strtr 替代 str_replace：strtr 不会对替换结果二次匹配，
			// 避免链式覆盖（如 此外→另外，紧接着 另外→还有，导致 此外最终变成 还有）。
			$text = strtr( $text, $replacements );
		}

		// 正则安全替换（避免破坏"十分钟""相当于"等正常词汇）
		$regex_replacements = array(
			'/十分(?=[重要好大多少强弱快慢高低难易])/'   => '很',
			'/非常(?=[重要好大多少强弱快慢高低难易])/'   => '很',
			'/极为(?=[重要])/'                           => '很',
		);
		foreach ( $regex_replacements as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		// 还原保护块
		if ( ! empty( $protect_map ) ) {
			$text = str_replace( array_keys( $protect_map ), array_values( $protect_map ), $text );
		}

		return $text;
	}

	/**
	 * 获取替换规则数组
	 * 用户自定义不为空时使用自定义；为空时使用内置默认
	 *
	 * @return array [ '原词' => '替换词', ... ]
	 */
	private static function get_phrase_replacements() {
		$custom = WAISG_Settings::get( 'ai_phrases_custom', '' );

		if ( ! empty( trim( $custom ) ) ) {
			return self::parse_phrases_text( $custom );
		}

		return self::get_default_phrases();
	}

	/**
	 * 解析文本格式的词库为数组
	 * 格式：每行 "原词|替换词"，# 开头为注释，空行忽略
	 *
	 * @param string $text
	 * @return array
	 */
	public static function parse_phrases_text( $text ) {
		$rules = array();
		$lines = explode( "\n", $text );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 ) continue;

			$parts = explode( '|', $line, 2 );
			if ( count( $parts ) < 1 || trim( $parts[0] ) === '' ) continue;

			$from = $parts[0];
			$to   = isset( $parts[1] ) ? $parts[1] : '';
			$rules[ $from ] = $to;
		}

		return $rules;
	}

	/**
	 * 获取内置默认的 AI 高频词替换规则
	 *
	 * @return array [ '原词' => '替换词', ... ]
	 */
	public static function get_default_phrases() {
		return array(
			// === 机械化过渡词 → 替换为自然口语连接（不再直接删除，避免散装句子）===
			'此外，'           => '另外，',
			'此外, '           => '另外，',
			'另外，'           => '还有，',
			'再者，'           => '还有，',
			'与此同时，'       => '同时',
			'同时，'           => '另外，',
			'然而，'           => '不过，',
			'因此，'           => '所以，',
			'于是，'           => '这样就，',
			'综上所述，'       => '总之，',
			'总而言之，'       => '说到底，',
			'总的来说，'       => '总而言之，',
			'总结来说，'       => '总之，',
			'换言之，'         => '也就是说，',
			'换句话说，'       => '也就是说，',
			'具体而言，'       => '具体来说，',
			'具体来说，'       => '简单来说，',
			'具体来讲，'       => '简单来说，',

			// === 学术化套话 → 替换为自然引导（不再直接删除，避免散装句子）===
			'值得注意的是，'   => '要注意的是，',
			'值得一提的是，'   => '有意思的是，',
			'需要指出的是，'   => '需要说明的是，',
			'需要强调的是，'   => '重点是，',
			'需要注意的是，'   => '要注意，',
			'不可忽视的是，'   => '不能忽略的是，',
			'不容忽视的是，'   => '不能忽略的是，',
			'毫无疑问，'       => '当然，',
			'毋庸置疑，'       => '当然，',
			'众所周知，'       => '大家都知道，',
			'显而易见，'       => '很明显，',
			'不言而喻，'       => '很显然，',
			'事实上，'         => '实际上，',
			'实际上，'         => '其实，',
			'本质上，'         => '从根本上说，',
			'从某种意义上说，' => '可以说，',
			'从某种程度上说，' => '可以说，',
			'某种程度上，'     => '可以说，',

			// === 套话短语 → 替换为更自然的表达 ===
			'至关重要'         => '很关键',
			'极其重要'         => '很重要',
			'尤为重要'         => '特别重要',
			'重要的是'         => '关键是',
			'在当今社会，'     => '现在，',
			'在现代社会，'     => '现在，',
			'在当今时代，'     => '现在，',
			'在这个时代，'     => '现在，',
			'随着时代的发展，' => '这些年，',
			'随着社会的发展，' => '这些年，',
			'随着科技的发展，' => '随着技术进步，',

			// === 模板化结论句式 → 替换为自然结论连接 ===
			'由此可见，'       => '可见',
			'由此可知，'       => '可以看出，',
			'可以看出，'       => '不难看出，',
			'可以发现，'       => '不难发现，',
			'不难看出，'       => '可以看出来，',
			'不难发现，'       => '可以发现，',
			'我们可以看到'     => '可以看到',
			'我们可以发现'     => '可以发现',
		);
	}

	/**
	 * 获取默认词库的文本格式（用于设置页显示/恢复）
	 *
	 * @return string
	 */
	public static function get_default_phrases_text() {
		$lines = array();
		$lines[] = '# AI 高频词替换词库（默认）';
		$lines[] = '# 格式：原词|替换词（优先替换为自然口语，替换词留空=删除该词）';
		$lines[] = '# 注意：删除过渡词会破坏句子连贯性，尽量替换而非删除';
		$lines[] = '';
		$lines[] = '# ── 机械化过渡词（替换为口语连接，不删除） ──';

		$defaults = self::get_default_phrases();
		$sections = array(
			'此外，'           => '# ── 机械化过渡词（替换为口语连接，不删除） ──',
			'值得注意的是，'   => '# ── 学术化套话（替换为自然引出，不删除） ──',
			'至关重要'         => '# ── 套话短语（替换为口语表达） ──',
			'由此可见，'       => '# ── 模板化结论句式（替换为自然结论，不删除） ──',
		);

		$current_section = '';
		foreach ( $defaults as $from => $to ) {
			if ( isset( $sections[ $from ] ) && $sections[ $from ] !== $current_section ) {
				$current_section = $sections[ $from ];
				if ( $from !== '此外，' ) { // 第一个分类已在上面输出
					$lines[] = '';
					$lines[] = $current_section;
				}
			}
			$lines[] = $from . '|' . $to;
		}

		return implode( "\n", $lines );
	}

	/**
	 * 对正文进行二次润色，打散 AI 统计特征
	 * 短文（< 300 字符）不做润色
	 * 长文（> 3000 字符）按 H2/H3 边界分段处理，避免单次 token 不够
	 *
	 * @param string $content        正文 HTML
	 * @param string $model_override 当前主任务使用的模型偏好（main/lightweight/''）
	 *                               仅当设置「润色模型 = 跟随主任务模型」时生效
	 * @return string 润色后的正文（失败/过短则返回原文）
	 */
	public static function humanize( $content, $model_override = '' ) {
		if ( empty( $content ) ) return $content;

		$plain_len = mb_strlen( strip_tags( $content ), 'UTF-8' );

		// 短文跳过 humanize 节省 token（短文没有明显 AI 特征）
		if ( $plain_len < 300 ) {
			return $content;
		}

		// 长文（纯文本 >3000 字符）按段落拆分润色，提高成功率并避免 token 不够
		if ( $plain_len > 3000 ) {
			return self::humanize_chunked( $content, $model_override );
		}

		return self::humanize_single( $content, $model_override );
	}

	/**
	 * 根据「润色模型」设置 + 主任务偏好，解析最终要用的模型名
	 * - lightweight: 强制轻量模型（无配置则降级主模型）
	 * - main:        强制主模型
	 * - follow:      跟随主任务（main/lightweight 由 $model_override 决定）
	 *
	 * @param string $model_override main/lightweight/''（仅在 follow 模式生效）
	 * @return string 最终模型名，可直接塞进 extra['model']
	 */
	private static function resolve_humanize_model( $model_override = '' ) {
		$pref = WAISG_Settings::get( 'humanize_model', 'lightweight' );
		$main = WAISG_Settings::get( 'model', 'gpt-4o' );
		$lm   = WAISG_Settings::get( 'lightweight_model', '' );

		if ( $pref === 'main' ) {
			return $main;
		}
		if ( $pref === 'follow' ) {
			// 跟随主任务：override 为空则按 batch_model 默认值（保持与主流程一致）
			$source = $model_override ?: WAISG_Settings::get( 'batch_model', 'main' );
			if ( $source === 'lightweight' && ! empty( $lm ) ) {
				return $lm;
			}
			return $main;
		}
		// 默认 lightweight：有配置就用，否则降级主模型
		return ! empty( $lm ) ? $lm : $main;
	}

	/**
	 * 单次润色（不分段）
	 *
	 * @param string $content        HTML 正文
	 * @param string $model_override 主任务模型偏好（main/lightweight/''）
	 * @return string
	 */
	private static function humanize_single( $content, $model_override = '' ) {
		// 保护图片：替换为占位符，防止 AI 润色时丢失
		$protected    = self::protect_images( $content );
		$safe_content = $protected['html'];

		$system = '你是真人博主，把机器化文本改写成自然口语风格。';

		// 使用用户自定义润色提示词（含 [正文] 占位符），为空则用内置默认
		$custom_prompt = trim( WAISG_Settings::get( 'humanize_prompt', '' ) );
		if ( ! empty( $custom_prompt ) && strpos( $custom_prompt, '[正文]' ) !== false ) {
			// 自定义 prompt: [正文] 前的部分作为可缓存前缀
			$prefix_end = strpos( $custom_prompt, '[正文]' );
			$cache_prefix = substr( $custom_prompt, 0, $prefix_end );
			$user = str_replace( '[正文]', $safe_content, $custom_prompt );
		} else {
			$cache_prefix = self::get_humanize_instruction_prefix();
			$user = $cache_prefix . $safe_content;
		}

		// 根据「润色模型」设置 + 主任务偏好解析模型，再动态调整 max_tokens / timeout
		$extra = self::build_long_content_extra( $safe_content );
		$extra['temperature'] = 0.75;
		$extra['cache_user_prefix'] = $cache_prefix;
		$extra['model'] = self::resolve_humanize_model( $model_override );

		$result = self::call( $user, $system, $extra );

		// 失败时返回原文，不阻断流程
		if ( is_wp_error( $result ) || empty( $result['text'] ) ) {
			return $content;
		}

		// 还原图片占位符
		$humanized = self::restore_images( $result['text'], $protected['map'] );

		// 二次清理：humanize 可能引入新 AI 词（如「然而」），再清理一次
		return self::filter_ai_phrases( $humanized );
	}

	/**
	 * 获取润色指令前缀（固定部分，可缓存）
	 */
	private static function get_humanize_instruction_prefix() {
		$p  = "请把下面的文本改写成真人博主口吻，目标是让 AI 检测工具识别为人类写作。\n\n";
		$p .= "【必须遵守】\n";
		$p .= "- HTML 标签结构、主题、事实信息完全不变\n";
		$p .= "- 原文中的数字、数据、单位（如「500亿美元」「3.2亿用户」「2024年」）必须原样保留，不得缩写或省略单位\n";
		$p .= "- <!--WAISG_IMG_N--> 占位符原位保留，不得删除/移动\n";
		$p .= "- 段落顺序不变，但可微调段内表达\n";
		$p .= "- 保持句子之间的逻辑连贯：改写连接词时必须用等价的口语连接替代，不得直接删除导致句意断裂\n\n";
		$p .= "【人类写作特征 - 必须体现】\n";
		$p .= "1. 句长差异化：长句和短句穿插，避免均匀；偶尔有 5 字以内的短句独立成行\n";
		$p .= "2. 加入个人观点词：「我觉得」「说实话」「老实讲」「我倒是认为」「感觉」（每 2-3 段一次）\n";
		$p .= "3. 偶尔反问句：「这真的有用吗？」「你猜怎么着？」「为什么这么说？」\n";
		$p .= "4. 口语化连接替代书面过渡词：此外→另外、然而→不过、因此→所以、同时→另外、综上→总之。用「对吧」「是不是」「你看」「话说回来」等口语连接，保持上下文连贯\n";
		$p .= "5. 同义词替换：把规范用词换成更口语的说法（如「然而」→「不过」「但是」「话说」）\n";
		$p .= "6. 允许的「小瑕疵」：偶尔语序调整、口语化倒装、用「……」代替部分句末标点\n";
		$p .= "7. 列表项长度不要均匀：故意让列表项长度有 2-3 倍差异\n\n";
		$p .= "【避免】\n";
		$p .= "- 避免书面套话：综上所述、值得注意的是、需要指出的是、毫无疑问、众所周知、至关重要、具体而言、事实上、由此可见、不难发现——这些词替换成自然口语，不要直接删掉\n";
		$p .= "- 避免机械化排比（首先/其次/再次/最后）连续使用\n";
		$p .= "- 避免每段开头使用相同句式\n\n";
		$p .= "仅输出 HTML 正文，不要任何前言或说明。\n\n";
		$p .= "【原文】\n";
		return $p;
	}

	/**
	 * 内置默认的润色 user prompt
	 *
	 * @param string $content 待润色的 HTML 正文
	 * @return string
	 */
	private static function build_default_humanize_user_prompt( $content ) {
		return self::get_humanize_instruction_prefix() . $content;
	}

	/**
	 * 获取默认润色提示词文本（供设置页展示，不含 [正文] 占位符版）
	 *
	 * @return string
	 */
	public static function get_default_humanize_prompt() {
		$text  = "请把下面的文本改写成真人博主口吻，目标是让 AI 检测工具识别为人类写作。\n\n";
		$text .= "【必须遵守】\n";
		$text .= "- HTML 标签结构、主题、事实信息完全不变\n";
		$text .= "- 原文中的数字、数据、单位（如「500亿美元」「3.2亿用户」「2024年」）必须原样保留\n";
		$text .= "- <!--WAISG_IMG_N--> 占位符原位保留，不得删除/移动\n";
		$text .= "- 段落顺序不变，但可微调段内表达\n\n";
		$text .= "【人类写作特征 - 必须体现】\n";
		$text .= "1. 句长差异化：长句和短句穿插，避免均匀；偶尔有 5 字以内的短句独立成行\n";
		$text .= "2. 加入个人观点词：「我觉得」「说实话」「老实讲」「我倒是认为」「感觉」（每 2-3 段一次）\n";
		$text .= "3. 偶尔反问句：「这真的有用吗？」「你猜怎么着？」「为什么这么说？」\n";
		$text .= "4. 书面过渡词换口语连接，保持句子连贯——此外→另外、然而→不过、因此→所以、综上所述→总之；不要生硬删除连接词导致散装句\n";
		$text .= "5. 同义词替换：把规范用词换成更口语的说法（如「然而」→「不过」「但是」「话说」）\n";
		$text .= "6. 允许的「小瑕疵」：偶尔语序调整、口语化倒装、用「……」代替部分句末标点\n";
		$text .= "7. 列表项长度不要均匀：故意让列表项长度有 2-3 倍差异\n\n";
		$text .= "【避免】\n";
		$text .= "- 避免书面套话：综上所述、值得注意的是、需要指出的是、毫无疑问、众所周知、至关重要、具体而言、事实上、由此可见、不难发现——这些词替换成自然口语，不要直接删掉\n";
		$text .= "- 避免机械化排比（首先/其次/再次/最后）连续使用\n";
		$text .= "- 避免每段开头使用相同句式\n\n";
		$text .= "仅输出 HTML 正文，不要任何前言或说明。\n\n";
		$text .= "[正文]";
		return $text;
	}

	/**
	 * 长文分段润色：按 H2 边界拆分，每段独立润色后拼接
	 * 优势：避免单次 token 超限、提高成功率（单段失败不影响其他段）、并行性更好
	 *
	 * @param string $content        HTML 正文
	 * @param string $model_override 主任务模型偏好（main/lightweight/''）
	 * @return string
	 */
	private static function humanize_chunked( $content, $model_override = '' ) {
		// 兜底：部分受限主机禁用 curl_multi_*，整段降级为单段润色（串行，慢但可用）
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return self::humanize_single( $content, $model_override );
		}

		// 按 H2 标签拆分（H2 之间是相对独立的章节）
		$chunks = self::split_by_h2( $content );

		// 如果拆分失败（没有 H2），按字符数硬切
		if ( count( $chunks ) < 2 ) {
			$chunks = self::split_by_length( $content, 2500 );
		}

		// 解析这次润色实际用的模型（chunked 内所有段共用同一个模型）
		$humanize_model = self::resolve_humanize_model( $model_override );

		// 分离需要润色的段和短段（短段直接返回原文，不浪费 API 调用）
		$jobs    = array(); // index => chunk
		$results = array_fill( 0, count( $chunks ), '' );

		foreach ( $chunks as $i => $chunk ) {
			$plain = mb_strlen( strip_tags( $chunk ), 'UTF-8' );
			if ( $plain < 200 ) {
				$results[ $i ] = $chunk;
			} else {
				$jobs[ $i ] = $chunk;
			}
		}

		// 并行润色：每批 4 个并发（避免 API rate limit），用 curl_multi
		$batch_size = 4;
		$job_keys   = array_keys( $jobs );
		$batches    = array_chunk( $job_keys, $batch_size );

		foreach ( $batches as $batch ) {
			$handles = array();
			$mh      = curl_multi_init();

			foreach ( $batch as $idx ) {
				$chunk = $jobs[ $idx ];
				$req   = self::build_humanize_request( $chunk, $humanize_model );
				if ( ! $req ) {
					$results[ $idx ] = $chunk;
					continue;
				}

				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $req['url'] );
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $req['body'] );
				curl_setopt( $ch, CURLOPT_HTTPHEADER, $req['headers'] );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_TIMEOUT, $req['timeout'] );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
				curl_multi_add_handle( $mh, $ch );
				$handles[ $idx ] = array( 'ch' => $ch, 'chunk' => $chunk, 'map' => $req['map'] );
			}

			// 执行并发请求
			$running = null;
			do {
				curl_multi_exec( $mh, $running );
				if ( $running > 0 ) {
					curl_multi_select( $mh, 0.5 );
				}
			} while ( $running > 0 );

			// 收集结果
			foreach ( $handles as $idx => $h ) {
				$response = curl_multi_getcontent( $h['ch'] );
				$http_code = curl_getinfo( $h['ch'], CURLINFO_HTTP_CODE );
				curl_multi_remove_handle( $mh, $h['ch'] );
				curl_close( $h['ch'] );

				if ( $http_code === 200 && ! empty( $response ) ) {
					$data = json_decode( $response, true );
					$text = self::extract_text_from_response( $data );
					if ( ! empty( $text ) ) {
						$humanized = self::restore_images( $text, $h['map'] );
						$results[ $idx ] = self::filter_ai_phrases( $humanized );
						// 记录 token：根据润色模型是否等于主模型决定 tag
						$tokens = self::extract_total_tokens( $data );
						if ( $tokens > 0 ) {
							$main_name = WAISG_Settings::get( 'model', 'gpt-4o' );
							$tag = ( $humanize_model === $main_name ) ? 'main' : 'lightweight';
							WAISG_Settings::record_tokens( $tokens, $tag );
						}
						continue;
					}
				}
				// 失败则保留原文
				$results[ $idx ] = $h['chunk'];
			}

			curl_multi_close( $mh );
		}

		return implode( "\n", $results );
	}

	/**
	 * 构建单段润色的 HTTP 请求参数（供并行调用）
	 *
	 * @param string $chunk          需要润色的 HTML 段
	 * @param string $humanize_model 实际使用的模型名（已由 resolve_humanize_model 解析过）
	 * @return array|null
	 */
	private static function build_humanize_request( $chunk, $humanize_model = '' ) {
		$api_url = WAISG_Settings::get( 'api_url' );
		$api_key = WAISG_Settings::get( 'api_key' );
		if ( empty( $api_url ) || empty( $api_key ) ) return null;

		// 调用方未指定模型时降级为旧逻辑（轻量优先）
		if ( empty( $humanize_model ) ) {
			$lm    = WAISG_Settings::get( 'lightweight_model', '' );
			$humanize_model = $lm ?: WAISG_Settings::get( 'model', 'gpt-4o' );
		}
		$model = $humanize_model;

		// 保护图片
		$protected    = self::protect_images( $chunk );
		$safe_content = $protected['html'];

		$system = '你是真人博主，把机器化文本改写成自然口语风格。';
		$custom_prompt = trim( WAISG_Settings::get( 'humanize_prompt', '' ) );
		if ( ! empty( $custom_prompt ) && strpos( $custom_prompt, '[正文]' ) !== false ) {
			$user = str_replace( '[正文]', $safe_content, $custom_prompt );
		} else {
			$user = self::get_humanize_instruction_prefix() . $safe_content;
		}

		$extra    = self::build_long_content_extra( $safe_content );
		$protocol = self::detect_protocol( $api_url );

		// 推理模型叠加预算（humanize_chunked 绕过 call()，需在此单独叠加，
		// 否则推理模型思考 token 吃光 max_tokens 导致润色失败静默返回原文）
		if ( self::is_reasoning_model( $model ) ) {
			$configured = absint( WAISG_Settings::get( 'max_tokens', 4096 ) );
			$extra['max_tokens'] = absint( $extra['max_tokens'] ) + $configured;
		}

		// 按协议构建 endpoint / headers（curl 风格）/ body
		if ( $protocol === 'gemini' ) {
			$endpoint     = self::build_gemini_endpoint( $api_url, $model );
			$headers      = array( 'Content-Type: application/json', 'x-goog-api-key: ' . $api_key );
			$strategies   = self::build_gemini_strategies( $system, $user, 0.75, $extra['max_tokens'] );
			$request_body = $strategies[0]['body'];
		} elseif ( $protocol === 'anthropic' ) {
			$endpoint     = self::normalize_anthropic_endpoint( $api_url );
			$headers      = array( 'Content-Type: application/json', 'x-api-key: ' . $api_key, 'anthropic-version: 2023-06-01' );
			$strategies   = self::build_anthropic_strategies( $model, $system, $user, 0.75, $extra['max_tokens'] );
			$request_body = $strategies[0]['body'];
		} elseif ( $protocol === 'responses' ) {
			$endpoint     = self::normalize_endpoint( $api_url );
			$headers      = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $api_key );
			$strategies   = self::build_responses_strategies( $model, $system, $user, 0.75, $extra['max_tokens'] );
			$request_body = $strategies[0]['body'];
		} else {
			$endpoint     = self::normalize_endpoint( $api_url );
			$headers      = array( 'Content-Type: application/json', 'Authorization: Bearer ' . $api_key );
			$is_claude    = self::is_claude_api( $model, $api_url );
			$messages     = self::build_messages( $system, $user, '', $is_claude );
			$request_body = array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => 0.75,
				'max_tokens'  => $extra['max_tokens'],
			);
		}

		return array(
			'url'     => $endpoint,
			'body'    => wp_json_encode( $request_body ),
			'headers' => $headers,
			'timeout' => $extra['timeout'],
			'map'     => $protected['map'],
		);
	}

	/**
	 * 按 H2 标签拆分 HTML（保留 H2 在新段开头）
	 *
	 * @param string $html
	 * @return array
	 */
	private static function split_by_h2( $html ) {
		// 用 H2 起始标签作为切分点，保留 H2 标签
		$parts = preg_split( '#(?=<h2[\s>])#i', $html );
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}

	/**
	 * 按字符长度硬切（HTML 标签敏感的简单切分）
	 * 优先在段落标签 </p>、</li>、</div> 处切分
	 *
	 * @param string $html
	 * @param int    $size 目标字符数
	 * @return array
	 */
	private static function split_by_length( $html, $size = 2500 ) {
		$len = mb_strlen( $html, 'UTF-8' );
		if ( $len <= $size ) return array( $html );

		$chunks = array();
		$start  = 0;
		while ( $start < $len ) {
			$end = $start + $size;
			if ( $end >= $len ) {
				$chunks[] = mb_substr( $html, $start, $len - $start );
				break;
			}
			// 在目标位置附近找最近的 </p>、</li>、</div> 切分（向后看 500 字符）
			$slice = mb_substr( $html, $end, 500 );
			$cut   = 0;
			foreach ( array( '</p>', '</li>', '</div>' ) as $tag ) {
				$pos = mb_stripos( $slice, $tag );
				if ( $pos !== false ) {
					$cut = $pos + mb_strlen( $tag );
					break;
				}
			}
			$end += $cut;
			$chunks[] = mb_substr( $html, $start, $end - $start );
			$start    = $end;
		}
		return $chunks;
	}

	/**
	 * 公共「字段要求」行（excerpt / seo_title / seo_description）。
	 *
	 * 这 3 行在 优化全部 / 仅SEO / 生成 / 改写 四个 builder 中连续且逐字相同，抽出统一
	 * 维护，以后改字段长度只改此处一份。title 行虽也相同但各 builder 中位置不同（优化全部
	 * 里被 content 行隔开），content 行与 seo_keywords 行措辞又各不相同，故均保留在各自
	 * 函数中按序拼接，不并入此公共块，确保发给 AI 的文字逐字逐序不变。
	 *
	 * @return string 以 "\n" 结尾的多行字段要求
	 */
	private static function common_field_rules() {
		$r  = "- excerpt: 100-150 字符\n";
		$r .= "- seo_title: 30-60 字符\n";
		$r .= "- seo_description: 先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）\n";
		return $r;
	}

	/**
	 * 构建"优化全部"的 user prompt
	 *
	 * 返回结构（含 cache_user_prefix 用于 prompt caching）：
	 *   - 固定指令前缀放在 user prompt 开头（可缓存）
	 *   - 动态内容（原文、关键词约束）放在末尾
	 *
	 * @param array $vars 文章字段值
	 * @return array { system, user, cache_user_prefix }
	 */
	public static function build_optimize_all_prompt( $vars, $template = null ) {
		$title   = $vars['title']       ?? '';
		$content = $vars['content']     ?? '';
		$excerpt = $vars['excerpt']     ?? '';
		$seo_t   = $vars['seo_title']   ?? '';
		$seo_d   = $vars['seo_desc']    ?? '';
		$seo_k   = $vars['seo_kw']      ?? '';

		$system_prompt_template = WAISG_Settings::get( 'system_prompt', '' );
		$vars_for_system = array(
			'title'       => $title,
			'content'     => $content,
			'keywords'    => $seo_k,
			'excerpt'     => $excerpt,
			'description' => $seo_d,
		);
		$system_prompt = self::replace_vars( $system_prompt_template, $vars_for_system );

		$seo_k_first = ! empty( $seo_k ) ? self::first_keyword( $seo_k ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "优化以下 WordPress 文章为 SEO + GEO 友好版本。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: HTML，保留 <!--WAISG_IMG_N--> 占位符原位；H2/H3、加粗、列表，段落短；content 是正文片段，不要在开头重复 title（标题已单独输出到 title 字段）\n";
		$prefix .= self::common_field_rules();
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔；若下方提供了关键词则沿用并酌情补充\n";
		$prefix .= "【GEO 优化】\n";
		$prefix .= "- 段落短（≤3行）：AI 搜索引擎倾向引用简短、独立、有结论的段落\n";
		$prefix .= "- 关键论点单独成段并加粗，便于 AI 提取引用\n";
		$prefix .= "- 使用数据/统计/年份佐证观点（如「截至" . current_time( 'Y' ) . "年…」），提升 AI 引用可信度\n";
		$prefix .= "- 在正文末尾添加 2-3 个 FAQ（用 <h3> 标签，问题后紧跟简短回答），便于生成 FAQ Schema\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块、无说明文字）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = self::get_time_base_rule();
		$dynamic .= "\n【原文】\n";
		if ( $title )   $dynamic .= "标题：{$title}\n";
		if ( $excerpt ) $dynamic .= "摘要：{$excerpt}\n";
		if ( $seo_t )   $dynamic .= "SEO标题：{$seo_t}\n";
		if ( $seo_d )   $dynamic .= "SEO描述：{$seo_d}\n";
		if ( $seo_k )   $dynamic .= "SEO关键词：{$seo_k}\n";
		if ( $seo_k_first ) {
			$dynamic .= "约束：title/seo_title/seo_description 自然包含主关键词「{$seo_k_first}」\n";
		}
			if ( $content ) {
				// #7 去重：若用户在系统提示词里用了 [内容]（含全角［内容］），完整正文已随
				// system 发送一次，此处不再在 user 里重复整篇正文（长文可省 2000-8000 token）。
				$check_tpl = str_replace( array( '［', '］' ), array( '[', ']' ), (string) $system_prompt_template );
				if ( strpos( $check_tpl, '[内容]' ) !== false ) {
					$dynamic .= "正文：见系统提示词中的正文，请据其优化 content 字段。\n";
				} else {
					$dynamic .= "正文：\n" . $content;
				}
			}

		// user 指令前缀（$prefix）纯静态，恒可缓存——它是否可缓存与 system_prompt
		// 是否含变量无关。system 的缓存由 build_messages 按长度单独决定；这里不再因
		// system 含任意变量（哪怕只是 [标题]）就连带放弃 user 前缀缓存。
		$cache_prefix = $prefix;

		return array(
			'system'            => $system_prompt,
			'user'              => $prefix . $dynamic,
			'cache_user_prefix' => $cache_prefix,
		);
	}

	/**
	 * 构建"仅优化 SEO 字段"的 prompt（不处理正文，大幅节省 Token）
	 *
	 * @param array $vars 文章字段值
	 * @param array|null $template 内容结构模板
	 * @return array { system, user, cache_user_prefix }
	 */
	public static function build_optimize_seo_only_prompt( $vars, $template = null ) {
		$title   = $vars['title']       ?? '';
		$content = $vars['content']     ?? '';
		$excerpt = $vars['excerpt']     ?? '';
		$seo_t   = $vars['seo_title']   ?? '';
		$seo_d   = $vars['seo_desc']    ?? '';
		$seo_k   = $vars['seo_kw']      ?? '';

		$system_prompt_template = WAISG_Settings::get( 'system_prompt', '' );
		$vars_for_system = array(
			'title'       => $title,
			'content'     => $content,
			'keywords'    => $seo_k,
			'excerpt'     => $excerpt,
			'description' => $seo_d,
		);
		$system_prompt = self::replace_vars( $system_prompt_template, $vars_for_system );

		// 正文截断到 800 字符（旧版 1500，对 SEO 字段优化已足够，节省 ~350 token）
		$content_preview = mb_substr( $content, 0, 800 );
		if ( mb_strlen( $content ) > 800 ) {
			$content_preview .= "...";
		}

		$seo_k_first = ! empty( $seo_k ) ? self::first_keyword( $seo_k ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "仅优化以下 WordPress 文章的 SEO 字段（title/excerpt/seo_title/seo_description/seo_keywords），不处理正文。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= self::common_field_rules();
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔；若下方提供了关键词则沿用并酌情补充\n";
		$prefix .= "【GEO 优化】SEO 描述用陈述句，结论前置，包含具体数据/事实，便于 AI 搜索引擎引用\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= "\n仅输出 JSON（无代码块、无说明文字）：\n";
		$prefix .= '{"title":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = self::get_time_base_rule();
		$dynamic .= "\n【参考】\n";
		if ( $title )   $dynamic .= "标题：{$title}\n";
		if ( $excerpt ) $dynamic .= "摘要：{$excerpt}\n";
		if ( $seo_t )   $dynamic .= "SEO标题：{$seo_t}\n";
		if ( $seo_d )   $dynamic .= "SEO描述：{$seo_d}\n";
		if ( $seo_k )   $dynamic .= "SEO关键词：{$seo_k}\n";
		if ( $seo_k_first ) {
			$dynamic .= "约束：title/seo_title/seo_description 自然包含主关键词「{$seo_k_first}」\n";
		}
		if ( $content_preview ) {
			$dynamic .= "正文摘录：{$content_preview}";
		}

		// user 前缀纯静态，恒可缓存（与 system 是否含变量无关）
		$cache_prefix = $prefix;

		return array(
			'system'            => $system_prompt,
			'user'              => $prefix . $dynamic,
			'cache_user_prefix' => $cache_prefix,
		);
	}


	/**
	 * 构建"生成全新文章"的 prompt
	 *
	 * @param string $topic    主题/要求
	 * @param string $keywords 关键词
	 * @param int    $length   字数要求（0=不限）
	 * @param string $description 补充说明
	 * @param string $language 输出语言代码（默认 zh-CN）
	 * @return array { system, user, cache_user_prefix }
	 */
	public static function build_generate_prompt( $topic, $keywords = '', $length = 0, $description = '', $language = 'zh-CN', $template = null ) {
		$system_prompt = WAISG_Settings::get( 'system_prompt', '' );
		if ( empty( $system_prompt ) ) {
			$system_prompt = '你是 WordPress 内容创作专家，擅长 SEO 友好高质量文章。';
		}

		$lang_names = array(
			'zh-CN' => '', 'zh-TW' => '中文（繁体）', 'en' => '英文',
			'ja' => '日文', 'ko' => '韩文', 'es' => '西班牙文', 'fr' => '法文',
		);
		$lang_label = $lang_names[ $language ] ?? $language;
		$kw_first = ! empty( $keywords ) ? self::first_keyword( $keywords ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "创作一篇 WordPress 文章。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: HTML，H2/H3 分层、加粗、列表，段落短；content 是正文片段，不要在开头重复 title（标题已单独输出到 title 字段）\n";
		$prefix .= self::common_field_rules();
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔\n";
		$prefix .= "【GEO 优化】段落短（≤3行）、关键论点加粗、用数据佐证、末尾添加 2-3 个 FAQ（<h3>问题</h3>后跟简短回答）\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = self::get_time_base_rule();
		$dynamic .= "\n【任务】\n";
		$dynamic .= "主题：" . sanitize_text_field( $topic ) . "\n";
		if ( ! empty( $keywords ) ) {
			$dynamic .= "关键词：" . sanitize_text_field( $keywords ) . "\n";
		}
		if ( ! empty( $description ) ) {
			$dynamic .= "补充：" . sanitize_textarea_field( $description ) . "\n";
		}
		if ( $length > 0 ) {
			$dynamic .= "字数：约 " . absint( $length ) . " 字\n";
		}
		if ( ! empty( $lang_label ) ) {
			$dynamic .= "语言：{$lang_label}\n";
		}
		if ( $kw_first ) {
			$dynamic .= "约束：title/seo_title/seo_description 自然包含主关键词「{$kw_first}」\n";
		}

		// user 前缀纯静态，恒可缓存（与 system 是否含变量无关）
		$cache_prefix = $prefix;

		return array(
			'system'            => $system_prompt,
			'user'              => $prefix . $dynamic,
			'cache_user_prefix' => $cache_prefix,
		);
	}

	/**
	 * 构建"文章改写/伪原创"的 prompt
	 *
	 * @param string $content     原文正文
	 * @param string $keywords    关键词（可选）
	 * @param string $language    输出语言代码（默认 zh-CN）
	 * @param string $description 改写要求（可选）
	 * @return array { system, user, cache_user_prefix }
	 */
	public static function build_rewrite_prompt( $content, $keywords = '', $language = 'zh-CN', $description = '', $template = null ) {
		$system_prompt = WAISG_Settings::get( 'system_prompt', '' );
		if ( empty( $system_prompt ) ) {
			$system_prompt = '你是内容改写专家。';
		}

		$lang_names = array(
			'zh-CN' => '', 'zh-TW' => '中文（繁体）', 'en' => '英文',
			'ja' => '日文', 'ko' => '韩文', 'es' => '西班牙文', 'fr' => '法文',
		);
		$lang_label    = $lang_names[ $language ] ?? $language;
		$rw_kw_first   = ! empty( $keywords ) ? self::first_keyword( $keywords ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "将下文改写为主题相同但全新创作的文章，不得直接复制原文任何句子。\n";
		$prefix .= "使用 H2/H3 层级、加粗、列表。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: 改写后正文 HTML；content 是正文片段，不要在开头重复 title（标题已单独输出到 title 字段）\n";
		$prefix .= self::common_field_rules();
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔\n";
		$prefix .= "【GEO 优化】段落短（≤3行）、关键论点加粗、结论前置、便于 AI 提取引用\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = self::get_time_base_rule();
		if ( ! empty( $keywords ) ) {
			$dynamic .= "\n关键词必须体现：" . sanitize_text_field( $keywords ) . "\n";
		}
		if ( ! empty( $description ) ) {
			$dynamic .= "额外要求：" . sanitize_textarea_field( $description ) . "\n";
		}
		if ( ! empty( $lang_label ) ) {
			$dynamic .= "语言：{$lang_label}\n";
		}
		if ( $rw_kw_first ) {
			$dynamic .= "约束：title/seo_title/seo_description 自然包含主关键词「{$rw_kw_first}」\n";
		}
		$dynamic .= "\n【原文】\n" . $content;

		// user 前缀纯静态，恒可缓存（与 system 是否含变量无关）
		$cache_prefix = $prefix;

		return array(
			'system'            => $system_prompt,
			'user'              => $prefix . $dynamic,
			'cache_user_prefix' => $cache_prefix,
		);
	}

	/**
	 * 构建"单字段优化"的 prompt
	 *
	 * @param string $field  字段类型：title|content|excerpt|seo_title|seo_description|seo_keywords
	 * @param array  $vars   文章当前字段值
	 * @return array { system, user, cache_user_prefix }
	 */
	public static function build_single_field_prompt( $field, $vars ) {
		// 单字段优化场景，简化 system prompt 节省 token
		$system_prompt = '你是 WordPress 内容优化专家。';

		$title   = $vars['title']   ?? '';
		$content = $vars['content'] ?? '';
		$kw      = ! empty( $vars['seo_kw'] ) ? self::first_keyword( $vars['seo_kw'] ) : '';

		// 正文字段不需参考正文（处理对象本身就是正文）
		// 其他字段按重要性决定截断长度
		$ref_length = array(
			'title'           => 200,
			'excerpt'         => 400,
			'seo_title'       => 200,
			'seo_description' => 400,
			'seo_keywords'    => 300,
		);

		if ( $field === 'content' ) {
			// 正文优化的指令前缀（可缓存）
			$prefix  = "润色优化以下正文（HTML 输出）：\n";
			$prefix .= "- 保持核心意思不变；H2/H3 分层、加粗、列表、短段落\n";
			$prefix .= "- 保留 <!--WAISG_IMG_N--> 占位符及图片/短代码/iframe\n";
			$prefix .= "- 标题仅为参考，不得在正文开头重复标题（不要加 <h1> 或把标题文字写入正文第一行）\n";
			$prefix .= self::get_writing_style_rules();
			$prefix .= "\n仅输出优化后的 HTML，无说明文字。\n";

			$dynamic  = self::get_time_base_rule();
			if ( $title ) {
				$dynamic .= "标题（仅供参考，不要写入正文）：{$title}\n";
			}
			$dynamic .= "正文：\n" . $content;

			return array(
				'system'            => $system_prompt,
				'user'              => $prefix . $dynamic,
				'cache_user_prefix' => '',  // 正文字段每次都不同，prefix 单独不长，不值得 cache
			);
		}

		// 短字段优化
		$len = $ref_length[ $field ] ?? 300;
		$ref = mb_substr( $content, 0, $len );
		if ( mb_strlen( $content ) > $len ) $ref .= '...';

		$current_value = $vars[ $field ] ?? '';
		$current_len   = mb_strlen( $current_value, 'UTF-8' );
		$prompt        = self::get_time_base_rule() . "文章标题：{$title}\n";
		if ( $ref ) $prompt .= "正文摘录：{$ref}\n";

		// 针对当前值分析 SEO 评分问题，给 AI 明确的修复目标
		$kw_lc = $kw ? mb_strtolower( $kw, 'UTF-8' ) : '';

		if ( $field === 'title' ) {
			$diag = self::diagnose_length( $current_len, 20, 60 );
			$kw_miss = ( $kw_lc && mb_stripos( $current_value, $kw_lc ) === false );
			$prompt .= "\n当前标题：" . ( $current_value ?: '空' ) . "\n";
			$prompt .= "当前问题：" . self::format_diag( $diag, $kw, $kw_miss ) . "\n";
			$prompt .= "【严格要求】必须满足 20-60 字符" . ( $kw ? "，且必须自然包含主关键词「{$kw}」" : '' ) . "。\n";
			$prompt .= "仅输出优化后的标题文字（不要任何说明、引号或前缀）。";
		} elseif ( $field === 'excerpt' ) {
			$diag = self::diagnose_length( $current_len, 100, 150 );
			$prompt .= "\n当前摘要：" . ( $current_value ?: '空' ) . "\n";
			$prompt .= "当前问题：" . self::format_diag( $diag, '', false ) . "\n";
			$prompt .= "【严格要求】必须满足 100-150 字符。\n";
			$prompt .= "仅输出优化后的摘要文字（不要任何说明、引号或前缀）。";
		} elseif ( $field === 'seo_title' ) {
			$diag = self::diagnose_length( $current_len, 30, 60 );
			$kw_miss = ( $kw_lc && mb_stripos( $current_value, $kw_lc ) === false );
			$prompt .= "\n当前 SEO 标题：" . ( $current_value ?: '空' ) . "\n";
			$prompt .= "当前问题：" . self::format_diag( $diag, $kw, $kw_miss ) . "\n";
			$prompt .= "【严格要求】必须满足 30-60 字符" . ( $kw ? "，且必须自然包含主关键词「{$kw}」" : '' ) . "。\n";
			$prompt .= "仅输出优化后的 SEO 标题文字（不要任何说明、引号或前缀）。";
		} elseif ( $field === 'seo_description' ) {
			$diag = self::diagnose_length( $current_len, 120, 160 );
			$kw_miss = ( $kw_lc && mb_stripos( $current_value, $kw_lc ) === false );
			$prompt .= "\n当前 SEO 描述：" . ( $current_value ?: '空' ) . "\n";
			$prompt .= "当前问题：" . self::format_diag( $diag, $kw, $kw_miss ) . "\n";
			$prompt .= "【严格要求】先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述" . ( $kw ? "，自然包含主关键词「{$kw}」" : '' ) . "。最终≥120字≤160字。\n";
			$prompt .= "仅输出优化后的 SEO 描述文字（不要任何说明、引号或前缀）。";
		} elseif ( $field === 'seo_keywords' ) {
			$prompt .= "\n当前关键词：" . ( $current_value ?: '空' ) . "\n";
			$prompt .= "【严格要求】优化为 3-5 个核心关键词，用英文逗号分隔。\n";
			$prompt .= "仅输出关键词列表（不要任何说明或前缀）。";
		}

		// 提高温度让短字段输出有更多变化，避免 AI 在重新优化时返回近似原文
		$temperature_for_short = max( 0.5, floatval( WAISG_Settings::get( 'temperature', 0.3 ) ) );

		return array(
			'system'            => $system_prompt,
			'user'              => $prompt,
			'cache_user_prefix' => '',  // 单字段短 prompt 不值得 cache
			// 注：temperature 通过 extra 传入会更合适，但 build_*_prompt 不直接接管。
			// 这里返回给调用方作为提示，调用方可决定是否使用。
			'_suggest_temperature' => $temperature_for_short,
		);
	}

	/**
	 * 诊断字段长度问题
	 *
	 * @param int $len     当前长度
	 * @param int $min     最小推荐长度
	 * @param int $max     最大推荐长度
	 * @return array { issue: string, action: string } | null（无问题返回 null）
	 */
	private static function diagnose_length( $len, $min, $max ) {
		if ( $len === 0 ) {
			return array(
				'issue'  => '当前为空',
				'action' => "必须撰写满足 {$min}-{$max} 字符的内容",
			);
		}
		if ( $len < $min ) {
			$diff = $min - $len;
			return array(
				'issue'  => "当前 {$len} 字符过短（差 {$diff} 字符）",
				'action' => "必须扩展到 {$min}-{$max} 字符之间，至少增加 {$diff} 字符",
			);
		}
		if ( $len > $max ) {
			$diff = $len - $max;
			return array(
				'issue'  => "当前 {$len} 字符过长（超 {$diff} 字符）",
				'action' => "必须缩减到 {$min}-{$max} 字符之间，至少减少 {$diff} 字符",
			);
		}
		return null;
	}

	/**
	 * 格式化诊断信息
	 */
	private static function format_diag( $diag, $kw, $kw_miss ) {
		$parts = array();
		if ( $diag ) {
			$parts[] = $diag['issue'] . '，' . $diag['action'];
		}
		if ( $kw_miss && $kw ) {
			$parts[] = "缺少主关键词「{$kw}」，必须自然嵌入";
		}
		return empty( $parts ) ? '长度和关键词合格，请进一步润色提升表达' : implode( '；', $parts );
	}

	// ================================================================
	// 服务端 SEO 评分（与前端 JS calcSeoScoreData 逻辑一致）
	// ================================================================


	/**
	 * 纯 PHP 判定 SEO 字段是否已全部达标（零 API 调用）。
	 *
	 * 用于批量「仅优化 SEO」前置预检：若字段已达标就无需再调 AI，直接省下整次调用。
	 * 达标标准与前端评分面板 / quick_seo_check 保持一致：
	 *   - 标题 title:      20-60 字符，且含主关键词
	 *   - SEO 标题:        30-60 字符，且含主关键词
	 *   - SEO 描述:        120-160 字符，且含主关键词
	 *   - SEO 关键词:      非空
	 * 未配置关键词时不校验"含关键词"（无从判断）。
	 *
	 * @param array $fields { title, seo_title, seo_desc, seo_kw }
	 * @return bool 全部达标返回 true
	 */
	public static function seo_fields_pass( $fields ) {
		$title    = (string) ( $fields['title']     ?? '' );
		$seo_t    = (string) ( $fields['seo_title'] ?? '' );
		$seo_d    = (string) ( $fields['seo_desc']  ?? '' );
		$seo_k    = (string) ( $fields['seo_kw']    ?? '' );

		if ( $seo_k === '' ) return false;  // 关键词空，交给 AI/auto_fix 补

		$kw    = self::first_keyword( $seo_k );
		$kw_lc = $kw !== '' ? mb_strtolower( $kw, 'UTF-8' ) : '';

		$title_len = mb_strlen( $title, 'UTF-8' );
		$seo_t_len = mb_strlen( $seo_t, 'UTF-8' );
		$seo_d_len = mb_strlen( $seo_d, 'UTF-8' );

		if ( $title_len < 20 || $title_len > 60 ) return false;
		if ( $seo_t_len < 30 || $seo_t_len > 60 ) return false;
		if ( $seo_d_len < 120 || $seo_d_len > 160 ) return false;

		// 关键词需出现在标题与描述中（评分面板的两项 kwInT / kwInD）
		if ( $kw_lc !== '' ) {
			if ( mb_stripos( $title, $kw_lc ) === false ) return false;
			if ( mb_stripos( $seo_d, $kw_lc ) === false ) return false;
		}

		return true;
	}

	/**
	 * SEO 本地修复（零 API 调用）：用 PHP 字符串操作将评分尽量拉高
	 *
	 * 设计原则：只做不会破坏可读性的修正，不强行拼凑
	 *
	 * PHP 擅长做的（效果自然）：
	 *   - 过长截断（标题/描述超长时在句号处截断）
	 *   - 描述补充（从摘要/正文抽取真实内容补到 120+ 字）
	 *   - 关键词注入（在标题/描述中自然插入关键词）
	 *   - SEO 标题为空时复制文章标题
	 *
	 * PHP 不该做的（效果生硬，不做）：
	 *   - 标题过短时追加"详解""指南"等模板词
	 *
	 * @param array $fields  { title, seo_title, seo_desc, seo_kw, excerpt, content }
	 * @param int   $post_id 文章 ID（预留）
	 * @return array 修复后的 fields
	 */
	public static function auto_fix_seo( $fields, $post_id = 0 ) {
		$kw      = self::first_keyword( $fields['seo_kw'] ?? '' );
		$kw_lc   = $kw ? mb_strtolower( $kw, 'UTF-8' ) : '';

		// === 1. 修复 SEO 关键词（如果为空，从标题提取） ===
		if ( empty( $kw ) && ! empty( $fields['title'] ) ) {
			$fields['seo_kw'] = mb_substr( $fields['title'], 0, 10, 'UTF-8' );
			$kw    = $fields['seo_kw'];
			$kw_lc = mb_strtolower( $kw, 'UTF-8' );
		}

		// === 2. 修复文章标题（只做截断和关键词注入，不强行拉长）===
		$fields['title'] = self::fix_truncate( $fields['title'], 60 );
		if ( $kw_lc && mb_stripos( $fields['title'], $kw_lc ) === false ) {
			$fields['title'] = self::inject_keyword( $fields['title'], $kw, 60 );
		}

		// === 3. 修复 SEO 标题 ===
		if ( empty( $fields['seo_title'] ) ) {
			$fields['seo_title'] = $fields['title'];
		}
		// 偏短（<30 字符）：借用更长的文章标题补足（真实内容，不拼「详解/指南」等模板词）。
		// 文章标题本身也偏短则无从借，交由前端提示用户点灯用 AI 扩写。
		if ( mb_strlen( $fields['seo_title'], 'UTF-8' ) < 30
			&& mb_strlen( $fields['title'], 'UTF-8' ) > mb_strlen( $fields['seo_title'], 'UTF-8' ) ) {
			$fields['seo_title'] = $fields['title'];
		}
		$fields['seo_title'] = self::fix_truncate( $fields['seo_title'], 60 );
		if ( $kw_lc && mb_stripos( $fields['seo_title'], $kw_lc ) === false ) {
			$fields['seo_title'] = self::inject_keyword( $fields['seo_title'], $kw, 60 );
		}

		// === 4. 修复 SEO 描述（最容易不达标，也是 PHP 最擅长修复的字段）===
		$fields['seo_desc'] = self::fix_seo_desc( $fields, $kw, $kw_lc );

		return $fields;
	}

	/**
	 * 仅截断过长文本（不做填充，避免生硬追加）
	 */
	private static function fix_truncate( $text, $max ) {
		if ( mb_strlen( $text, 'UTF-8' ) > $max ) {
			return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . '…';
		}
		return $text;
	}

	/**
	 * 在文本中插入关键词（不破坏可读性）
	 */
	private static function inject_keyword( $text, $kw, $max_len ) {
		$kw_len  = mb_strlen( $kw, 'UTF-8' );
		$txt_len = mb_strlen( $text, 'UTF-8' );

		// 策略1：「关键词 — 原文」
		$attempt = $kw . ' — ' . $text;
		if ( mb_strlen( $attempt, 'UTF-8' ) <= $max_len ) {
			return $attempt;
		}

		// 策略2：截断原文后追加「 | 关键词」
		$suffix  = ' | ' . $kw;
		$room    = $max_len - mb_strlen( $suffix, 'UTF-8' );
		if ( $room > 10 ) {
			return mb_substr( $text, 0, $room, 'UTF-8' ) . $suffix;
		}

		// 策略3：直接替换开头
		return $kw . mb_substr( $text, $kw_len, $max_len - $kw_len, 'UTF-8' );
	}

	/**
	 * 修复 SEO 描述至 120-160 字符（纯 PHP，不调 AI）
	 *
	 * 素材优先级：现有描述 → 摘要 → 正文开头纯文本
	 */
	private static function fix_seo_desc( $fields, $kw, $kw_lc ) {
		$desc = $fields['seo_desc'] ?? '';
		$min  = 120;
		$max  = 160;

		// 如果为空，从摘要或正文获取初始内容
		if ( mb_strlen( $desc, 'UTF-8' ) < 20 ) {
			if ( ! empty( $fields['excerpt'] ) && mb_strlen( $fields['excerpt'], 'UTF-8' ) >= 20 ) {
				$desc = $fields['excerpt'];
			} elseif ( ! empty( $fields['content'] ) ) {
				$desc = mb_substr( wp_strip_all_tags( $fields['content'] ), 0, 200, 'UTF-8' );
			}
		}

		$desc = trim( $desc );
		$len  = mb_strlen( $desc, 'UTF-8' );

		// 过长：在句号处截断，或强制截断
		if ( $len > $max ) {
			// 找最后一个在 max 之前的句号
			$cut = $desc;
			$cut = mb_substr( $cut, 0, $max, 'UTF-8' );
			$last_period = self::find_last_sentence_end( $cut );
			if ( $last_period >= $min ) {
				$desc = mb_substr( $desc, 0, $last_period, 'UTF-8' );
			} else {
				$desc = mb_substr( $desc, 0, $max - 1, 'UTF-8' ) . '…';
			}
		}

		// 过短：用正文补充；正文为空时回退用摘要补充
		// （「仅优化 SEO」/ 生成结果等场景 content 可能为空，此时靠 excerpt 也能补到 120+）
		$len = mb_strlen( $desc, 'UTF-8' );
		if ( $len < $min ) {
			$source = ! empty( $fields['content'] )
				? wp_strip_all_tags( $fields['content'] )
				: ( ! empty( $fields['excerpt'] ) ? wp_strip_all_tags( $fields['excerpt'] ) : '' );
			if ( $source !== '' ) {
				$plain = $source;
				// 避免和已有描述重复：跳过前面和描述相似的部分
				$extra = mb_substr( $plain, min( $len, mb_strlen( $plain, 'UTF-8' ) ), 300, 'UTF-8' );
				$extra = trim( $extra );
				if ( ! empty( $extra ) ) {
					$need  = $min - $len + 10; // 多取 10 字留余量
					$chunk = mb_substr( $extra, 0, $need, 'UTF-8' );
					// 在句号处截断补充部分
					$end_pos = self::find_last_sentence_end( $chunk );
					if ( $end_pos > 5 ) {
						$chunk = mb_substr( $chunk, 0, $end_pos, 'UTF-8' );
					}
					$desc = $desc . $chunk;
				}
			}
		}

		// 再次确保不超长
		if ( mb_strlen( $desc, 'UTF-8' ) > $max ) {
			$desc = mb_substr( $desc, 0, $max - 1, 'UTF-8' ) . '…';
		}

		// 确保关键词在描述中
		if ( $kw_lc && mb_stripos( $desc, $kw_lc ) === false ) {
			$suffix = '了解更多关于' . $kw . '的信息。';
			$room   = $max - mb_strlen( $desc, 'UTF-8' );
			if ( $room >= mb_strlen( $suffix, 'UTF-8' ) ) {
				$desc = $desc . $suffix;
			} else {
				// 空间不够，截短描述后追加
				$cut_to = $max - mb_strlen( $suffix, 'UTF-8' ) - 1;
				if ( $cut_to > 60 ) {
					$desc = mb_substr( $desc, 0, $cut_to, 'UTF-8' ) . $suffix;
				}
			}
		}

		return $desc;
	}

	/**
	 * 找字符串中最后一个中文句号/感叹号/问号/英文句号的位置
	 *
	 * @param string $text
	 * @return int 位置（mb_strlen 单位），找不到返回 0
	 */
	private static function find_last_sentence_end( $text ) {
		$ends = array( '。', '！', '？', '；', '.', '!', '?' );
		$best = 0;
		foreach ( $ends as $ch ) {
			$pos = mb_strrpos( $text, $ch, 0, 'UTF-8' );
			if ( $pos !== false && $pos + 1 > $best ) {
				$best = $pos + 1;
			}
		}
		return $best;
	}

	/**
	 * 将内容结构模板注入为 prompt 文本块
	 *
	 * @param array  $template 模板数组 {id, name, structure, extra}
	 * @param string $keyword  关键词，替换 {关键词} 占位符；传入 null 时保留占位符（用于 prompt caching）
	 * @return string
	 */
	private static function build_template_block( $template, $keyword = '' ) {
		if ( empty( $template ) || empty( $template['structure'] ) ) return '';
		if ( $keyword === null ) {
			// 保留 {关键词} 占位符（cache-friendly），由 AI 从动态部分读取
			$structure = $template['structure'];
			$block     = "\n【正文结构】按此组织（其中 {关键词} 指代下方提供的关键词）：\n";
		} else {
			$kw        = $keyword ?: '（关键词）';
			$structure = str_replace( '{关键词}', $kw, $template['structure'] );
			$block     = "\n【正文结构】按此组织：\n";
		}
		$block .= $structure . "\n";
		if ( ! empty( $template['extra'] ) ) {
			$block .= "附加：" . $template['extra'] . "\n";
		}
		return $block;
	}

	/**
	 * 解析 AI 返回的 JSON（兼容 markdown 代码块包裹、解析失败时尝试括号配对提取）
	 *
	 * @param string $text AI 返回文本
	 * @return array|false
	 */
	public static function parse_json_response( $text, $context = '', $post_id = 0 ) {
		$text = trim( $text );
		// 1. 去掉 markdown 代码块包裹（首尾的 ``` 或 ```json）
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );

		// 2. 先尝试直接 decode（最常见的情况：AI 严格输出纯 JSON）
		$data = json_decode( $text, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			return $data;
		}

		// 3. 找到第一个 { 之后，用括号配对找到匹配的 }（避免贪婪匹配到错误位置）
		$start = strpos( $text, '{' );
		if ( $start === false ) return false;

		$depth     = 0;
		$in_string = false;
		$escape    = false;
		$end       = -1;
		$len       = strlen( $text );
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $escape ) { $escape = false; continue; }
			if ( $ch === '\\' && $in_string ) { $escape = true; continue; }
			if ( $ch === '"' ) { $in_string = ! $in_string; continue; }
			if ( $in_string ) continue;
			if ( $ch === '{' ) { $depth++; continue; }
			if ( $ch === '}' ) {
				$depth--;
				if ( $depth === 0 ) { $end = $i; break; }
			}
		}

		// 4. 提取到完整对象但 decode 失败 → 去尾逗号后重试
		if ( $end !== -1 ) {
			$json_str = substr( $text, $start, $end - $start + 1 );
			$data     = json_decode( $json_str, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
				return $data;
			}
			$cleaned = preg_replace( '/,(\s*[}\]])/', '$1', $json_str );
			$data    = json_decode( $cleaned, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
				return $data;
			}
		}

		// 5. 括号未配平（疑似被 max_tokens 截断）→ 抢救：闭合未结束的字符串 + 补齐缺失的右括号
		//    可救回截断前已完整的字段（如 title/seo_*），避免整条结果作废
		if ( $depth > 0 ) {
			$salvage = substr( $text, $start );
			if ( $in_string ) {
				$salvage .= '"';
			}
			$salvage .= str_repeat( '}', $depth );
			$salvage  = preg_replace( '/,(\s*[}\]])/', '$1', $salvage );
			$data     = json_decode( $salvage, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
				// 标记为截断抢救回的数据：正文 content 字段可能不完整，
				// 上层可据此在前端高亮提示用户核对，或不直接自动应用
				$data['_recovered'] = 'truncated';
				self::log_recovery( $context, $post_id, 'truncated' );
				return $data;
			}
		}

		// 6. 标准解析全部失败 → 已知字段锚点宽容提取（容忍 content 等值内未转义的英文双引号）
		$loose = self::loose_extract_fields( $text );
		if ( $loose ) {
			// 同样标记为宽容提取（loose），值内可能含未转义引号被强转修复
			$loose['_recovered'] = 'loose';
			self::log_recovery( $context, $post_id, 'loose' );
			return $loose;
		}

		return false;
	}

	/**
	 * 记录"容错修复"留痕日志：凡走了截断抢救 / 宽容提取救回结果的，
	 * 在错误日志留一条提示，便于用户重点核对这些文章。
	 *
	 * @param string $context 业务场景码（optimize_all / batch / generate ...），为空则不记
	 * @param int    $post_id 文章 ID
	 * @param string $type    'truncated'（截断抢救）| 'loose'（宽容提取）
	 */
	private static function log_recovery( $context, $post_id, $type ) {
		if ( empty( $context ) || ! class_exists( 'WAISG_Logger' ) ) {
			return;
		}
		$msg = ( $type === 'truncated' )
			? '⚠️ AI 返回被截断（疑似 max_tokens 不足），已自动抢救救回，正文可能不完整，请核对'
			: '⚠️ AI 返回 JSON 格式有瑕疵（如正文含未转义引号），已自动修复，建议核对内容';
		WAISG_Logger::log( $post_id, $context, $msg, '自动容错修复（' . $type . '）' );
	}

	/**
	 * 宽容字段提取：标准 JSON 解析失败时（最常见原因：content/title 等值内含未转义的
	 * 英文双引号，如 AI 写出 官方定义是"科幻心理惊悚"），按已知字段名作锚点逐个提取值，
	 * 容忍值内的引号、换行等。只要 AI 大致按约定字段输出，就能救回结果。
	 *
	 * @param string $text
	 * @return array|false
	 */
	private static function loose_extract_fields( $text ) {
	 $fields = array( 'title', 'content', 'excerpt', 'seo_title', 'seo_description', 'seo_keywords' );
	 $found  = array();
	 foreach ( $fields as $field ) {
	  $others     = array_diff( $fields, array( $field ) );
	  $others_pat = implode( '|', array_map( function ( $f ) { return preg_quote( $f, '/' ); }, $others ) );
	  // "field":"(值)"，右边界为：紧跟 ,"另一字段": 或 结尾 }
	  // 正则安全：(.*?) 为惰性量词，右边界用明确锚点（下一字段名 或 行尾 }），无嵌套可选组，
	  // 不存在灾难性回溯。超长/恶意输入会触及 pcre.backtrack_limit（默认 100 万），preg_match 返回 false（非 1），走 $found 空数组路径。
	  $pattern = '/"' . preg_quote( $field, '/' ) . '"\s*:\s*"(.*?)"\s*(?:,\s*"(?:' . $others_pat . ')"\s*:|\}\s*$)/s';
	  $matched = preg_match( $pattern, $text, $m );
	  if ( $matched === 1 ) {
	   $val = str_replace(
	    array( '\\"', '\\n', '\\r', '\\t', '\\/' ),
	    array( '"', "\n", "\r", "\t", '/' ),
	    $m[1]
	   );
	   $found[ $field ] = $val;
	  }
	 }
	 return ! empty( $found ) ? $found : false;
	}
}
