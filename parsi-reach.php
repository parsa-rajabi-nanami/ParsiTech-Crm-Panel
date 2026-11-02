<?php
/**
 * Plugin Name:       ParsiReach – WooCommerce Order SMS Automation
 * Description:       Schedule IPPanel pattern messages from WooCommerce order status changes.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ParsiReach
 * License:           GPL-2.0-or-later
 * Text Domain:       parsireach
 * Domain Path:       /languages
 * WC requires at least: 7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PARSIREACH_VERSION', '1.0.0' );
define( 'PARSIREACH_DB_VERSION', '1.0.0' );
define( 'PARSIREACH_FILE', __FILE__ );
define( 'PARSIREACH_PATH', plugin_dir_path( __FILE__ ) );
define( 'PARSIREACH_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( PARSIREACH_PATH . 'vendor/autoload.php' ) ) {
	require_once PARSIREACH_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'ParsiReach\\';
			if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
				return;
			}

			$file = PARSIREACH_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \ParsiReach\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ParsiReach\Core\Deactivator::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function (): void {
		$features_util = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		if ( class_exists( $features_util ) ) {
			$features_util::declare_compatibility( 'custom_order_tables', PARSIREACH_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\ParsiReach\Bootstrap::instance()->init();
	},
	15
);
