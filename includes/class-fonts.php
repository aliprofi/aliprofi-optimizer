<?php
/**
 * Локализация Google Fonts и preload .woff2.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Скачивание CSS/шрифтов Google в локальный каталог.
 */
final class Fonts {

	private const UA  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
	private const TTL = 604800;

	/**
	 * Файлы шрифтов, скачанные в текущем запросе.
	 *
	 * @var array<string, string>
	 */
	private static array $files = array();

	/**
	 * Каталог кэша шрифтов.
	 */
	public static function dir(): string {
		return WP_CONTENT_DIR . '/cache/aliprofi-optimizer-fonts';
	}

	/**
	 * URL каталога кэша.
	 */
	private static function base_url(): string {
		return content_url( '/cache/aliprofi-optimizer-fonts' );
	}

	/**
	 * Подключение модуля.
	 */
	public function run(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'rewrite' ), 9999 );
		add_action( 'wp_head', array( $this, 'preload' ), 2 );
	}

	/**
	 * Подмена внешних Google Fonts на локальный CSS.
	 */
	public function rewrite(): void {
		if ( ! function_exists( 'wp_styles' ) ) {
			return;
		}

		$styles = wp_styles();
		if ( ! $styles instanceof \WP_Styles ) {
			return;
		}

		foreach ( $styles->queue as $handle ) {
			if ( empty( $styles->registered[ $handle ] ) ) {
				continue;
			}
			$style = $styles->registered[ $handle ];
			if ( empty( $style->src ) || ! is_string( $style->src ) || false === stripos( $style->src, 'fonts.googleapis.com' ) ) {
				continue;
			}

			$local = self::localize( $style->src );
			if ( $local ) {
				$style->src = $local;
				$style->ver = null;
			}
		}
	}

	/**
	 * Preload локальных шрифтов.
	 */
	public function preload(): void {
		$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'fonts_preload' ) );
		foreach ( $lines as $line ) {
			$url = trim( $line );
			if ( '' === $url ) {
				continue;
			}
			if ( str_starts_with( $url, '/' ) ) {
				$url = content_url( $url );
			}
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( $url ) );
		}
	}

	/**
	 * Локализует CSS Google Fonts, возвращает URL локального файла.
	 *
	 * @param string $remote URL CSS.
	 */
	private static function localize( string $remote ): ?string {
		$remote = html_entity_decode( trim( $remote ), ENT_QUOTES, 'UTF-8' );
		if ( str_starts_with( $remote, '//' ) ) {
			$remote = 'https:' . $remote;
		}
		if ( 'fonts.googleapis.com' !== strtolower( (string) wp_parse_url( $remote, PHP_URL_HOST ) ) ) {
			return null;
		}

		$dir       = self::dir();
		$fonts_dir = $dir . '/fonts';
		if ( ! wp_mkdir_p( $fonts_dir ) ) {
			return null;
		}

		$hash     = md5( 'v1|' . $remote . '|' . self::UA );
		$css_file = $dir . '/' . $hash . '.css';
		$css_url  = self::base_url() . '/' . $hash . '.css';

		if ( is_file( $css_file ) && ( time() - (int) filemtime( $css_file ) ) < self::TTL ) {
			return $css_url;
		}

		$lock = $dir . '/' . $hash . '.lock';
		if ( is_file( $lock ) && ( time() - (int) filemtime( $lock ) ) > 120 ) {
			@unlink( $lock );
		}
		$handle = @fopen( $lock, 'x' );
		if ( ! $handle ) {
			return is_file( $css_file ) ? $css_url : null;
		}

		try {
			$response = wp_remote_get(
				$remote,
				array(
					'timeout'    => 8,
					'user-agent' => self::UA,
					'headers'    => array( 'Accept' => 'text/css,*/*;q=0.1' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$css = (string) wp_remote_retrieve_body( $response );
			if ( '' === $css || strlen( $css ) > 524288 ) {
				return null;
			}

			$css = (string) preg_replace_callback(
				'#url\(\s*([\'"]?)((?:https?:)?//fonts\.gstatic\.com/[^\'")]+)\1\s*\)#i',
				static function ( $match ) use ( $fonts_dir ) {
					$file = self::download_font( $match[2], $fonts_dir );
					return $file ? 'url(' . self::base_url() . '/fonts/' . $file . ')' : $match[0];
				},
				$css
			);

			$css = (string) preg_replace_callback(
				'#@font-face\s*\{[^}]*\}#i',
				static function ( $match ) {
					return false === stripos( $match[0], 'font-display' )
						? (string) preg_replace( '/\}\s*$/', ';font-display:swap;}', $match[0] )
						: $match[0];
				},
				$css
			);

			if ( false === file_put_contents( $css_file, $css, LOCK_EX ) ) {
				return null;
			}

			return $css_url;
		} finally {
			fclose( $handle );
			@unlink( $lock );
		}
	}

	/**
	 * Скачивает один файл шрифта.
	 *
	 * @param string $remote    URL.
	 * @param string $fonts_dir Каталог.
	 */
	private static function download_font( string $remote, string $fonts_dir ): ?string {
		if ( str_starts_with( $remote, '//' ) ) {
			$remote = 'https:' . $remote;
		}
		if ( 'fonts.gstatic.com' !== strtolower( (string) wp_parse_url( $remote, PHP_URL_HOST ) ) ) {
			return null;
		}
		if ( isset( self::$files[ $remote ] ) ) {
			return self::$files[ $remote ];
		}

		$name = basename( (string) wp_parse_url( $remote, PHP_URL_PATH ) );
		$name = substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name ), 0, 80 );
		if ( '' === $name ) {
			$name = 'font.woff2';
		}
		if ( ! str_contains( $name, '.' ) ) {
			$name .= '.woff2';
		}
		$name = substr( md5( $remote ), 0, 10 ) . '-' . $name;

		$path = $fonts_dir . '/' . $name;
		if ( is_file( $path ) && filesize( $path ) > 0 ) {
			self::$files[ $remote ] = $name;
			return $name;
		}

		$response = wp_remote_get(
			$remote,
			array(
				'timeout'    => 8,
				'user-agent' => self::UA,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > 3145728 ) {
			return null;
		}
		if ( false === file_put_contents( $path, $body, LOCK_EX ) ) {
			return null;
		}

		self::$files[ $remote ] = $name;

		return $name;
	}

	/**
	 * Удаляет локальный кэш шрифтов (деактивация, удаление, кнопка в админке).
	 */
	public static function purge_files(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $item ) {
				/** @var \SplFileInfo $item */
				if ( $item->isLink() || $item->isFile() ) {
					@unlink( $item->getPathname() );
				} elseif ( $item->isDir() ) {
					@rmdir( $item->getPathname() );
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			return false;
		}

		@rmdir( $dir );
		self::$files = array();

		return true;
	}
}
