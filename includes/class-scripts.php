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
	 * Исключения асинхронного CSS.
	 *
	 * @var string[]
	 */
	private array $css_exclude = array();

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

		if ( Settings::is_on( 'css_async' ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'css_async_exclude' ) );
			foreach ( $lines as $line ) {
				$line = strtolower( trim( $line ) );
				if ( '' !== $line ) {
					$this->css_exclude[] = $line;
				}
			}
			add_filter( 'style_loader_tag', array( $this, 'async_style' ), 10, 4 );
		}

		if ( Settings::is_on( 'dequeue_mediaelement' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_mediaelement' ), 999 );
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
	 * Асинхронная загрузка стилей через preload + onload.
	 *
	 * @param string $tag    HTML тега.
	 * @param string $handle Handle стиля.
	 * @param string $href   URL стиля.
	 * @param string $media  Медиа-запрос.
	 */
	public function async_style( $tag, $handle, $href, $media ): string {
		$tag = (string) $tag;
		if ( '' === $tag || ! is_string( $href ) || '' === $href ) {
			return $tag;
		}
		if ( false !== stripos( $tag, "rel='preload'" ) || false !== stripos( $tag, 'rel="preload"' ) || false !== stripos( $tag, 'data-no-async' ) ) {
			return $tag;
		}
		if ( ! preg_match( '/\brel=([\'"])stylesheet\1/i', $tag ) ) {
			return $tag;
		}
		if ( is_string( $media ) && str_contains( strtolower( $media ), 'print' ) ) {
			return $tag;
		}

		$handle = strtolower( (string) $handle );
		$href   = strtolower( (string) $href );
		foreach ( $this->css_exclude as $needle ) {
			if ( str_contains( $handle, $needle ) || str_contains( $href, $needle ) ) {
				return $tag;
			}
		}

		$async = str_replace(
			array( "rel='stylesheet'", 'rel="stylesheet"' ),
			array(
				"rel='preload' as='style' onload=\"this.onload=null;this.rel='stylesheet'\"",
				'rel="preload" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"',
			),
			$tag
		);
		if ( $async === $tag ) {
			return $tag;
		}

		return $async . '<noscript>' . $tag . '</noscript>';
	}

	/**
	 * Отключение стилей и скриптов MediaElement.
	 */
	public function dequeue_mediaelement(): void {
		foreach ( array( 'mediaelement', 'wp-mediaelement', 'mediaelementplayer', 'mediaelementplayer-legacy' ) as $handle ) {
			wp_dequeue_style( $handle );
			wp_dequeue_script( $handle );
		}
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
