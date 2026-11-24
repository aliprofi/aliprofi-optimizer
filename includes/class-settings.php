<?php
/**
 * Хранение и валидация настроек. Одна автозагружаемая опция — без SQL-запросов на фронтенде.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Доступ к настройкам плагина.
 */
final class Settings {

	public const OPTION = 'aliprofi_optimizer';
	public const GROUP  = 'aliprofi_optimizer';
	public const PAGE   = 'aliprofi-optimizer';

	/**
	 * Настройки в рамках текущего запроса.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Значения по умолчанию.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'             => 1,
			'purge'               => 1,
			'purge_method'        => 'fs',
			'cache_path'          => '/var/cache/nginx/fastcgi',
			'cache_levels'        => '1:2',
			'cache_key'           => '{scheme}{method}{host}{uri}',
			'purge_endpoint'      => '',
			'purge_on_menu'       => 1,
			'preload'             => 1,
			'preload_full'        => 0,
			'preload_batch'       => 3,
			'preload_limit'       => 30,
			'delay'               => 1,
			'delay_exclusions'    => "jquery\njquery-migrate",
			'delay_safe_mode'     => 0,
			'delay_timeout'       => 7000,
			'defer'               => 1,
			'defer_exclude'       => "jquery-core\njquery",
			'clean_emoji'         => 1,
			'clean_embed'         => 1,
			'clean_meta'          => 1,
			'lazy_exclude'        => "custom-logo\nhero",
			'img_dims'            => 0,
			'youtube'             => 1,
			'fonts'               => 1,
			'fonts_preload'       => '',
			'db_revisions'        => 1,
			'db_auto_drafts'      => 1,
			'db_trashed_posts'    => 1,
			'db_spam_comments'    => 1,
			'db_trashed_comments' => 1,
			'db_transients'       => 1,
			'db_optimize_tables'  => 1,
			'db_schedule'         => 0,
			'db_frequency'        => 'weekly',
		);
	}

	/**
	 * Все настройки (с кэшем на время запроса).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}

		return self::$cache;
	}

	/**
	 * Одно значение настройки.
	 *
	 * @param string $key Ключ.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Включена ли булева настройка.
	 *
	 * @param string $key Ключ.
	 */
	public static function is_on( string $key ): bool {
		return ! empty( self::get( $key ) );
	}

