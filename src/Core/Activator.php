<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Core;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Scheduler\MaintenanceScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
			deactivate_plugins( plugin_basename( PARSISHOP_CRM_FILE ) );
			wp_die(
				esc_html__( 'افزونه ParsiReach نیازمند PHP نسخه 8.1 یا بالاتر است.', 'parsishop-crm' )
			);
		}

		SchemaManager::install();

		if ( false === get_option( SettingsPage::OPTION_NAME, false ) ) {
			update_option(
				SettingsPage::OPTION_NAME,
				SettingsPage::get_default_settings(),
				false
			);
		}

		MaintenanceScheduler::schedule_recurring_tasks();
		if ( class_exists( 'WooCommerce' ) ) {
			\ParsiShop\CRM\Repositories\JobRepository::reschedule_pending_jobs();
		}
	}
}
