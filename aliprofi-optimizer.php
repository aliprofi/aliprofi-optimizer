<?php
/**
 * Plugin Name: Aliprofi Optimizer
 * Plugin URI:  https://aliprofi.ru
 * Description: Лёгкая оптимизация фронтенда (JS, изображения, YouTube, шрифты), собственный страничный кэш, очистка базы данных, сброс и прогрев FastCGI-кэша OpenResty.
 * Version:     1.5.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:      Али Профи
 * Author URI:  https://aliprofi.ru
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

define( 'ALIPROFI_OPTIMIZER_FILE', __FILE__ );
define( 'ALIPROFI_OPTIMIZER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALIPROFI_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );
define( 'ALIPROFI_OPTIMIZER_VERSION', '1.5.0' );

require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-settings.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-cache.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-database.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-admin.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-scripts.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-delay-js.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-images.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-youtube.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-fonts.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-preload.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-page-cache.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-pagespeed.php';
require_once ALIPROFI_OPTIMIZER_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Plugin::boot();
	}
);
