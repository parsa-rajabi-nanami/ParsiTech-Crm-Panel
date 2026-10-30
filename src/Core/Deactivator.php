<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Core;

use ParsiShop\CRM\Scheduler\MaintenanceScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	public static function deactivate(): void {
		MaintenanceScheduler::unschedule_recurring_tasks();
		delete_option( 'parsishop_crm_queue_rescheduled_2_0' );
	}
}
