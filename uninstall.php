<?php
/**
 * Удаление плагина: опция, собственный страничный кэш с drop-in и локальный кэш шрифтов.
 * Кэш OpenResty не затрагивается.
 *
 * @package Aliprofi_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-fonts.php';
require_once __DIR__ . '/includes/class-page-cache.php';

$aliprofi_optimizer_cleanup = static function () {
	\AliProfi\Optimizer\Page_Cache::uninstall();
	delete_option( 'aliprofi_optimizer' );
	\AliProfi\Optimizer\Fonts::purge_files();
	wp_clear_scheduled_hook( 'aliprofi_optimizer_preload' );
	delete_transient( 'aliprofi_optimizer_preload_queue' );
	delete_transient( 'aliprofi_optimizer_preload_lock' );
};

if ( is_multisite() ) {
	$aliprofi_optimizer_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $aliprofi_optimizer_sites as $aliprofi_optimizer_site ) {
		switch_to_blog( $aliprofi_optimizer_site );
		$aliprofi_optimizer_cleanup();
		restore_current_blog();
	}
} else {
	$aliprofi_optimizer_cleanup();
}
