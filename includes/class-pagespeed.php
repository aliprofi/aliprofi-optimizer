<?php
/**
 * Интеграция с Google PageSpeed Insights API v5 и опциональным AI-разбором (DeepSeek).
 * Работает только в админке, не создаёт нагрузки на фронтенд.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Модуль PageSpeed-диагностики и рекомендаций для Aliprofi Optimizer.
 */
final class PageSpeed {

	public const OPT_PSI_KEY      = 'aliprofi_psi_api_key';
	public const OPT_DEEPSEEK_KEY = 'aliprofi_deepseek_api_key';
	public const OPT_HISTORY      = 'aliprofi_psi_history';
	public const NONCE_ACTION     = 'aliprofi_psi_nonce';

	/**
	 * Регистрация AJAX-обработчиков.
	 */
	public function run(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'wp_ajax_aliprofi_psi_save_keys', array( $this, 'ajax_save_keys' ) );
		add_action( 'wp_ajax_aliprofi_psi_run', array( $this, 'ajax_run_psi' ) );
		add_action( 'wp_ajax_aliprofi_psi_ai_analyze', array( $this, 'ajax_ai_analyze' ) );
		add_action( 'wp_ajax_aliprofi_psi_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	/**
	 * Сохранение API-ключей (autoload = no).
	 */
	public function ajax_save_keys(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}

		$psi_key      = isset( $_POST['psi_key'] ) ? sanitize_text_field( wp_unslash( $_POST['psi_key'] ) ) : '';
		$deepseek_key = isset( $_POST['deepseek_key'] ) ? sanitize_text_field( wp_unslash( $_POST['deepseek_key'] ) ) : '';

		update_option( self::OPT_PSI_KEY, trim( $psi_key ), false );
		update_option( self::OPT_DEEPSEEK_KEY, trim( $deepseek_key ), false );

		wp_send_json_success( array( 'message' => 'API-ключи сохранены.' ) );
	}

