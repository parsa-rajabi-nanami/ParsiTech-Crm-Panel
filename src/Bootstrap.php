<?php

declare(strict_types=1);

namespace ParsiReach;

use ParsiReach\Admin\DashboardPage;
use ParsiReach\Admin\LogsPage;
use ParsiReach\Admin\MenuManager;
use ParsiReach\Admin\PlansPage;
use ParsiReach\Admin\SettingsPage;
use ParsiReach\Core\Capabilities;
use ParsiReach\Core\SchemaManager;
use ParsiReach\Repositories\JobRepository;
use ParsiReach\Scheduler\MaintenanceScheduler;
use ParsiReach\Scheduler\OrderEventListener;
use ParsiReach\Scheduler\SmsJobDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Bootstrap {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		load_plugin_textdomain( 'parsireach', false, dirname( plugin_basename( PARSIREACH_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		SchemaManager::maybe_upgrade();
		Capabilities::init();
		MaintenanceScheduler::init();

		if ( false === get_option( 'parsireach_queue_rescheduled', false ) ) {
			JobRepository::reschedule_pending_jobs();
			add_option( 'parsireach_queue_rescheduled', time(), '', false );
		}

		OrderEventListener::init();
		SmsJobDispatcher::init();

		if ( is_admin() ) {
			MenuManager::init();
			( new SettingsPage() )->init();
			( new PlansPage() )->init();
			( new DashboardPage() )->init();
			( new LogsPage() )->init();
		}
	}

	public function missing_wc_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'افزونه ParsiReach برای اجرا به ووکامرس نیاز دارد.', 'parsireach' )
		);
	}
}
