<?php
/**
 * Точка сборки модулей плагина.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Запуск модулей и активация/деактивация.
 */
final class Plugin {

	/**
	 * Старт плагина.
	 */
	public static function boot(): void {
		( new self() )->run();
	}

	/**
	 * Регистрация хуков.
	 */
	public function run(): void {
		( new Admin() )->run();
		( new Database() )->run();

		if ( ! Settings::is_on( 'enabled' ) ) {
			return;
		}
		if ( Settings::is_on( 'purge' ) ) {
			( new Cache() )->run();
		}

		add_action( 'wp', array( $this, 'frontend' ), 1 );
	}

	/**
	 * Подключение фронтенд-модулей после разбора основного запроса.
	 */
	public function frontend(): void {
		if ( ! $this->allowed() ) {
			return;
		}

		$settings = Settings::all();

		if ( ! empty( $settings['defer'] ) || ! empty( $settings['clean_emoji'] ) || ! empty( $settings['clean_embed'] ) || ! empty( $settings['clean_meta'] ) ) {
			( new Scripts() )->run();
		}
		if ( ! empty( $settings['delay'] ) ) {
			( new DelayJs() )->run();
		}

		( new Images() )->run();

		if ( ! empty( $settings['youtube'] ) ) {
			( new YouTube() )->run();
		}
		if ( ! empty( $settings['fonts'] ) ) {
			( new Fonts() )->run();
		}
	}

	/**
	 * Можно ли оптимизировать текущий запрос.
	 */
	private function allowed(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		// Только для замеров в Query Monitor: по умолчанию авторизованные исключены.
		$force = defined( 'ALIPROFI_OPTIMIZER_FORCE' ) && ALIPROFI_OPTIMIZER_FORCE;
		if ( ! $force && is_user_logged_in() ) {
			return false;
		}
		if ( is_feed() || is_404() || is_robots() || is_trackback() ) {
			return false;
		}
		if ( function_exists( 'is_embed' ) && is_embed() ) {
			return false;
		}
		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}
		if ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) {
			return false;
		}

		foreach ( array( 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'et_fb', 'elementor-preview', 'vc_preview' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение флага.
				return false;
			}
		}

		return true;
	}

	/**
	 * Активация: создаём автозагружаемую опцию.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', 'yes' );
		}
	}

	/**
	 * Деактивация: чистим временные файлы шрифтов и задание автоочистки БД.
	 */
	public static function deactivate(): void {
		Fonts::purge_files();
		wp_clear_scheduled_hook( Database::CRON );
	}
}
