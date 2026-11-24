<?php
/**
 * defer для скриптов и очистка лишнего вывода WordPress.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Оптимизация очереди скриптов и head.
 */
final class Scripts {

	/**
	 * Исключения defer.
	 *
	 * @var string[]
	 */
	private array $exclude = array();

	/**
	 * Подключение модуля.
	 */
	public function run(): void {
		if ( Settings::is_on( 'defer' ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'defer_exclude' ) );
			foreach ( $lines as $line ) {
				$line = strtolower( trim( $line ) );
				if ( '' !== $line ) {
					$this->exclude[] = $line;
				}
			}
			add_filter( 'script_loader_tag', array( $this, 'defer' ), 10, 3 );
		}

		if ( Settings::is_on( 'clean_emoji' ) ) {
			$this->clean_emoji();
		}
		if ( Settings::is_on( 'clean_embed' ) ) {
			$this->clean_embed();
		}
		if ( Settings::is_on( 'clean_meta' ) ) {
			$this->clean_meta();
		}
	}

	/**
	 * Добавление defer к тегу скрипта.
	 *
	 * @param string $tag    HTML тега.
	 * @param string $handle Handle скрипта.
	 * @param string $src    URL скрипта.
	 */
	public function defer( $tag, $handle, $src ): string {
		$tag = (string) $tag;
		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}
		if ( false !== stripos( $tag, 'application/ld+json' ) ) {
			return $tag;
		}

		$handle = strtolower( (string) $handle );
		if ( function_exists( 'wp_scripts' ) ) {
			$registered = wp_scripts()->query( $handle, 'registered' );
			if ( $registered && ! empty( $registered->extra['strategy'] ) ) {
				return $tag;
			}
		}

		$file = strtolower( basename( (string) wp_parse_url( (string) $src, PHP_URL_PATH ) ) );
		foreach ( $this->exclude as $needle ) {
			if ( str_contains( $handle, $needle ) || str_contains( $file, $needle ) ) {
				return $tag;
			}
		}

		return (string) preg_replace( '/<script\b/i', '<script defer', $tag, 1 );
	}

	/**
	 * Отключение emoji-скриптов и стилей.
	 */
	private function clean_emoji(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'print_emoji_styles' );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Отключение wp-embed.
	 */
	private function clean_embed(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_action(
			'wp_enqueue_scripts',
			static function () {
				wp_deregister_script( 'wp-embed' );
			},
			100
		);
	}

	/**
	 * Удаление лишних мета-тегов.
	 */
	private function clean_meta(): void {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	}
}
