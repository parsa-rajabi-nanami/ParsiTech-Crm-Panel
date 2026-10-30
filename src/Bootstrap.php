<?php
declare(strict_types=1);
namespace ParsiShop\CRM;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Core\SchemaManager;
use ParsiShop\CRM\Scheduler\MaintenanceScheduler;
use ParsiShop\CRM\Repositories\JobRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Bootstrap {
	private static ?self $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}
	private function __construct() {}

	public function init(): void {
		load_plugin_textdomain( 'parsishop-crm', false, dirname( plugin_basename( PARSISHOP_CRM_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		SchemaManager::maybe_upgrade();
		Capabilities::init();
		MaintenanceScheduler::init();

		// Activation hooks can run before WooCommerce is loaded. Rebuild the
		// persisted queue once on the first normal WooCommerce bootstrap.
		if ( false === get_option( 'parsishop_crm_queue_rescheduled_2_0', false ) ) {
			JobRepository::reschedule_pending_jobs();
			add_option( 'parsishop_crm_queue_rescheduled_2_0', time(), '', false );
		}

        \ParsiShop\CRM\Core\Privacy::init();
        
        \ParsiShop\CRM\Scheduler\OrderEventListener::init();
        \ParsiShop\CRM\Scheduler\SmsJobDispatcher::init();

		if ( is_admin() ) {
            \ParsiShop\CRM\Admin\MenuManager::init();
			( new SettingsPage() )->init();
            ( new \ParsiShop\CRM\Admin\PlansPage() )->init();
            ( new \ParsiShop\CRM\Admin\DashboardPage() )->init();
            ( new \ParsiShop\CRM\Admin\LogsPage() )->init();
		}
	}

	public function missing_wc_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) return;
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'افزونه ParsiReach برای اجرا به ووکامرس نیاز دارد.', 'parsishop-crm' )
		);
	}
}
