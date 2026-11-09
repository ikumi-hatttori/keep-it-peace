<?php
/**
 * Plugin Name:       Keep It Peace
 * Plugin URI:        https://keep-it-peace.com
 * Description:       Monitors custom themes, server-side programs outside WordPress, the site's public surface and the PHP runtime, and emails you a summary.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            mackarel Inc.
 * Author URI:        https://mackarel.jp
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keep-it-peace
 * Domain Path:       /languages
 *
 * @package KeepItPeace
 */

defined( 'ABSPATH' ) || exit;

define( 'KEEPITPEACE_VERSION', '1.0.0' );
define( 'KEEPITPEACE_API_VERSION', '1.0.0' );
define( 'KEEPITPEACE_EXPORT_SCHEMA_VERSION', '1.0.0' );
define( 'KEEPITPEACE_RULESET_SCHEMA_VERSION', 1 );
define( 'KEEPITPEACE_DB_VERSION', 1 );
define( 'KEEPITPEACE_FILE', __FILE__ );
define( 'KEEPITPEACE_DIR', plugin_dir_path( __FILE__ ) );
define( 'KEEPITPEACE_URL', plugin_dir_url( __FILE__ ) );

require_once KEEPITPEACE_DIR . 'src/Autoloader.php';
\KeepItPeace\Autoloader::register( 'KeepItPeace\\', KEEPITPEACE_DIR . 'src/' );

// Intervals come from the scan frequency options (daily/weekly by default).
add_filter( 'cron_schedules', array( '\KeepItPeace\Scan\Scheduler', 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

register_activation_hook( __FILE__, array( '\KeepItPeace\Install\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\KeepItPeace\Install\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\KeepItPeace\Plugin', 'boot' ) );
