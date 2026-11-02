<?php

declare(strict_types=1);

namespace ParsiReach\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlanMatcher {
	public static function matches( object $plan, \WC_Order $order ): bool {
		// Do not send a delayed or repeated message after the order has moved
		// away from the status that originally triggered this automation.
		return (string) $plan->trigger_status === sanitize_key( $order->get_status() );
	}
}
