<?php
/**
 * Plugin Name:       ParsiReach – WooCommerce Marketing Automation
 * Plugin URI:        https://parsishop.com
 * Description:       Consent-aware WooCommerce lifecycle SMS marketing with IPPanel Edge integration, automation rules and send logs.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            ParsiReach
 * Text Domain:       parsishop-crm
 * Domain Path:       /languages
 * WC requires at least: 7.9
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep legacy constants, option keys, tables and scheduled-action hooks so upgrades
// from CRM ParsiShop retain their existing configuration and queued records.
define( 'PARSISHOP_CRM_VERSION', '2.0.0' );
define( 'PARSISHOP_CRM_DB_VERSION', '1.1.0' );
define( 'PARSISHOP_CRM_FILE', __FILE__ );
define( 'PARSISHOP_CRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'PARSISHOP_CRM_URL', plugin_dir_url( __FILE__ ) );

define( 'PARSIREACH_VERSION', PARSISHOP_CRM_VERSION );

define( 'PARSIREACH_PATH', PARSISHOP_CRM_PATH );
define( 'PARSIREACH_URL', PARSISHOP_CRM_URL );

if ( file_exists( PARSISHOP_CRM_PATH . 'vendor/autoload.php' ) ) {
	require_once PARSISHOP_CRM_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix   = 'ParsiShop\\CRM\\';
			$base_dir = PARSISHOP_CRM_PATH . 'src/';
			$len      = strlen( $prefix );

			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}

			$relative_class = substr( $class, $len );
			$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \ParsiShop\CRM\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ParsiShop\CRM\Core\Deactivator::class, 'deactivate' ) );


// Declare HPOS compatibility as early as possible; WooCommerce fires this hook
// before the plugin's `plugins_loaded` bootstrap callback runs.
add_action(
    'before_woocommerce_init',
    static function (): void {
        $features_util = 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
        if ( class_exists( $features_util ) ) {
            $features_util::declare_compatibility( 'custom_order_tables', PARSISHOP_CRM_FILE, true );
        }
    }
);

add_action(
	'plugins_loaded',
	static function (): void {
		\ParsiShop\CRM\Bootstrap::instance()->init();
	},
	15
);
