<?php
/**
 * Безопасная предзагрузка FastCGI-кэша после сброса: очередь, WP-Cron и лимиты.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Фоновая предзагрузка очищенных URL.
 */
final class Preload {

	public const CRON  = 'aliprofi_optimizer_preload';
	private const QUEUE = 'aliprofi_optimizer_preload_queue';
	private const LOCK  = 'aliprofi_optimizer_preload_lock';

	/**
	 * Подписка на сброс кэша и обработчик очереди.
	 */
	public function run(): void {
		add_action( self::CRON, array( $this, 'process' ) );
		add_action( 'aliprofi_optimizer_cache_purged', array( $this, 'on_purged' ), 10, 1 );
		add_action( 'aliprofi_optimizer_cache_purged_all', array( $this, 'on_purged_all' ) );
	}

	/**
	 * Включена ли предзагрузка.
	 */
	public static function is_enabled(): bool {
		return Settings::is_on( 'enabled' ) && Settings::is_on( 'preload' );
	}

	/**
	 * Количество URL в очереди.
	 */
	public static function queue_count(): int {
		return count( self::queue() );
	}

	/**
	 * Очистка задания и очереди при деактивации.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
		delete_transient( self::QUEUE );
		delete_transient( self::LOCK );
	}

	/**
	 * Постановка очищенных URL в очередь.
	 *
	 * @param string[] $urls URL или пути.
	 */
	public static function enqueue( array $urls ): void {
		if ( ! self::is_enabled() || ! self::allowed_context() ) {
			return;
		}

		$limit = max( 1, min( 200, (int) Settings::get( 'preload_limit' ) ) );
		$new   = array();

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$normalized = self::normalize( $url );
			if ( '' !== $normalized ) {
				$new[] = $normalized;
			}
		}

		if ( ! $new ) {
			if ( self::queue() ) {
				self::schedule();
			}
			return;
		}

		$queue = array_values( array_unique( array_merge( $new, self::queue() ) ) );
		$queue = array_slice( $queue, 0, $limit );

		set_transient( self::QUEUE, $queue, 6 * HOUR_IN_SECONDS );
		self::schedule();
	}

	/**
	 * Обработка одной порции очереди WP-Cron.
	 */
	public function process(): void {
		if ( ! self::is_enabled() ) {
			delete_transient( self::QUEUE );
			delete_transient( self::LOCK );
			return;
		}

		if ( get_transient( self::LOCK ) ) {
			self::schedule( 60 );
			return;
		}

		$queue = self::queue();
		if ( ! $queue ) {
			return;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );
		self::schedule( 60 );

		$batch = max( 1, min( 5, (int) Settings::get( 'preload_batch' ) ) );
		$urls  = array_slice( $queue, 0, $batch );

		try {
			foreach ( $urls as $index => $url ) {
				if ( $index > 0 ) {
					usleep( 700000 );
				}
				wp_safe_remote_get(
					$url,
					array(
						'timeout'     => 4,
						'redirection' => 2,
						'cookies'     => array(),
						'user-agent'  => 'Mozilla/5.0 (compatible; AliprofiOptimizer/' . ALIPROFI_OPTIMIZER_VERSION . '; +https://aliprofi.ru)',
					)
				);
			}
		} finally {
			delete_transient( self::LOCK );
		}

		// Перечитываем очередь: за время запросов могли добавиться новые URL.
		$remaining = array_values( array_diff( self::queue(), $urls ) );
		if ( $remaining ) {
			set_transient( self::QUEUE, $remaining, 6 * HOUR_IN_SECONDS );
			self::schedule( 60 );
		} else {
			delete_transient( self::QUEUE );
		}
	}

	/**
	 * Сброс одной страницы: прогреваем именно её.
	 *
	 * @param string[] $urls URL.
	 */
	public function on_purged( $urls ): void {
		if ( is_array( $urls ) ) {
			self::enqueue( $urls );
		}
	}

	/**
	 * Полный сброс: главная и последние изменённые страницы.
	 */
	public function on_purged_all(): void {
		self::enqueue( self::site_urls() );
	}

	/**
	 * URL для полной очистки: главная и последние опубликованные материалы.
	 *
	 * @return string[]
	 */
	private static function site_urls(): array {
		$urls = array( home_url( '/' ) );

		if ( ! Settings::is_on( 'preload_full' ) ) {
			return $urls;
		}

		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		if ( ! $types ) {
			return $urls;
		}

		$limit = max( 1, min( 200, (int) Settings::get( 'preload_limit' ) ) );
		$ids   = get_posts(
			array(
				'post_type'           => array_values( $types ),
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, $limit - 1 ),
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			)
		);

		foreach ( (array) $ids as $id ) {
			$link = get_permalink( (int) $id );
			if ( is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}
		}

		return $urls;
	}

	/**
	 * Планирование ближайшего запуска.
	 *
	 * @param int $delay Задержка в секундах.
	 */
	private static function schedule( int $delay = 30 ): void {
		$next = wp_next_scheduled( self::CRON );
		if ( $next && $next > time() ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), self::CRON );
	}

	/**
	 * Предзагрузка допустима только в админке, REST, cron или WP-CLI.
	 */
	private static function allowed_context(): bool {
		if ( is_admin() || wp_doing_cron() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Оставляет только безопасные URL текущего сайта.
	 *
	 * @param string $url URL.
	 */
	private static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$url   = preg_replace( '/#.*$/', '', $url );
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme      = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host        = strtolower( (string) $parts['host'] );
		$home        = wp_parse_url( home_url( '/' ) );
		$home_scheme = strtolower( (string) ( $home['scheme'] ?? 'https' ) );
		$home_host   = strtolower( (string) ( $home['host'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || '' === $home_host ) {
			return '';
		}
		if ( $scheme !== $home_scheme || $host !== $home_host ) {
			return '';
		}

		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === $home_scheme ? 443 : 80 );
		if ( $port !== $home_port ) {
			return '';
		}

		if ( isset( $parts['query'] ) && preg_match( '/(?:^|&)(?:preview|preview_id|preview_nonce|customize_changeset_uuid|et_fb|elementor-preview|vc_preview)(?:=|&|$)/i', (string) $parts['query'] ) ) {
			return '';
		}

		return esc_url_raw( (string) $url );
	}

	/**
	 * Текущая очередь.
	 *
	 * @return string[]
	 */
	private static function queue(): array {
		$queue = get_transient( self::QUEUE );
		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values( array_filter( $queue, 'is_string' ) );
	}
}
