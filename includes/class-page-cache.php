<?php
/**
 * Собственный страничный кэш для шаред-хостинга: HTML в wp-content/cache + advanced-cache.php.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Файловый кэш готовых HTML-страниц.
 */
final class Page_Cache {

	private const DIR    = 'cache/aliprofi-optimizer';
	private const MARKER = 'Aliprofi Optimizer page cache';

	/**
	 * Куки, при которых страницу нельзя отдавать из общего кэша.
	 *
	 * @var string[]
	 */
	private const COOKIE_PREFIXES = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wordpress_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_',
		'wp_woocommerce_',
		'wp-wpml_current_language',
		'wpml_',
	);

	/**
	 * Путь текущего запроса, если буфер активен.
	 */
	private string $buffer_path = '';

	/**
	 * Был ли WP_CACHE добавлен в этом запросе.
	 */
	private static bool $wp_cache_written = false;

	/**
	 * Подписка на генерацию, очистку и обслуживание.
	 */
	public function run(): void {
		add_action( 'template_redirect', array( $this, 'maybe_start' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_install' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ), 10, 2 );
		add_action( 'aliprofi_optimizer_cache_purged', array( $this, 'on_purged' ), 10, 1 );
		add_action( 'aliprofi_optimizer_cache_purged_all', array( $this, 'on_purged_all' ) );

		add_action( 'save_post', array( $this, 'on_save' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete' ) );
		add_action( 'deleted_post', array( $this, 'on_deleted' ) );
		add_action( 'comment_post', array( $this, 'on_comment' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_edit_comment' ) );
		add_action( 'wp_update_nav_menu', array( $this, 'on_menu' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'on_layout_change' ) );
		add_action( 'customize_save_after', array( $this, 'on_layout_change' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'on_layout_change' ) );
	}

	/**
	 * Включён ли страничный кэш.
	 */
	public static function is_enabled(): bool {
		return Settings::is_on( 'enabled' ) && Settings::is_on( 'page_cache' ) && ! is_multisite();
	}

	/**
	 * Установка кэша при активации плагина.
	 */
	public static function activate(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::ensure_storage();
		if ( self::refresh_config() && self::install_dropin() ) {
			self::enable_wp_cache();
		}
	}

	/**
	 * Удаление drop-in и файлов при деактивации.
	 */
	public static function deactivate(): void {
		self::remove_dropin();
		self::purge_all();
		self::delete_root();
	}

	/**
	 * Удаление всех следов при удалении плагина.
	 */
	public static function uninstall(): void {
		self::deactivate();
	}

	/**
	 * Поддержание конфигурации и drop-in после обновления или смены настроек.
	 */
	public function maybe_install(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			if ( is_file( self::config_file() ) ) {
				self::refresh_config();
			}
			if ( 'ours' === self::dropin_state() ) {
				self::remove_dropin();
			}
			return;
		}

		self::ensure_storage();
		if ( self::refresh_config() && self::install_dropin() ) {
			self::enable_wp_cache();
		}
	}

	/**
	 * Реакция на сохранение настроек.
	 *
	 * @param mixed $old Старые настройки.
	 * @param mixed $new Новые настройки.
	 */
	public function on_settings_saved( $old, $new ): void {
		unset( $old, $new );

		if ( ! self::is_enabled() ) {
			if ( is_file( self::config_file() ) ) {
				self::refresh_config();
			}
			self::purge_all();
			self::remove_dropin();
			return;
		}

		self::ensure_storage();
		if ( self::refresh_config() && self::install_dropin() ) {
			self::enable_wp_cache();
		}
		self::purge_all();
	}

	/**
	 * Начало буферизации гостевой страницы.
	 */
	public function maybe_start(): void {
		if ( ! self::can_buffer() ) {
			return;
		}

		$path = self::request_path();
		if ( '' === $path || self::is_excluded_path( $path ) ) {
			return;
		}
		if ( ! self::ensure_storage() || headers_sent() ) {
			return;
		}

		$this->buffer_path = $path;
		ob_start( array( $this, 'store' ) );
	}

	/**
	 * Сохранение готового HTML после рендера.
	 *
	 * @param string $buffer Вывод страницы.
	 */
	public function store( $buffer ): string {
		$buffer = is_string( $buffer ) ? $buffer : '';

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return $buffer;
		}
		if ( '' === $this->buffer_path || '' === $buffer || strlen( $buffer ) < 255 || false === stripos( $buffer, '</html>' ) ) {
			return $buffer;
		}

		$code = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( 0 !== $code && 200 !== $code ) {
			return $buffer;
		}

		$content_type = '';
		foreach ( headers_list() as $header ) {
			$lower = strtolower( $header );
			if ( str_starts_with( $lower, 'content-type:' ) ) {
				$content_type = $lower;
			}
			if ( str_starts_with( $lower, 'set-cookie:' ) ) {
				return $buffer;
			}
			if ( str_starts_with( $lower, 'content-encoding:' ) ) {
				return $buffer;
			}
			if ( str_starts_with( $lower, 'cache-control:' ) && ( str_contains( $lower, 'private' ) || str_contains( $lower, 'no-store' ) ) ) {
				return $buffer;
			}
			if ( str_starts_with( $lower, 'vary:' ) && ( str_contains( $lower, '*' ) || str_contains( $lower, 'cookie' ) || str_contains( $lower, 'user-agent' ) ) ) {
				return $buffer;
			}
		}

		if ( '' !== $content_type && ! str_contains( $content_type, 'text/html' ) ) {
			return $buffer;
		}

		$file = self::file_for_path( $this->buffer_path );
		$dir  = dirname( $file );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $buffer;
		}

		$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
		if ( false === file_put_contents( $tmp, $buffer, LOCK_EX ) ) {
			return $buffer;
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
		}

		return $buffer;
	}

	/**
	 * Очистка кэша по списку URL из хуков сброса.
	 *
	 * @param mixed $urls URL.
	 */
	public function on_purged( $urls ): void {
		if ( is_array( $urls ) ) {
			self::purge_urls( $urls );
		}
	}

	/**
	 * Полная очистка страничного кэша.
	 */
	public function on_purged_all(): void {
		self::purge_all();
	}

	/**
	 * Сохранение опубликованной записи.
	 *
	 * @param int      $post_id ID записи.
	 * @param \WP_Post $post    Запись.
	 * @param bool     $update  Флаг обновления.
	 */
	public function on_save( $post_id, $post, $update ): void {
		unset( $update );

		if ( ! self::is_enabled() || ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$this->purge_post( $post );
	}

	/**
	 * Смена статуса записи.
	 *
	 * @param string   $new_status Новый статус.
	 * @param string   $old_status Старый статус.
	 * @param \WP_Post $post       Запись.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! self::is_enabled() || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		$this->purge_post( $post );
	}

	/**
	 * Удаление записи: очищаем её URL до удаления.
	 *
	 * @param int $post_id ID записи.
	 */
	public function on_before_delete( $post_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->purge_post( $post );
		}
	}

	/**
	 * После удаления очищаем главную.
	 */
	public function on_deleted(): void {
		if ( self::is_enabled() ) {
			self::purge_urls( array( home_url( '/' ) ) );
		}
	}

	/**
	 * Новый комментарий.
	 *
	 * @param int $comment_id ID комментария.
	 * @param int $approved   Статус.
	 */
	public function on_comment( $comment_id, $approved ): void {
		unset( $approved );

		if ( ! self::is_enabled() ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( get_post( (int) $comment->comment_post_ID ) );
		}
	}

	/**
	 * Изменение комментария.
	 *
	 * @param int $comment_id ID комментария.
	 */
	public function on_edit_comment( $comment_id ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->purge_post( get_post( (int) $comment->comment_post_ID ) );
		}
	}

	/**
	 * Меню выводится на большинстве страниц — очищаем всё.
	 */
	public function on_menu(): void {
		if ( self::is_enabled() ) {
			self::purge_all();
		}
	}

	/**
	 * Смена темы, настройщик или виджеты меняют вёрстку всех страниц.
	 */
	public function on_layout_change(): void {
		if ( self::is_enabled() ) {
			self::purge_all();
		}
	}

	/**
	 * Очистка URL записи.
	 *
	 * @param mixed $post Запись.
	 */
	private function purge_post( $post ): void {
		if ( $post instanceof \WP_Post && 'nav_menu_item' !== $post->post_type ) {
			self::purge_urls( Cache::urls_for_post( $post ) );
		}
	}

	/**
	 * Можно ли буферизовать текущий запрос.
	 */
	private function can_buffer(): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method ) {
			return false;
		}
		if ( ! empty( $_SERVER['QUERY_STRING'] ) || isset( $_SERVER['HTTP_RANGE'] ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		if ( is_user_logged_in() || is_preview() || is_404() || is_feed() || is_robots() || is_trackback() || is_search() ) {
			return false;
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return false;
		}
		if ( self::has_bypass_cookie() || ! self::host_matches() ) {
			return false;
		}

		return '' !== self::request_path();
	}

	/**
	 * Есть ли куки авторизации, корзины или комментатора.
	 */
	private static function has_bypass_cookie(): bool {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( self::COOKIE_PREFIXES as $prefix ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Совпадает ли запрос с домашним хостом и портом.
	 */
	private static function host_matches(): bool {
		$host_header = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
		if ( '' === $host_header ) {
			return false;
		}

		$port = null;
		if ( preg_match( '/^(.+):(\d+)$/', $host_header, $matches ) ) {
			$host_header = $matches[1];
			$port        = (int) $matches[2];
		}

		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return false;
		}
		if ( strtolower( (string) $home['host'] ) !== $host_header ) {
			return false;
		}

		$home_port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === strtolower( (string) ( $home['scheme'] ?? '' ) ) ? 443 : 80 );

		return null === $port || $port === $home_port;
	}

	/**
	 * Путь текущего запроса без query.
	 */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		$path = rawurldecode( $path );

		return '/' === $path[0] ? $path : '/' . $path;
	}

	/**
	 * Служебные URL, которые нельзя кэшировать.
	 *
	 * @param string $path Путь.
	 */
	private static function is_excluded_path( string $path ): bool {
		if ( preg_match( '#(?:^|/)(?:wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php)(?:/|$)#i', $path ) ) {
			return true;
		}
		if ( preg_match( '#/(?:feed|embed|trackback)(?:/|$)#i', $path ) ) {
			return true;
		}
		if ( preg_match( '#/(?:robots\.txt|favicon\.ico)$#i', $path ) ) {
			return true;
		}

		foreach ( self::exclude_lines() as $line ) {
			if ( '' !== $line && false !== strpos( $path, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Пользовательские исключения.
	 *
	 * @return string[]
	 */
	private static function exclude_lines(): array {
		$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'page_cache_exclude' ) );
		$out   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$out[] = '/' === $line[0] ? $line : '/' . $line;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Файл кэша для пути.
	 *
	 * @param string $path Путь.
	 */
	private static function file_for_path( string $path ): string {
		$home = wp_parse_url( home_url( '/' ) );
		$host = is_array( $home ) && ! empty( $home['host'] ) ? strtolower( (string) $home['host'] ) : '';
		$key  = md5( $host . '|' . $path );

		return self::pages_dir() . '/' . substr( $key, 0, 2 ) . '/' . substr( $key, 2, 2 ) . '/' . $key . '.html';
	}

	/**
	 * Удалить URL из страничного кэша.
	 *
	 * @param string[] $urls URL.
	 */
	public static function purge_urls( array $urls ): int {
		$deleted = 0;

		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			foreach ( self::paths_for_url( $url ) as $path ) {
				$file = self::file_for_path( $path );
				if ( is_file( $file ) && @unlink( $file ) ) {
					++$deleted;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Варианты пути URL (со слешем и без).
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	private static function paths_for_url( string $url ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return array();
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = home_url( $url );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return array();
		}

		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return array();
		}
		if ( strtolower( (string) $parts['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return array();
		}

		$path  = rawurldecode( (string) $parts['path'] );
		$paths = array( '/' === $path[0] ? $path : '/' . $path );

		if ( '/' !== $paths[0] ) {
			$paths[] = str_ends_with( $paths[0], '/' ) ? rtrim( $paths[0], '/' ) : $paths[0] . '/';
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Полная очистка HTML-файлов.
	 */
	public static function purge_all(): bool {
		$pages = self::pages_dir();
		if ( ! is_dir( $pages ) ) {
			return true;
		}

		$ok = self::remove_tree( $pages, false );
		if ( self::is_enabled() ) {
			self::ensure_storage();
		}

		return $ok;
	}

	/**
	 * Актуальное состояние для админки.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function status(): array {
		if ( ! Settings::is_on( 'page_cache' ) ) {
			return array( 'off', 'Кэш страниц выключен.' );
		}
		if ( is_multisite() ) {
			return array( 'warn', 'В мультисайте кэш страниц не поддерживается.' );
		}
		if ( ! self::is_enabled() ) {
			return array( 'warn', 'Оптимизация выключена главным переключателем.' );
		}

		$pages = self::pages_dir();
		if ( ! is_dir( $pages ) || ! is_writable( $pages ) ) {
			return array( 'bad', 'Каталог wp-content/cache/aliprofi-optimizer недоступен для записи.' );
		}

		$dropin = self::dropin_state();
		if ( 'foreign' === $dropin ) {
			return array( 'bad', 'Найден чужой advanced-cache.php — отключите другой плагин кэширования.' );
		}
		if ( 'missing' === $dropin ) {
			return array( 'warn', 'Drop-in advanced-cache.php ещё не установлен. Откройте эту страницу в админке.' );
		}
		if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) && ! self::$wp_cache_written ) {
			return array( 'warn', 'WP_CACHE не включён в wp-config.php. Добавьте define( \'WP_CACHE\', true );' );
		}

		return array( 'ok', 'Кэш страниц работает: HTML хранится в wp-content/cache/aliprofi-optimizer/pages.' );
	}

	/**
	 * Проверка наличия drop-in.
	 */
	public static function dropin_state(): string {
		$file = self::dropin_file();
		if ( ! is_file( $file ) ) {
			return 'missing';
		}

		$head = @file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- файл может исчезнуть между проверкой и чтением.

		return is_string( $head ) && str_contains( $head, self::MARKER ) ? 'ours' : 'foreign';
	}

	/**
	 * Каталог кэша.
	 */
	private static function root(): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DIR;
	}

	/**
	 * Каталог HTML-файлов.
	 */
	private static function pages_dir(): string {
		return self::root() . '/pages';
	}

	/**
	 * Файл конфигурации drop-in.
	 */
	private static function config_file(): string {
		return self::root() . '/config.php';
	}

	/**
	 * Путь к advanced-cache.php.
	 */
	private static function dropin_file(): string {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/advanced-cache.php';
	}

	/**
	 * Создать каталоги и защитные файлы.
	 */
	private static function ensure_storage(): bool {
		$root  = self::root();
		$pages = self::pages_dir();

		if ( ! wp_mkdir_p( $pages ) ) {
			return false;
		}

		$deny = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
		$index = "<?php\n// Silence is golden.\n";

		foreach ( array( $root, $pages ) as $dir ) {
			if ( ! is_file( $dir . '/.htaccess' ) ) {
				@file_put_contents( $dir . '/.htaccess', $deny, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- необязательный защитный файл.
			}
			if ( ! is_file( $dir . '/index.php' ) ) {
				@file_put_contents( $dir . '/index.php', $index, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- необязательный защитный файл.
			}
		}

		return is_dir( $pages ) && is_writable( $pages );
	}

	/**
	 * Записать конфигурацию для advanced-cache.php.
	 */
	private static function refresh_config(): bool {
		if ( ! self::ensure_storage() ) {
			return false;
		}

		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return false;
		}

		$ttl = max( 1, min( 168, (int) Settings::get( 'page_cache_ttl' ) ) ) * HOUR_IN_SECONDS;
		$port = isset( $home['port'] ) ? (int) $home['port'] : ( 'https' === strtolower( (string) ( $home['scheme'] ?? '' ) ) ? 443 : 80 );

		$config = array(
			'enabled'   => self::is_enabled(),
			'ttl'       => $ttl,
			'host'      => strtolower( (string) $home['host'] ),
			'port'      => $port,
			'cache_dir' => self::pages_dir(),
			'exclude'   => self::exclude_lines(),
			'version'   => ALIPROFI_OPTIMIZER_VERSION,
		);

		$code = "<?php\ndefined( 'ABSPATH' ) || exit;\n\nreturn " . var_export( $config, true ) . ";\n";

		return self::write_file( self::config_file(), $code );
	}

	/**
	 * Установить drop-in, не затрагивая чужой advanced-cache.php.
	 */
	private static function install_dropin(): bool {
		$file = self::dropin_file();
		if ( is_file( $file ) ) {
			$head = @file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $head ) && '' !== $head && ! str_contains( $head, self::MARKER ) ) {
				return false;
			}
		}

		return self::write_file( $file, self::dropin_code() );
	}

	/**
	 * Удалить только свой drop-in.
	 */
	private static function remove_dropin(): void {
		$file = self::dropin_file();
		if ( 'ours' === self::dropin_state() && is_writable( dirname( $file ) ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- файл принадлежит плагину.
		}
	}

	/**
	 * Включить WP_CACHE в wp-config.php.
	 */
	private static function enable_wp_cache(): bool {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}
		if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
			return false;
		}

		$file = self::wp_config_path();
		if ( '' === $file ) {
			return false;
		}

		$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_string( $contents ) || '' === $contents ) {
			return false;
		}
		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(?:false|0)\s*\)/i', $contents ) ) {
			return false;
		}
		if ( str_contains( $contents, 'WP_CACHE' ) ) {
			return false;
		}

		$new = preg_replace(
			'/<\?php\b/',
			"<?php\n\ndefine( 'WP_CACHE', true ); // Added by Aliprofi Optimizer\n",
			$contents,
			1,
			$count
		);
		if ( 1 !== $count || ! is_string( $new ) || ! str_contains( $new, 'WP_CACHE' ) ) {
			return false;
		}

		$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
		if ( false === file_put_contents( $tmp, $new, LOCK_EX ) ) {
			return false;
		}
		$perms = @fileperms( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $tmp, false !== $perms ? ( $perms & 0777 ) : 0644 );
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}

		self::$wp_cache_written = true;
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}

		return true;
	}

	/**
	 * Найти wp-config.php.
	 */
	private static function wp_config_path(): string {
		$candidates = array(
			rtrim( ABSPATH, '/\\' ) . '/wp-config.php',
			dirname( rtrim( ABSPATH, '/\\' ) ) . '/wp-config.php',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) && is_readable( $candidate ) && is_writable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Содержимое drop-in.
	 */
	private static function dropin_code(): string {
		return <<<'PHP'
<?php
/**
 * Aliprofi Optimizer page cache drop-in.
 *
 * Marker: Aliprofi Optimizer page cache
 *
 * @package Aliprofi_Optimizer
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ALIPROFI_OPTIMIZER_PAGE_CACHE_DROPIN' ) ) {
	define( 'ALIPROFI_OPTIMIZER_PAGE_CACHE_DROPIN', true );
}

$aliprofi_pc_config_file = __DIR__ . '/cache/aliprofi-optimizer/config.php';
if ( ! is_readable( $aliprofi_pc_config_file ) ) {
	return;
}

$aliprofi_pc = include $aliprofi_pc_config_file;
if ( ! is_array( $aliprofi_pc ) || empty( $aliprofi_pc['enabled'] ) || empty( $aliprofi_pc['cache_dir'] ) ) {
	return;
}

$aliprofi_pc_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
if ( 'GET' !== $aliprofi_pc_method && 'HEAD' !== $aliprofi_pc_method ) {
	return;
}
if ( ! empty( $_SERVER['QUERY_STRING'] ) || isset( $_SERVER['HTTP_RANGE'] ) ) {
	return;
}

$aliprofi_pc_path = isset( $_SERVER['REQUEST_URI'] ) ? parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '/';
if ( ! is_string( $aliprofi_pc_path ) || '' === $aliprofi_pc_path ) {
	$aliprofi_pc_path = '/';
}
$aliprofi_pc_path = rawurldecode( $aliprofi_pc_path );
if ( '/' !== $aliprofi_pc_path[0] ) {
	$aliprofi_pc_path = '/' . $aliprofi_pc_path;
}

if (
	preg_match( '#(?:^|/)(?:wp-admin|wp-login\.php|wp-json|wp-cron\.php|xmlrpc\.php|wp-signup\.php|wp-activate\.php)(?:/|$)#i', $aliprofi_pc_path )
	|| preg_match( '#/(?:feed|embed|trackback)(?:/|$)#i', $aliprofi_pc_path )
	|| preg_match( '#/(?:robots\.txt|favicon\.ico)$#i', $aliprofi_pc_path )
) {
	return;
}

$aliprofi_pc_exclude = isset( $aliprofi_pc['exclude'] ) && is_array( $aliprofi_pc['exclude'] ) ? $aliprofi_pc['exclude'] : array();
foreach ( $aliprofi_pc_exclude as $aliprofi_pc_needle ) {
	if ( is_string( $aliprofi_pc_needle ) && '' !== $aliprofi_pc_needle && false !== strpos( $aliprofi_pc_path, $aliprofi_pc_needle ) ) {
		return;
	}
}

$aliprofi_pc_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
if ( '' === $aliprofi_pc_host ) {
	return;
}

$aliprofi_pc_port = null;
if ( preg_match( '/^(.+):(\d+)$/', $aliprofi_pc_host, $aliprofi_pc_matches ) ) {
	$aliprofi_pc_host = $aliprofi_pc_matches[1];
	$aliprofi_pc_port = (int) $aliprofi_pc_matches[2];
}
if ( $aliprofi_pc_host !== strtolower( (string) $aliprofi_pc['host'] ) ) {
	return;
}
if ( null !== $aliprofi_pc_port && $aliprofi_pc_port !== (int) $aliprofi_pc['port'] ) {
	return;
}

$aliprofi_pc_cookie_prefixes = array(
	'wordpress_logged_in_',
	'wordpress_sec_',
	'wordpress_',
	'wp-postpass_',
	'comment_author_',
	'woocommerce_',
	'wp_woocommerce_',
	'wp-wpml_current_language',
	'wpml_',
);
foreach ( array_keys( (array) $_COOKIE ) as $aliprofi_pc_cookie ) {
	foreach ( $aliprofi_pc_cookie_prefixes as $aliprofi_pc_prefix ) {
		if ( 0 === strpos( (string) $aliprofi_pc_cookie, $aliprofi_pc_prefix ) ) {
			return;
		}
	}
}

$aliprofi_pc_key  = md5( strtolower( (string) $aliprofi_pc['host'] ) . '|' . $aliprofi_pc_path );
$aliprofi_pc_file = rtrim( (string) $aliprofi_pc['cache_dir'], '/\\' ) . '/' . substr( $aliprofi_pc_key, 0, 2 ) . '/' . substr( $aliprofi_pc_key, 2, 2 ) . '/' . $aliprofi_pc_key . '.html';

if ( ! is_readable( $aliprofi_pc_file ) ) {
	return;
}

$aliprofi_pc_ttl   = isset( $aliprofi_pc['ttl'] ) ? (int) $aliprofi_pc['ttl'] : 0;
$aliprofi_pc_mtime = @filemtime( $aliprofi_pc_file );
if ( false === $aliprofi_pc_mtime ) {
	return;
}
$aliprofi_pc_mtime = (int) $aliprofi_pc_mtime;
if ( $aliprofi_pc_ttl > 0 && $aliprofi_pc_mtime + $aliprofi_pc_ttl < time() ) {
	return;
}

$aliprofi_pc_last_modified = gmdate( 'D, d M Y H:i:s', $aliprofi_pc_mtime ) . ' GMT';
header( 'Last-Modified: ' . $aliprofi_pc_last_modified );

if ( ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) === $aliprofi_pc_mtime ) {
	header( 'Cache-Control: public, max-age=' . max( 0, $aliprofi_pc_ttl - ( time() - $aliprofi_pc_mtime ) ) );
	$aliprofi_pc_protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
	header( $aliprofi_pc_protocol . ' 304 Not Modified', true, 304 );
	exit;
}

header( 'Content-Type: text/html; charset=UTF-8' );
header( 'X-Aliprofi-Cache: HIT' );
header( 'Cache-Control: public, max-age=' . max( 0, $aliprofi_pc_ttl - ( time() - $aliprofi_pc_mtime ) ) );
header( 'Vary: Accept-Encoding' );

if ( 'HEAD' === $aliprofi_pc_method ) {
	exit;
}

readfile( $aliprofi_pc_file );
exit;
PHP;
	}

	/**
	 * Атомарная запись файла.
	 *
	 * @param string $file    Путь.
	 * @param string $content Содержимое.
	 */
	private static function write_file( string $file, string $content ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( is_file( $file ) && @file_get_contents( $file ) === $content ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}

		$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return false;
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
			return false;
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $file, true );
		}

		return true;
	}

	/**
	 * Удаление каталога или его содержимого.
	 *
	 * @param string $dir      Каталог.
	 * @param bool   $with_dir Удалять ли сам каталог.
	 */
	private static function remove_tree( string $dir, bool $with_dir = true ): bool {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
			return true;
		}

		$root = self::root();
		if ( $dir !== $root && ! str_starts_with( $dir, $root . '/' ) ) {
			return false;
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

		if ( $with_dir ) {
			@rmdir( $dir );
		}

		return true;
	}

	/**
	 * Удалить весь каталог плагина.
	 */
	private static function delete_root(): void {
		self::remove_tree( self::root() );
	}
}
