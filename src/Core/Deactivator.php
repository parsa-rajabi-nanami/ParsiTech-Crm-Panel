<?php

declare(strict_types=1);

namespace ParsiReach\Core;

use ParsiReach\Scheduler\MaintenanceScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	public static function deactivate(): void {
		MaintenanceScheduler::unschedule_recurring_tasks();
		delete_option( 'parsireach_queue_rescheduled' );
	}
}