	/**
	 * Очистка истории замеров.
	 */
	public function ajax_clear_history(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}
		delete_option( self::OPT_HISTORY );
		wp_send_json_success( array( 'message' => 'История замеров очищена.' ) );
	}

	/**
	 * Запуск проверки через Google PageSpeed Insights API v5.
	 */
	public function ajax_run_psi(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}

		$url      = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';
		$strategy = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'mobile';

		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'message' => 'Укажите корректный URL с http:// или https://.' ) );
		}
		if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
			$strategy = 'mobile';
		}

		$api_key = trim( (string) get_option( self::OPT_PSI_KEY, '' ) );
		$query   = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
			'locale'   => 'ru',
		);
		if ( '' !== $api_key ) {
			$query['key'] = $api_key;
		}

		$endpoint = add_query_arg( $query, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );
		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Ошибка запроса к Google PSI: ' . $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['lighthouseResult'] ) ) {
			$err = $body['error']['message'] ?? ( 'HTTP ' . $code );
			wp_send_json_error( array( 'message' => 'Google PSI вернул ошибку: ' . $err ) );
		}

		$report = self::parse_lighthouse( $body['lighthouseResult'], $url, $strategy );
		$report['recommendations'] = self::build_recommendations( $report );
		$report['dev_log']         = self::build_dev_log( $report );

		self::save_history( $report );

		wp_send_json_success( $report );
	}

	/**
	 * Опциональный AI-разбор результатов через DeepSeek API.
	 */
	public function ajax_ai_analyze(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}

		$api_key = trim( (string) get_option( self::OPT_DEEPSEEK_KEY, '' ) );
		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => 'Укажите DeepSeek API Key и сохраните ключи.' ) );
		}

		$history = get_option( self::OPT_HISTORY, array() );
		$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
		$report  = is_array( $history ) && isset( $history[ $index ] ) ? $history[ $index ] : null;

		if ( ! is_array( $report ) ) {
			wp_send_json_error( array( 'message' => 'Сначала выполните замер PageSpeed.' ) );
		}

		$settings = Settings::all();
		$safe_settings = array(
			'enabled'              => (int) $settings['enabled'],
			'page_cache'           => (int) $settings['page_cache'],
			'delay'                => (int) $settings['delay'],
			'delay_safe_mode'      => (int) $settings['delay_safe_mode'],
			'delay_exclusions'     => $settings['delay_exclusions'],
			'defer'                => (int) $settings['defer'],
			'defer_exclude'        => $settings['defer_exclude'],
			'css_async'            => (int) $settings['css_async'],
			'css_async_exclude'    => $settings['css_async_exclude'],
			'dequeue_mediaelement' => (int) $settings['dequeue_mediaelement'],
			'lazy_exclude'         => $settings['lazy_exclude'],
			'img_dims'             => (int) $settings['img_dims'],
			'youtube'              => (int) $settings['youtube'],
			'fonts'                => (int) $settings['fonts'],
			'fonts_preload'        => $settings['fonts_preload'],
		);

		$prompt = "Ты — эксперт по оптимизации WordPress и Core Web Vitals.\n"
			. "Проанализируй отчёт Google PageSpeed Insights и текущие настройки плагина Aliprofi Optimizer.\n"
			. "Дай чёткий, практический ответ на русском языке в двух частях:\n"
			. "1) **Что изменить в настройках Aliprofi Optimizer** (конкретные галочки и точные строки/URL, которые нужно вписать в поля `delay_exclusions`, `defer_exclude`, `css_async_exclude`, `lazy_exclude`, `fonts_preload`).\n"
			. "2) **Системное ТЗ для программиста** (если есть тяжёлые плагины, TTFB, LCP-элемент, CLS или лишний код, которые не решаются галочками).\n\n"
			. "Отчёт PageSpeed:\n" . wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n\n"
			. "Текущие настройки Aliprofi Optimizer:\n" . wp_json_encode( $safe_settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

		$response = wp_remote_post(
			'https://api.deepseek.com/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'       => 'deepseek-chat',
						'temperature' => 0.2,
						'max_tokens'  => 1800,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => 'Ты опытный WordPress Performance Engineer. Пиши кратко, структурно, без воды, с точными именами полей и файлов.',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Ошибка запроса к DeepSeek: ' . $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			$err = $body['error']['message'] ?? ( 'HTTP ' . $code );
			wp_send_json_error( array( 'message' => 'DeepSeek API вернул ошибку: ' . $err ) );
		}

		wp_send_json_success(
			array(
				'analysis' => (string) $body['choices'][0]['message']['content'],
			)
		);
	}

	/**
	 * Извлечение компактной выжимки из ответа Lighthouse.
	 *
	 * @param array<string, mixed> $lh       Lighthouse result.
	 * @param string               $url      Проверенный URL.
	 * @param string               $strategy mobile|desktop.
	 * @return array<string, mixed>
	 */
	private static function parse_lighthouse( array $lh, string $url, string $strategy ): array {
		$audits = $lh['audits'] ?? array();
		$score  = isset( $lh['categories']['performance']['score'] )
			? (int) round( (float) $lh['categories']['performance']['score'] * 100 )
			: 0;

		$metrics = array(
			'score' => $score,
			'fcp'   => self::metric_item( $audits, 'first-contentful-paint' ),
			'lcp'   => self::metric_item( $audits, 'largest-contentful-paint' ),
			'tbt'   => self::metric_item( $audits, 'total-blocking-time' ),
			'cls'   => self::metric_item( $audits, 'cumulative-layout-shift' ),
			'si'    => self::metric_item( $audits, 'speed-index' ),
			'ttfb'  => self::metric_item( $audits, 'server-response-time' ),
		);

		$audit_keys = array(
			'render-blocking-resources',
			'unused-css-rules',
			'unused-javascript',
			'bootup-time',
			'uses-optimized-images',
			'modern-image-formats',
			'offscreen-images',
			'font-display',
		);

		$issues  = array();
		$plugins = array();

		foreach ( $audit_keys as $key ) {
			if ( empty( $audits[ $key ] ) ) {
				continue;
			}
			$a = $audits[ $key ];
			if ( isset( $a['score'] ) && null !== $a['score'] && (float) $a['score'] >= 0.9 ) {
				continue;
			}

			$items = array();
			if ( ! empty( $a['details']['items'] ) && is_array( $a['details']['items'] ) ) {
				foreach ( array_slice( $a['details']['items'], 0, 12 ) as $item ) {
					$item_url = (string) ( $item['url'] ?? '' );
					if ( '' === $item_url ) {
						continue;
					}
					$wasted_ms    = isset( $item['wastedMs'] ) ? (int) round( (float) $item['wastedMs'] ) : 0;
					$wasted_bytes = isset( $item['wastedBytes'] ) ? (int) $item['wastedBytes'] : 0;
					$total_ms     = isset( $item['total'] ) ? (int) round( (float) $item['total'] ) : 0;

					$items[] = array(
						'url'          => $item_url,
						'wasted_ms'    => $wasted_ms,
						'wasted_kb'    => round( $wasted_bytes / 1024, 1 ),
						'total_ms'     => $total_ms,
					);

					if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $item_url, $m ) ) {
						$slug = $m[1];
						if ( ! isset( $plugins[ $slug ] ) ) {
							$plugins[ $slug ] = array(
								'slug'      => $slug,
								'wasted_ms' => 0,
								'wasted_kb' => 0.0,
								'files'     => array(),
							);
						}
						$plugins[ $slug ]['wasted_ms'] += max( $wasted_ms, $total_ms );
						$plugins[ $slug ]['wasted_kb'] += round( $wasted_bytes / 1024, 1 );
						if ( ! in_array( $item_url, $plugins[ $slug ]['files'], true ) && count( $plugins[ $slug ]['files'] ) < 6 ) {
							$plugins[ $slug ]['files'][] = $item_url;
						}
					}
				}
			}

			if ( ! empty( $items ) || ( isset( $a['score'] ) && (float) $a['score'] < 0.9 ) ) {
				$issues[ $key ] = array(
					'id'            => $key,
					'title'         => (string) ( $a['title'] ?? $key ),
					'display_value' => (string) ( $a['displayValue'] ?? '' ),
					'items'         => $items,
				);
			}
		}

		// LCP element info
		$lcp_element = '';
		if ( ! empty( $audits['largest-contentful-paint-element']['details']['items'][0]['items'][0]['node']['snippet'] ) ) {
			$lcp_element = (string) $audits['largest-contentful-paint-element']['details']['items'][0]['items'][0]['node']['snippet'];
		} elseif ( ! empty( $audits['largest-contentful-paint-element']['details']['items'][0]['node']['snippet'] ) ) {
			$lcp_element = (string) $audits['largest-contentful-paint-element']['details']['items'][0]['node']['snippet'];
		}

		// CLS elements info (layout-shift-elements)
		$cls_elements = array();
		if ( ! empty( $audits['layout-shift-elements']['details']['items'] ) && is_array( $audits['layout-shift-elements']['details']['items'] ) ) {
			foreach ( array_slice( $audits['layout-shift-elements']['details']['items'], 0, 8 ) as $item ) {
				$snippet = (string) ( $item['node']['snippet'] ?? '' );
				$label   = (string) ( $item['node']['nodeLabel'] ?? '' );
				$score   = isset( $item['score'] ) ? round( (float) $item['score'], 4 ) : 0.0;
				if ( '' === $snippet && '' === $label ) {
					continue;
				}
				$cls_elements[] = array(
					'snippet' => $snippet,
					'label'   => $label,
					'score'   => $score,
				);
			}
		}

		usort(
			$plugins,
			static function ( array $a, array $b ): int {
				return ( $b['wasted_ms'] <=> $a['wasted_ms'] ) ?: ( $b['wasted_kb'] <=> $a['wasted_kb'] );
			}
		);

		return array(
			'timestamp'    => current_time( 'mysql' ),
			'url'          => $url,
			'strategy'     => $strategy,
			'metrics'      => $metrics,
			'lcp_element'  => $lcp_element,
			'cls_elements' => $cls_elements,
			'issues'       => $issues,
			'plugins'      => array_values( $plugins ),
		);
	}

	/**
	 * Форматирование отдельной метрики Lighthouse.
	 *
	 * @param array<string, mixed> $audits Аудиты.
	 * @param string               $key    Ключ аудита.
	 * @return array<string, mixed>
	 */
	private static function metric_item( array $audits, string $key ): array {
		$a = $audits[ $key ] ?? array();
		return array(
			'value'   => isset( $a['numericValue'] ) ? round( (float) $a['numericValue'], 2 ) : 0,
			'display' => (string) ( $a['displayValue'] ?? '—' ),
			'score'   => isset( $a['score'] ) ? (float) $a['score'] : 1.0,
		);
	}

	/**
	 * Детерминированные рекомендации по настройкам Aliprofi Optimizer.
	 *
	 * @param array<string, mixed> $report Отчёт PSI.
	 * @return array<int, array<string, string>>
	 */
	private static function build_recommendations( array $report ): array {
		$recs     = array();
		$settings = Settings::all();
		$m        = $report['metrics'];
		$issues   = $report['issues'];

		if ( empty( $settings['enabled'] ) ) {
			$recs[] = array(
				'tab'    => 'Кэш',
				'level'  => 'critical',
				'text'   => 'Главный выключатель плагина выключен. Включите «Оптимизация включена» на вкладке Кэш.',
			);
		}

		if ( ( $m['ttfb']['value'] ?? 0 ) > 600 && empty( $settings['page_cache'] ) ) {
			$recs[] = array(
				'tab'   => 'Кэш',
				'level' => 'critical',
				'text'  => sprintf(
					'Высокий TTFB (%s). Включите «Внутренний страничный кэш» на вкладке Кэш (или проверьте FastCGI-кэш OpenResty).',
					$m['ttfb']['display']
				),
			);
		}

		if ( ! empty( $issues['render-blocking-resources'] ) || ! empty( $issues['bootup-time'] ) || ( $m['tbt']['value'] ?? 0 ) > 200 ) {
			if ( empty( $settings['delay'] ) ) {
				$recs[] = array(
					'tab'   => 'JavaScript',
					'level' => 'critical',
					'text'  => 'Есть блокирующие/тяжёлые скрипты. Включите «Отложенную загрузку JS» (delay) на вкладке JavaScript.',
				);
			} elseif ( empty( $settings['defer'] ) ) {
				$recs[] = array(
					'tab'   => 'JavaScript',
					'level' => 'warn',
					'text'  => 'Включите атрибут defer для скриптов (вкладка JavaScript), чтобы снизить TBT.',
				);
			}

			if ( empty( $settings['css_async'] ) && ! empty( $issues['render-blocking-resources']['items'] ) ) {
				foreach ( $issues['render-blocking-resources']['items'] as $it ) {
					if ( str_contains( $it['url'], '.css' ) ) {
						$recs[] = array(
							'tab'   => 'JavaScript',
							'level' => 'warn',
							'text'  => 'CSS-файлы блокируют первый рендер. Попробуйте включить «Асинхронная загрузка CSS» (вкладка JavaScript) и проверьте отображение первого экрана.',
						);
						break;
					}
				}
			}
		}

		if ( ! empty( $report['lcp_element'] ) && str_contains( $report['lcp_element'], '<img' ) ) {
			$recs[] = array(
				'tab'   => 'Медиа',
				'level' => 'info',
				'text'  => 'LCP-элемент — изображение (' . mb_substr( $report['lcp_element'], 0, 120 ) . '). Убедитесь, что его класс или часть URL добавлены в «Исключения LazyLoad» на вкладке Медиа, чтобы картинка не ждала прокрутки.',
			);
		}

		if ( ! empty( $issues['font-display'] ) && empty( $settings['fonts'] ) ) {
			$recs[] = array(
				'tab'   => 'Шрифты',
				'level' => 'warn',
				'text'  => 'Обнаружены проблемы со шрифтами. Включите «Оптимизацию шрифтов» на вкладке Шрифты и добавьте основные .woff2 в Preload шрифтов.',
			);
		}

		if ( empty( $recs ) ) {
			$recs[] = array(
				'tab'   => 'Готово',
				'level' => 'ok',
				'text'  => 'Базовые настройки Aliprofi Optimizer уже включены оптимально. Если балл всё ещё неидеален — проверьте вкладку «Проблемные плагины» или запустите AI-разбор DeepSeek.',
			);
		}

		return $recs;
	}

	/**
	 * Сборка готового текстового отчёта-задания для программиста / AI.
	 *
	 * @param array<string, mixed> $report Отчёт PSI.
	 */
	private static function build_dev_log( array $report ): string {
		$m = $report['metrics'];
		$lines = array(
			'Google PageSpeed Insights — Отчёт аудита',
			'========================================',
			'URL: ' . $report['url'],
			'Устройство: ' . $report['strategy'],
			'Дата: ' . $report['timestamp'],
			sprintf(
				'Performance: %d/100 | FCP: %s | LCP: %s | TBT: %s | CLS: %s | TTFB: %s',
				$m['score'],
				$m['fcp']['display'],
				$m['lcp']['display'],
				$m['tbt']['display'],
				$m['cls']['display'],
				$m['ttfb']['display']
			),
			'',
		);

		if ( '' !== $report['lcp_element'] ) {
			$lines[] = 'LCP-элемент: ' . $report['lcp_element'];
			$lines[] = '';
		}

		if ( ! empty( $report['cls_elements'] ) ) {
			$lines[] = '[Элементы, вызывающие сдвиг макета (CLS)]';
			foreach ( $report['cls_elements'] as $ce ) {
				$lines[] = sprintf( '- CLS %s: %s (%s)', $ce['score'], $ce['snippet'] ?: $ce['label'], $ce['label'] );
			}
			$lines[] = '';
		}

		if ( ! empty( $report['plugins'] ) ) {
			$lines[] = '[Проблемные плагины по ресурсам PSI]';
			foreach ( $report['plugins'] as $p ) {
				$lines[] = sprintf( '- %s: ~%d мс, ~%.1f КБ', $p['slug'], $p['wasted_ms'], $p['wasted_kb'] );
				foreach ( $p['files'] as $f ) {
					$lines[] = '    • ' . $f;
				}
			}
			$lines[] = '';
		}

		if ( ! empty( $report['issues'] ) ) {
			$lines[] = '[Основные аудиты Lighthouse]';
			foreach ( $report['issues'] as $issue ) {
				$lines[] = '* ' . $issue['title'] . ( '' !== $issue['display_value'] ? ' (' . $issue['display_value'] . ')' : '' );
				foreach ( array_slice( $issue['items'], 0, 5 ) as $it ) {
					$lines[] = sprintf( '    - %s (потери: %d мс / %.1f КБ)', $it['url'], $it['wasted_ms'], $it['wasted_kb'] );
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Сохранение до 10 последних замеров в истории.
	 *
	 * @param array<string, mixed> $report Отчёт.
	 */
	private static function save_history( array $report ): void {
		$history = get_option( self::OPT_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		array_unshift( $history, $report );
		$history = array_slice( $history, 0, 10 );
		update_option( self::OPT_HISTORY, $history, false );
	}
}