	/**
	 * Регистрация опции в Settings API.
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => self::defaults(),
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Валидация всех полей формы.
	 *
	 * @param mixed $input Данные формы.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$old      = self::all();
		$in       = is_array( $input ) ? $input : array();
		$out      = $old;

		if ( isset( $in['_scope'] ) ) {
			$scope = array_filter( array_map( 'sanitize_key', explode( ',', self::scalar( $in['_scope'] ) ) ) );
			foreach ( array_keys( $defaults ) as $key ) {
				if ( ! in_array( $key, $scope, true ) ) {
					$in[ $key ] = $old[ $key ] ?? $defaults[ $key ];
				}
			}
		}

		$bools = array(
			'enabled',
			'purge',
			'purge_on_menu',
			'preload',
			'preload_full',
			'delay',
			'delay_safe_mode',
			'defer',
			'clean_emoji',
			'clean_embed',
			'clean_meta',
			'img_dims',
			'youtube',
			'fonts',
			'db_revisions',
			'db_auto_drafts',
			'db_trashed_posts',
			'db_spam_comments',
			'db_trashed_comments',
			'db_transients',
			'db_optimize_tables',
			'db_schedule',
		);
		foreach ( $bools as $key ) {
			$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
		}

		$method              = isset( $in['purge_method'] ) ? sanitize_text_field( self::scalar( $in['purge_method'] ) ) : '';
		$out['purge_method'] = in_array( $method, array( 'fs', 'http', 'both' ), true ) ? $method : $old['purge_method'];

		$path = isset( $in['cache_path'] ) ? rtrim( str_replace( '\\', '/', trim( self::scalar( $in['cache_path'] ) ) ), '/' ) : '';
		if ( '' === $path ) {
			$out['cache_path'] = '';
		} elseif ( self::is_safe_dir( $path ) ) {
			$out['cache_path'] = $path;
		} else {
			$out['cache_path'] = $old['cache_path'];
			add_settings_error( self::OPTION, 'cache_path', 'Путь должен быть абсолютным и не указывать на корень сайта или системный каталог.', 'error' );
		}

		$levels = isset( $in['cache_levels'] ) ? trim( self::scalar( $in['cache_levels'] ) ) : '';
		if ( '' === $levels || preg_match( '#^\d{1,2}(?::\d{1,2}){0,2}$#', $levels ) ) {
			$out['cache_levels'] = $levels;
		} else {
			$out['cache_levels'] = $old['cache_levels'];
			add_settings_error( self::OPTION, 'cache_levels', 'Уровни кэша задаются в формате 1:2 (не более трёх уровней).', 'error' );
		}

		$key              = isset( $in['cache_key'] ) ? trim( sanitize_text_field( self::scalar( $in['cache_key'] ) ) ) : '';
		$out['cache_key'] = '' !== $key ? $key : $defaults['cache_key'];

		$endpoint = isset( $in['purge_endpoint'] ) ? trim( self::scalar( $in['purge_endpoint'] ) ) : '';
		if ( '' !== $endpoint ) {
			$endpoint = esc_url_raw( $endpoint );
			if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
				$endpoint = '';
				add_settings_error( self::OPTION, 'purge_endpoint', 'Адрес PURGE-обработчика должен начинаться с http:// или https://.', 'error' );
			}
		}
		$out['purge_endpoint'] = $endpoint;

		$batch                = isset( $in['preload_batch'] ) ? absint( $in['preload_batch'] ) : 0;
		$out['preload_batch'] = max( 1, min( 5, $batch ? $batch : (int) $defaults['preload_batch'] ) );

		$limit                = isset( $in['preload_limit'] ) ? absint( $in['preload_limit'] ) : 0;
		$out['preload_limit'] = max( 5, min( 200, $limit ? $limit : (int) $defaults['preload_limit'] ) );

		$out['delay_exclusions'] = self::lines( $in['delay_exclusions'] ?? '', 300 );
		$out['defer_exclude']    = self::lines( $in['defer_exclude'] ?? '', 200 );
		$out['lazy_exclude']     = self::lines( $in['lazy_exclude'] ?? '', 200 );

		$timeout              = isset( $in['delay_timeout'] ) ? absint( $in['delay_timeout'] ) : 0;
		$out['delay_timeout'] = min( 60000, $timeout );

		$frequency              = isset( $in['db_frequency'] ) ? sanitize_key( self::scalar( $in['db_frequency'] ) ) : '';
		$out['db_frequency']    = in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ? $frequency : ( $old['db_frequency'] ?? 'weekly' );

		$out['fonts_preload'] = self::url_lines( $in['fonts_preload'] ?? '', 50 );

		self::$cache = $out;

		return $out;
	}

	/**
	 * Безопасен ли путь как директория кэша (защита от удаления корня сайта и системных каталогов).
	 *
	 * @param string $path Абсолютный путь.
	 */
	public static function is_safe_dir( string $path ): bool {
		if ( '' === $path || '/' === $path || strlen( $path ) < 6 || ! str_starts_with( $path, '/' ) ) {
			return false;
		}
		if ( str_contains( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}

		$segments = array_filter( explode( '/', $path ), 'strlen' );
		if ( count( $segments ) < 3 ) {
			return false;
		}

		$deny = array( '/var', '/var/cache', '/var/www', '/etc', '/home', '/usr', '/tmp', '/opt', '/srv', '/root', '/mnt', '/media', '/dev', '/proc', '/sys' );
		if ( in_array( $path, $deny, true ) ) {
			return false;
		}
		foreach ( array( '/etc', '/usr', '/bin', '/sbin', '/boot', '/lib', '/lib64', '/dev', '/proc', '/sys', '/root' ) as $critical ) {
			if ( str_starts_with( $path, $critical . '/' ) ) {
				return false;
			}
		}

		$root = untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
		$real = realpath( $path );
		if ( false !== $real ) {
			$real_root = realpath( $root );
			if ( '/' === $real || false === $real_root ) {
				return false;
			}
			if ( $real_root === $real || str_starts_with( $real_root, $real . '/' ) ) {
				return false;
			}
		} elseif ( $root === $path || str_starts_with( $root, $path . '/' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Безопасное приведение входного значения к строке.
	 *
	 * @param mixed $value Значение.
	 */
	private static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Нормализация многострочного списка.
	 *
	 * @param mixed $value Значение.
	 * @param int   $limit Максимум строк.
	 */
	private static function lines( $value, int $limit ): string {
		$value = sanitize_textarea_field( self::scalar( $value ) );
		$out   = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return implode( "\n", $out );
	}

	/**
	 * Нормализация списка URL.
	 *
	 * @param mixed $value Значение.
	 * @param int   $limit Максимум строк.
	 */
	private static function url_lines( $value, int $limit ): string {
		$value = sanitize_textarea_field( self::scalar( $value ) );
		$out   = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = preg_match( '#^https?://#i', $line ) ? esc_url_raw( $line ) : '/' . ltrim( $line, '/' );
			if ( '' !== $url && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return implode( "\n", $out );
	}
}
