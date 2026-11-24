<?php
/**
 * Сброс FastCGI-кэша OpenResty: файловый и через HTTP PURGE.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Очистка серверного кэша.
 */
final class Cache {

	/**
	 * Уже сброшенные в этом запросе цели (URI), чтобы не дублировать работу хуков.
	 *
	 * @var array<string, true>
	 */
	private array $done = array();

	/**
	 * Подписка на хуки изменения контента.
	 */
	public function run(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );
		add_action( 'deleted_post', array( $this, 'on_deleted' ) );
		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_edit_comment' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu' ), 10, 2 );
	}

	/**
	 * Сохранение опубликованной записи.
	 *
	 * @param int          $post_id ID записи.
	 * @param \WP_Post     $post    Запись.
	 * @param bool         $update  Флаг обновления.
	 */
	public function on_save( $post_id, $post, $update ): void {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'nav_menu_item' === $post->post_type ) {
			return;
		}
		$this->purge_post( $post );
	}

	/**
	 * Смена статуса записи (публикация, снятие с публикации, корзина).
	 *
	 * @param string   $new_status Новый статус.
	 * @param string   $old_status Старый статус.
	 * @param \WP_Post $post       Запись.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || 'nav_menu_item' === $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$this->purge_post( $post );
	}

	/**
	 * Удаление записи: сбрасываем её URL и главную.
	 *
	 * @param int $post_id ID записи.
	 */
	public function on_before_delete( $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && 'nav_menu_item' !== $post->post_type ) {
			$this->purge_post( $post );
		}
	}

	/**
	 * После удаления остаётся сбросить архивы и главную.
	 */
	public function on_deleted(): void {
		$this->purge_urls( array( home_url( '/' ) ) );
	}

	/**
	 * Новый комментарий.
	 *
	 * @param int $comment_id ID комментария.
	 * @param int $approved   Статус модерации.
	 */
	public function on_comment( $comment_id, $approved ): void {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( get_post( (int) $comment->comment_post_ID ) );
		}
	}

	/**
	 * Редактирование комментария.
	 *
	 * @param int $comment_id ID комментария.
	 */
	public function on_edit_comment( $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( get_post( (int) $comment->comment_post_ID ) );
		}
	}

	/**
	 * Изменение меню: оно выводится на большинстве страниц.
	 */
	public function on_menu(): void {
		$this->purge_urls( array( home_url( '/' ) ) );
		if ( Settings::is_on( 'purge_on_menu' ) ) {
			self::purge_all();
		}
	}

	/**
	 * Сброс URL записи, главной, архивов типа записи, рубрик, меток и автора.
	 *
	 * @param \WP_Post|null $post Запись.
	 */
	private function purge_post( $post ): void {
		if ( ! $post instanceof \WP_Post || ! Settings::is_on( 'purge' ) ) {
			return;
		}

		$this->purge_urls( self::urls_for_post( $post ) );
	}

	/**
	 * URL, которые выводят данные записи: сама запись, главная, архив, автор, рубрики, метки.
	 *
	 * @param \WP_Post $post Запись.
	 * @return string[]
	 */
	public static function urls_for_post( \WP_Post $post ): array {
		$urls = array( home_url( '/' ) );

		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		$type = get_post_type_object( $post->post_type );
		if ( $type && ! empty( $type->public ) ) {
			if ( ! empty( $type->has_archive ) ) {
				$archive = get_post_type_archive_link( $post->post_type );
				if ( $archive ) {
					$urls[] = $archive;
				}
			}
			$author = get_author_posts_url( (int) $post->post_author );
			if ( $author ) {
				$urls[] = $author;
			}
		}

		if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			$categories = get_the_category( $post->ID );
			if ( is_array( $categories ) ) {
				foreach ( $categories as $category ) {
					$link = get_category_link( $category );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' ) ) {
			$tags = get_the_tags( $post->ID );
			if ( is_array( $tags ) ) {
				foreach ( $tags as $tag ) {
					$link = get_tag_link( $tag );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
	}

	/**
	 * Сброс списка URL с дедупликацией в рамках запроса.
	 *
	 * @param string[] $urls URL или пути.
	 */
	private function purge_urls( array $urls ): void {
		$settings = Settings::all();
		if ( empty( $settings['purge'] ) ) {
			return;
		}

		$targets = array();
		foreach ( $urls as $url ) {
			foreach ( self::targets( (string) $url ) as $target ) {
				$targets[ $target['uri'] ] = $target;
			}
		}

		$purged = false;
		foreach ( $targets as $uri => $target ) {
			if ( isset( $this->done[ $uri ] ) ) {
				continue;
			}
			$this->done[ $uri ] = true;
			$purged             = true;

			if ( 'fs' === $settings['purge_method'] || 'both' === $settings['purge_method'] ) {
				self::purge_file( $target, $settings );
			}
			if ( 'http' === $settings['purge_method'] || 'both' === $settings['purge_method'] ) {
				self::purge_http( $target, $settings );
			}
		}

		if ( $purged ) {
			do_action( 'aliprofi_optimizer_cache_purged', array_values( array_unique( $urls ) ) );
		}
	}

	/**
	 * Превращает URL в набор вариантов URI (со слешем/без, с query/без).
	 *
	 * @param string $url URL или путь.
	 * @return array<int, array{scheme: string, host: string, uri: string}>
	 */
	private static function targets( string $url ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return array();
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query  = isset( $parts['query'] ) ? $parts['query'] : '';

		$uris = array( $path . ( '' !== $query ? '?' . $query : '' ) );
		if ( '' !== $query ) {
			$uris[] = $path;
		}
		if ( '/' !== $path ) {
			$alt = str_ends_with( $path, '/' ) ? rtrim( $path, '/' ) : $path . '/';
			if ( '' !== $alt ) {
				$uris[] = $alt . ( '' !== $query ? '?' . $query : '' );
				if ( '' !== $query ) {
					$uris[] = $alt;
				}
			}
		}

		$out = array();
		foreach ( array_unique( $uris ) as $uri ) {
			$out[] = array(
				'scheme' => $scheme,
				'host'   => $host,
				'uri'    => $uri,
			);
		}

		return $out;
	}

	/**
	 * Удаление файла кэша по вычисленному ключу.
	 *
	 * @param array<string, string> $target   Цель (scheme/host/uri).
	 * @param array<string, mixed>  $settings Настройки.
	 */
	private static function purge_file( array $target, array $settings ): bool {
		$dir = rtrim( (string) $settings['cache_path'], '/' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return false;
		}

		$key  = strtr(
			(string) $settings['cache_key'],
			array(
				'{scheme}' => $target['scheme'],
				'{method}' => 'GET',
				'{host}'   => $target['host'],
				'{uri}'    => $target['uri'],
			)
		);
		$hash = md5( $key );

		foreach ( self::cache_files( $dir, (string) $settings['cache_levels'], $hash ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}

		return true;
	}

	/**
	 * Точечный сброс кэша одного URL (кнопка «Очистить эту страницу»).
	 *
	 * @param string $url Абсолютный URL страницы.
	 */
	public static function purge_url( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$settings = Settings::all();
		$method   = (string) $settings['purge_method'];
		$tried    = false;
		$ok       = false;

		foreach ( self::targets( $url ) as $target ) {
			if ( 'fs' === $method || 'both' === $method ) {
				$tried = true;
				$ok    = self::purge_file( $target, $settings ) || $ok;
			}
			if ( 'http' === $method || 'both' === $method ) {
				$tried = true;
				$ok    = self::purge_http( $target, $settings ) || $ok;
			}
		}

		if ( $tried && $ok ) {
			do_action( 'aliprofi_optimizer_cache_purged', array( $url ) );
		}

		return $tried && $ok;
	}

	/**
	 * Возможные пути файла кэша для заданного MD5-хеша.
	 *
	 * @param string $dir    Каталог кэша.
	 * @param string $levels Уровни вида 1:2.
	 * @param string $hash   MD5 ключа.
	 * @return string[]
	 */
	private static function cache_files( string $dir, string $levels, string $hash ): array {
		$files = array( $dir . '/' . $hash );

		$parts = array();
		foreach ( explode( ':', $levels ) as $part ) {
			$len = (int) $part;
			if ( $len > 0 ) {
				$parts[] = $len;
			}
		}
		if ( ! $parts ) {
			return $files;
		}

		$position = strlen( $hash );
		$tail     = $dir;
		foreach ( $parts as $len ) {
			$position -= $len;
			if ( $position < 0 ) {
				$tail = null;
				break;
			}
			$tail .= '/' . substr( $hash, $position, $len );
		}
		if ( $tail ) {
			$files[] = $tail . '/' . $hash;
		}

		$position = 0;
		$head     = $dir;
		foreach ( $parts as $len ) {
			if ( $position + $len > strlen( $hash ) ) {
				$head = null;
				break;
			}
			$head    .= '/' . substr( $hash, $position, $len );
			$position += $len;
		}
		if ( $head ) {
			$files[] = $head . '/' . $hash;
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * HTTP PURGE по настроенному обработчику.
	 *
	 * @param array<string, string> $target   Цель.
	 * @param array<string, mixed>  $settings Настройки.
	 */
	private static function purge_http( array $target, array $settings ): bool {
		$endpoint = untrailingslashit( (string) $settings['purge_endpoint'] );
		if ( '' === $endpoint ) {
			return false;
		}

		$url  = $endpoint . ( str_starts_with( $target['uri'], '/' ) ? $target['uri'] : '/' . $target['uri'] );
		$resp = wp_remote_request(
			$url,
			array(
				'method'     => 'PURGE',
				'timeout'    => 3,
				'redirection' => 0,
			)
		);

		return self::ok_http( $resp );
	}

	/**
	 * Рекурсивная очистка всего кэша.
	 */
	public static function purge_all(): bool {
		$settings = Settings::all();
		$method   = (string) $settings['purge_method'];
		$tried    = false;
		$ok       = false;

		if ( 'fs' === $method || 'both' === $method ) {
			$tried = true;
			$ok    = self::clear_dir( (string) $settings['cache_path'] ) || $ok;
		}

		if ( 'http' === $method || 'both' === $method ) {
			$tried = true;
			$ok    = self::purge_all_http( (string) $settings['purge_endpoint'] ) || $ok;
		}

		$result = $tried && $ok;
		if ( $result ) {
			do_action( 'aliprofi_optimizer_cache_purged_all' );
		}

		return $result;
	}

	/**
	 * Полная очистка через HTTP (wildcard, затем главная).
	 *
	 * @param string $endpoint Базовый адрес PURGE.
	 */
	private static function purge_all_http( string $endpoint ): bool {
		$endpoint = untrailingslashit( trim( $endpoint ) );
		if ( '' === $endpoint ) {
			return false;
		}

		$responses = array(
			$endpoint . '/*',
			$endpoint . '/',
		);

		$ok = false;
		foreach ( $responses as $url ) {
			$resp = wp_remote_request(
				$url,
				array(
					'method'     => 'PURGE',
					'timeout'    => 5,
					'redirection' => 0,
				)
			);
			$ok = self::ok_http( $resp ) || $ok;
		}

		return $ok;
	}

	/**
	 * Успешен ли ответ PURGE (204/2xx, а 404 считаем «кэша не было»).
	 *
	 * @param array|\WP_Error $resp Ответ.
	 */
	private static function ok_http( $resp ): bool {
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );

		return ( $code >= 200 && $code < 300 ) || 404 === $code;
	}

	/**
	 * Удаление всех файлов в каталоге кэша (сам каталог остаётся).
	 *
	 * @param string $dir Каталог.
	 */
	private static function clear_dir( string $dir ): bool {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! Settings::is_safe_dir( $dir ) || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$real = realpath( $dir );
		if ( false === $real || '/' === $real ) {
			return false;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ),
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

		return true;
	}
}
