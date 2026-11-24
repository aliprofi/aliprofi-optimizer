<?php
/**
 * Удаление плагина: опция и локальный кэш шрифтов. Кэш OpenResty не затрагивается.
 *
 * @package Aliprofi_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-fonts.php';

$aliprofi_optimizer_cleanup = static function () {
	delete_option( 'aliprofi_optimizer' );
	\AliProfi\Optimizer\Fonts::purge_files();
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
