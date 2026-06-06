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

		// 构建 endpoint：兼容 /v1 结尾和完整路径
		$endpoint = rtrim( $api_url, '/' );
		if ( ! str_ends_with_compat( $endpoint, '/chat/completions' ) ) {
			$endpoint .= '/chat/completions';
		}

		// 检测是否为 Claude API（含 OpenAI 兼容代理）：依据 model 名或 API URL
		$is_claude = self::is_claude_api( $model, $api_url );

		// 构建 messages（Claude 启用显式 cache_control，OpenAI 依赖自动缓存）
		$messages = self::build_messages( $system_prompt, $user_prompt, $cache_user_prefix, $is_claude );

		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);

		if ( $is_claude ) {
			$request_body = array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			);
			$result = self::request_once( $endpoint, $headers, $request_body, $timeout, $model );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return $result;
		}

		$strategies = self::build_openai_compatible_strategies( $model, $system_prompt, $user_prompt, $messages, $temperature, $max_tokens );
		$last_error = null;
		foreach ( $strategies as $strategy ) {
			$result = self::request_once( $endpoint, $headers, $strategy['body'], $timeout, $model, $strategy['label'] );
			if ( ! is_wp_error( $result ) ) {
				if ( $strategy['label'] !== 'standard' ) {
					error_log( sprintf( '[WAISG] 模型 %s 命中兼容策略：%s', $model, $strategy['label'] ) );
				}
				return $result;
			}

			$last_error = $result;
			if ( ! self::should_retry_compatible_request( $result ) ) {
				return $result;
			}
		}

		return $last_error ? $last_error : new WP_Error( 'request_failed', 'API 请求失败：未获得有效响应。' );
	}

	private static function request_once( $endpoint, $headers, $request_body, $timeout, $model, $strategy_label = 'standard' ) {
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
		$tokens       = (int) ( $data['usage']['total_tokens'] ?? 0 );
		$finish_reason = self::extract_finish_reason( $data );
		$reasoning    = self::extract_reasoning_text( $data );

		if ( empty( $text ) ) {
			$reasoning_json = self::extract_json_from_text( $reasoning );
			if ( $reasoning_json !== '' ) {
				$text = $reasoning_json;
			} else {
				error_log( sprintf(
					'[WAISG] AI 返回内容为空。模型：%s，策略：%s，finish_reason：%s，has_reasoning：%s，响应 body（前 500 字符）：%s',
					$model,
					$strategy_label,
					$finish_reason ?: 'unknown',
					$reasoning !== '' ? 'yes' : 'no',
					mb_substr( $body, 0, 500, 'UTF-8' )
				) );

				if ( $reasoning !== '' ) {
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

		return array( 'text' => trim( $text ), 'tokens' => $tokens );
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

	public static function test_connection( $api_url, $api_key, $model ) {
		$endpoint = rtrim( $api_url, '/' );
		if ( ! str_ends_with_compat( $endpoint, '/chat/completions' ) ) {
			$endpoint .= '/chat/completions';
		}

		$is_claude   = self::is_claude_api( $model, $api_url );
		$system      = '';
		$user        = 'hi';
		$messages    = self::build_messages( $system, $user, '', $is_claude );
		$headers     = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $api_key,
		);
		$timeout     = 15;
		$temperature = 0.3;
		$max_tokens  = 5;

		if ( $is_claude ) {
			return self::request_once( $endpoint, $headers, array(
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
			), $timeout, $model, 'test_standard' );
		}

		$strategies = self::build_openai_compatible_strategies( $model, $system, $user, $messages, $temperature, $max_tokens );
		$last_error = null;
		foreach ( $strategies as $strategy ) {
			$result = self::request_once( $endpoint, $headers, $strategy['body'], $timeout, $model, 'test_' . $strategy['label'] );
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

		if ( ! empty( $data['choices'][0]['message']['reasoning_content'] ) && is_string( $data['choices'][0]['message']['reasoning_content'] ) ) {
			return $data['choices'][0]['message']['reasoning_content'];
		}

		if ( ! empty( $data['choices'][0]['message']['reasoning'] ) && is_string( $data['choices'][0]['message']['reasoning'] ) ) {
			return $data['choices'][0]['message']['reasoning'];
		}

		if ( ! empty( $data['reasoning_content'] ) && is_string( $data['reasoning_content'] ) ) {
			return $data['reasoning_content'];
		}

		if ( ! empty( $data['reasoning'] ) && is_string( $data['reasoning'] ) ) {
			return $data['reasoning'];
		}

		return '';
	}

	private static function extract_finish_reason( $data ) {
		if ( ! is_array( $data ) ) return '';
		if ( ! empty( $data['choices'][0]['finish_reason'] ) && is_string( $data['choices'][0]['finish_reason'] ) ) {
			return $data['choices'][0]['finish_reason'];
		}
		if ( ! empty( $data['finish_reason'] ) && is_string( $data['finish_reason'] ) ) {
			return $data['finish_reason'];
		}
		return '';
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
	 * 检测用户的 system_prompt 模板是否含动态变量
	 * 含变量则 system 每次内容不同，无法命中 prompt caching
	 *
	 * @param string $template
	 * @return bool
	 */
	private static function has_dynamic_vars( $template ) {
		if ( empty( $template ) ) return false;
		return ( strpos( $template, '[标题]' ) !== false )
			|| ( strpos( $template, '[内容]' ) !== false )
			|| ( strpos( $template, '[关键词]' ) !== false )
			|| ( strpos( $template, '[摘要]' ) !== false )
			|| ( strpos( $template, '[描述]' ) !== false );
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
		return "\n【风格】长短句交错（短句≤8字偶尔独立成行）；偶尔用反问句或感叹句；禁用：此外/综上/值得注意的是/众所周知/至关重要/总的来说/首先…其次…最后 连续使用；像真人博主，少过渡词，允许口语化表达（「说实话」「我觉得」等）。\n";
	}

	/**
	 * 正文净化入口（零过滤，原样返回）
	 *
	 * 本插件仅在后台、由管理员操作，正文内容由站长/AI 产生且可信；管理员拥有
	 * unfiltered_html 权限，WordPress 自身保存时本就不过滤。为保证 AI 优化前后
	 * 内容字符级一致——任何标签、短代码、iframe/video/svg/自定义标签等都不被删改
	 * ——此处不做任何过滤，直接原样返回。
	 *
	 * @param string $html 正文 HTML
	 * @return string 原样的 HTML
	 */
	public static function sanitize_content( $html ) {
		if ( null === $html ) return '';
		return (string) $html;
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

		// 1. 整块嵌入/媒体标签 + 独立图片：
		//    figure 放最前优先整体匹配（避免拆开包裹 iframe/img 的 figure）；
		//    其后依次匹配 iframe / video / audio / object / embed / img。
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

		// 2. 短代码（含成对 [xx]...[/xx] 与自闭合 [xx]），优先用站点已注册的
		//    短代码精确正则；取不到则退回通用正则。转义的 [[...]] 不处理。
		$sc_pattern = function_exists( 'get_shortcode_regex' )
			? '/' . get_shortcode_regex() . '/s'
			: '/\[(\[?)([a-zA-Z0-9_\-]+)(?![\w-])[^\]]*?(?:\](?:.*?\[\/\2\])?|\/\])(\]?)/s';
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

		// 公式：中文约 1 token/1.5字符，留 50% 扩写余量（AI 可能添加段落/FAQ/表格）+ 1500 token（SEO字段+HTML标签+JSON开销）
		$estimated = (int) ceil( mb_strlen( $content, 'UTF-8' ) / 1.5 * 1.5 ) + 1500;
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

		// 如果用户关闭了 AI 高频词替换，直接返回
		if ( ! WAISG_Settings::get( 'ai_phrases_enabled', 1 ) ) {
			return $text;
		}

		// 获取替换规则（优先用户自定义，为空则用内置默认）
		$replacements = self::get_phrase_replacements();

		if ( ! empty( $replacements ) ) {
			$text = str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
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
			// === 机械化过渡词 ===
			'此外，'           => '',
			'此外, '           => '',
			'另外，'           => '',
			'再者，'           => '',
			'与此同时，'       => '同时',
			'同时，'           => '',
			'然而，'           => '不过，',
			'因此，'           => '所以，',
			'于是，'           => '',
			'综上所述，'       => '总之，',
			'总而言之，'       => '说到底，',
			'总的来说，'       => '',
			'总结来说，'       => '',
			'换言之，'         => '也就是说，',
			'换句话说，'       => '也就是说，',
			'具体而言，'       => '',
			'具体来说，'       => '',
			'具体来讲，'       => '',

			// === 学术化套话 ===
			'值得注意的是，'   => '',
			'值得一提的是，'   => '',
			'需要指出的是，'   => '',
			'需要强调的是，'   => '',
			'需要注意的是，'   => '',
			'不可忽视的是，'   => '',
			'不容忽视的是，'   => '',
			'毫无疑问，'       => '',
			'毋庸置疑，'       => '',
			'众所周知，'       => '',
			'显而易见，'       => '',
			'不言而喻，'       => '',
			'事实上，'         => '',
			'实际上，'         => '',
			'本质上，'         => '',
			'从某种意义上说，' => '',
			'从某种程度上说，' => '',
			'某种程度上，'     => '',

			// === 套话短语 ===
			'至关重要'         => '很关键',
			'极其重要'         => '很重要',
			'尤为重要'         => '特别重要',
			'重要的是'         => '关键是',
			'在当今社会，'     => '',
			'在现代社会，'     => '',
			'在当今时代，'     => '',
			'在这个时代，'     => '',
			'随着时代的发展，' => '',
			'随着社会的发展，' => '',
			'随着科技的发展，' => '',

			// === 模板化结论句式 ===
			'由此可见，'       => '可见',
			'由此可知，'       => '',
			'可以看出，'       => '',
			'可以发现，'       => '',
			'不难看出，'       => '',
			'不难发现，'       => '',
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
		$lines[] = '# 格式：原词|替换词（替换词留空=删除该词）';
		$lines[] = '';
		$lines[] = '# ── 机械化过渡词 ──';

		$defaults = self::get_default_phrases();
		$sections = array(
			'此外，'           => '# ── 机械化过渡词 ──',
			'值得注意的是，'   => '# ── 学术化套话 ──',
			'至关重要'         => '# ── 套话短语 ──',
			'由此可见，'       => '# ── 模板化结论句式 ──',
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
	 * @param string $content 正文 HTML
	 * @return string 润色后的正文（失败/过短则返回原文）
	 */
	public static function humanize( $content ) {
		if ( empty( $content ) ) return $content;

		$plain_len = mb_strlen( strip_tags( $content ), 'UTF-8' );

		// 短文跳过 humanize 节省 token（短文没有明显 AI 特征）
		if ( $plain_len < 300 ) {
			return $content;
		}

		// 长文（纯文本 >3000 字符）按段落拆分润色，提高成功率并避免 token 不够
		if ( $plain_len > 3000 ) {
			return self::humanize_chunked( $content );
		}

		return self::humanize_single( $content );
	}

	/**
	 * 单次润色（不分段）
	 *
	 * @param string $content HTML 正文
	 * @return string
	 */
	private static function humanize_single( $content ) {
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

		// 使用轻量模型（如已配置）+ 根据内容长度动态调整 max_tokens 和 timeout
		$extra = self::build_long_content_extra( $safe_content );
		$extra['temperature'] = 0.75;
		$extra['cache_user_prefix'] = $cache_prefix;
		$lm    = WAISG_Settings::get( 'lightweight_model', '' );
		if ( $lm ) {
			$extra['model'] = $lm;
		}

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
		$p .= "- <!--WAISG_IMG_N--> 占位符原位保留，不得删除/移动\n";
		$p .= "- 段落顺序不变，但可微调段内表达\n\n";
		$p .= "【人类写作特征 - 必须体现】\n";
		$p .= "1. 句长差异化：长句和短句穿插，避免均匀；偶尔有 5 字以内的短句独立成行\n";
		$p .= "2. 加入个人观点词：「我觉得」「说实话」「老实讲」「我倒是认为」「感觉」（每 2-3 段一次）\n";
		$p .= "3. 偶尔反问句：「这真的有用吗？」「你猜怎么着？」「为什么这么说？」\n";
		$p .= "4. 口语化连接：「对吧」「是不是」「你看」「话说回来」（替代「此外」「然而」等过渡词）\n";
		$p .= "5. 同义词替换：把规范用词换成更口语的说法（如「然而」→「不过」「但是」「话说」）\n";
		$p .= "6. 允许的「小瑕疵」：偶尔语序调整、口语化倒装、用「……」代替部分句末标点\n";
		$p .= "7. 列表项长度不要均匀：故意让列表项长度有 2-3 倍差异\n\n";
		$p .= "【严禁】\n";
		$p .= "- 严禁使用：此外、综上、综上所述、值得注意的是、需要指出的是、毫无疑问、众所周知、至关重要、总的来说、具体而言、事实上、由此可见、不难发现\n";
		$p .= "- 严禁机械化排比（首先/其次/再次/最后）连续使用\n";
		$p .= "- 严禁每段开头使用相同句式\n\n";
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
		$text .= "- <!--WAISG_IMG_N--> 占位符原位保留，不得删除/移动\n";
		$text .= "- 段落顺序不变，但可微调段内表达\n\n";
		$text .= "【人类写作特征 - 必须体现】\n";
		$text .= "1. 句长差异化：长句和短句穿插，避免均匀；偶尔有 5 字以内的短句独立成行\n";
		$text .= "2. 加入个人观点词：「我觉得」「说实话」「老实讲」「我倒是认为」「感觉」（每 2-3 段一次）\n";
		$text .= "3. 偶尔反问句：「这真的有用吗？」「你猜怎么着？」「为什么这么说？」\n";
		$text .= "4. 口语化连接：「对吧」「是不是」「你看」「话说回来」（替代「此外」「然而」等过渡词）\n";
		$text .= "5. 同义词替换：把规范用词换成更口语的说法（如「然而」→「不过」「但是」「话说」）\n";
		$text .= "6. 允许的「小瑕疵」：偶尔语序调整、口语化倒装、用「……」代替部分句末标点\n";
		$text .= "7. 列表项长度不要均匀：故意让列表项长度有 2-3 倍差异\n\n";
		$text .= "【严禁】\n";
		$text .= "- 严禁使用：此外、综上、综上所述、值得注意的是、需要指出的是、毫无疑问、众所周知、至关重要、总的来说、具体而言、事实上、由此可见、不难发现\n";
		$text .= "- 严禁机械化排比（首先/其次/再次/最后）连续使用\n";
		$text .= "- 严禁每段开头使用相同句式\n\n";
		$text .= "仅输出 HTML 正文，不要任何前言或说明。\n\n";
		$text .= "[正文]";
		return $text;
	}

	/**
	 * 长文分段润色：按 H2 边界拆分，每段独立润色后拼接
	 * 优势：避免单次 token 超限、提高成功率（单段失败不影响其他段）、并行性更好
	 *
	 * @param string $content HTML 正文
	 * @return string
	 */
	private static function humanize_chunked( $content ) {
		// 按 H2 标签拆分（H2 之间是相对独立的章节）
		$chunks = self::split_by_h2( $content );

		// 如果拆分失败（没有 H2），按字符数硬切
		if ( count( $chunks ) < 2 ) {
			$chunks = self::split_by_length( $content, 2500 );
		}

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

		// 并行润色：每批 2 个并发（避免 API rate limit），用 curl_multi
		$batch_size = 2;
		$job_keys   = array_keys( $jobs );
		$batches    = array_chunk( $job_keys, $batch_size );

		foreach ( $batches as $batch ) {
			$handles = array();
			$mh      = curl_multi_init();

			foreach ( $batch as $idx ) {
				$chunk = $jobs[ $idx ];
				$req   = self::build_humanize_request( $chunk );
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
						// 记录 token
						$tokens = (int) ( $data['usage']['total_tokens'] ?? 0 );
						if ( $tokens > 0 ) {
							$lm  = WAISG_Settings::get( 'lightweight_model', '' );
							$tag = ! empty( $lm ) ? 'lightweight' : 'main';
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
	 */
	private static function build_humanize_request( $chunk ) {
		$api_url = WAISG_Settings::get( 'api_url' );
		$api_key = WAISG_Settings::get( 'api_key' );
		if ( empty( $api_url ) || empty( $api_key ) ) return null;

		$endpoint = rtrim( $api_url, '/' );
		if ( ! str_ends_with_compat( $endpoint, '/chat/completions' ) ) {
			$endpoint .= '/chat/completions';
		}

		$lm    = WAISG_Settings::get( 'lightweight_model', '' );
		$model = $lm ?: WAISG_Settings::get( 'model', 'gpt-4o' );

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
		$is_claude = self::is_claude_api( $model, $api_url );
		$messages  = self::build_messages( $system, $user, '', $is_claude );

		$request_body = array(
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => 0.75,
			'max_tokens'  => $extra['max_tokens'],
		);

		$headers = array(
			'Content-Type: application/json',
			'Authorization: Bearer ' . $api_key,
		);

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

		$seo_k_first = ! empty( $seo_k ) ? trim( explode( ',', $seo_k )[0] ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "优化以下 WordPress 文章为 SEO + GEO 友好版本。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: HTML，保留 <!--WAISG_IMG_N--> 占位符原位；H2/H3、加粗、列表，段落短\n";
		$prefix .= "- excerpt: 100-150 字符\n";
		$prefix .= "- seo_title: 30-60 字符\n";
		$prefix .= "- seo_description: 先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）\n";
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔；若下方提供了关键词则沿用并酌情补充\n";
		$prefix .= "【GEO 优化】\n";
		$prefix .= "- 段落短（≤3行）：AI 搜索引擎倾向引用简短、独立、有结论的段落\n";
		$prefix .= "- 关键论点单独成段并加粗，便于 AI 提取引用\n";
		$prefix .= "- 使用数据/统计/年份佐证观点（如「截至2024年…」），提升 AI 引用可信度\n";
		$prefix .= "- 在正文末尾添加 2-3 个 FAQ（用 <h3> 标签，问题后紧跟简短回答），便于生成 FAQ Schema\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块、无说明文字）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = "\n【原文】\n";
		if ( $title )   $dynamic .= "标题：{$title}\n";
		if ( $excerpt ) $dynamic .= "摘要：{$excerpt}\n";
		if ( $seo_t )   $dynamic .= "SEO标题：{$seo_t}\n";
		if ( $seo_d )   $dynamic .= "SEO描述：{$seo_d}\n";
		if ( $seo_k )   $dynamic .= "SEO关键词：{$seo_k}\n";
		if ( $seo_k_first ) {
			$dynamic .= "约束：title/seo_title/seo_description 自然包含主关键词「{$seo_k_first}」\n";
		}
		if ( $content ) {
			$dynamic .= "正文：\n" . $content;
		}

		// 没有动态变量的 system_prompt 才能享受 caching
		$cache_prefix = self::has_dynamic_vars( $system_prompt_template ) ? '' : $prefix;

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

		$seo_k_first = ! empty( $seo_k ) ? trim( explode( ',', $seo_k )[0] ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "仅优化以下 WordPress 文章的 SEO 字段（title/excerpt/seo_title/seo_description/seo_keywords），不处理正文。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- excerpt: 100-150 字符\n";
		$prefix .= "- seo_title: 30-60 字符\n";
		$prefix .= "- seo_description: 先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）\n";
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔；若下方提供了关键词则沿用并酌情补充\n";
		$prefix .= "【GEO 优化】SEO 描述用陈述句，结论前置，包含具体数据/事实，便于 AI 搜索引擎引用\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= "\n仅输出 JSON（无代码块、无说明文字）：\n";
		$prefix .= '{"title":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = "\n【参考】\n";
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

		$cache_prefix = self::has_dynamic_vars( $system_prompt_template ) ? '' : $prefix;

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
		$kw_first = ! empty( $keywords ) ? trim( explode( ',', $keywords )[0] ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "创作一篇 WordPress 文章。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: HTML，H2/H3 分层、加粗、列表，段落短\n";
		$prefix .= "- excerpt: 100-150 字符\n";
		$prefix .= "- seo_title: 30-60 字符\n";
		$prefix .= "- seo_description: 先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）\n";
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔\n";
		$prefix .= "【GEO 优化】段落短（≤3行）、关键论点加粗、用数据佐证、末尾添加 2-3 个 FAQ（<h3>问题</h3>后跟简短回答）\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic  = "\n【任务】\n";
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

		$cache_prefix = self::has_dynamic_vars( $system_prompt ) ? '' : $prefix;

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
		$rw_kw_first   = ! empty( $keywords ) ? trim( explode( ',', $keywords )[0] ) : '';

		// ========== 固定指令前缀（cache_user_prefix）==========
		$prefix  = "将下文改写为主题相同但全新创作的文章，不得直接复制原文任何句子。\n";
		$prefix .= "使用 H2/H3 层级、加粗、列表。\n\n";
		$prefix .= "【字段要求】\n";
		$prefix .= "- title: 20-60 字符\n";
		$prefix .= "- content: 改写后正文 HTML\n";
		$prefix .= "- excerpt: 100-150 字符\n";
		$prefix .= "- seo_title: 30-60 字符\n";
		$prefix .= "- seo_description: 先写3句概括文章核心价值，再追加2句补充细节或数据，合并为一段连贯描述（最终≥120字≤160字）\n";
		$prefix .= "- seo_keywords: 3-5 个，逗号分隔\n";
		$prefix .= "【GEO 优化】段落短（≤3行）、关键论点加粗、结论前置、便于 AI 提取引用\n";
		if ( $template ) {
			$prefix .= self::build_template_block( $template, null );
		}
		$prefix .= self::get_writing_style_rules();
		$prefix .= "\n仅输出 JSON（无代码块）：\n";
		$prefix .= '{"title":"","content":"","excerpt":"","seo_title":"","seo_description":"","seo_keywords":""}' . "\n";

		// ========== 动态内容部分 ==========
		$dynamic = '';
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

		$cache_prefix = self::has_dynamic_vars( $system_prompt ) ? '' : $prefix;

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
		$kw      = ! empty( $vars['seo_kw'] ) ? trim( explode( ',', $vars['seo_kw'] )[0] ) : '';

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
			$prefix .= self::get_writing_style_rules();
			$prefix .= "\n仅输出优化后的 HTML，无说明文字。\n";

			$dynamic  = "\n标题（参考）：{$title}\n";
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
		$prompt        = "文章标题：{$title}\n";
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
		$kw      = trim( explode( ',', $fields['seo_kw'] ?? '' )[0] );
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

		// 过短：用正文内容补充
		$len = mb_strlen( $desc, 'UTF-8' );
		if ( $len < $min && ! empty( $fields['content'] ) ) {
			$plain = wp_strip_all_tags( $fields['content'] );
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
	public static function parse_json_response( $text ) {
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

		if ( $end === -1 ) return false;
		$json_str = substr( $text, $start, $end - $start + 1 );
		$data     = json_decode( $json_str, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) {
			return $data;
		}

		return false;
	}
}

/**
 * PHP 7.4 兼容的 str_ends_with
 */
if ( ! function_exists( 'str_ends_with_compat' ) ) {
	function str_ends_with_compat( $haystack, $needle ) {
		if ( function_exists( 'str_ends_with' ) ) {
			return str_ends_with( $haystack, $needle );
		}
		return $needle === '' || substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
