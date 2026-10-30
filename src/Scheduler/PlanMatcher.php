<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlanMatcher {
	public static function matches( object $plan, \WC_Order $order ): bool {
		$rules = json_decode( (string) $plan->targeting_rules, true );
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		// Do not send a delayed or repeated message after the order has moved
		// away from the status that originally triggered this automation.
		$trigger_statuses = json_decode( (string) ( $plan->trigger_statuses ?? '[]' ), true );
		if ( is_array( $trigger_statuses ) && ! empty( $trigger_statuses ) ) {
			$current_status = 'wc-' . sanitize_key( $order->get_status() );
			if ( ! in_array( $current_status, $trigger_statuses, true ) ) {
				return false;
			}
		}

		$total = (float) $order->get_total();
		if ( isset( $rules['min_total'] ) && $total < (float) $rules['min_total'] ) {
			return false;
		}
		if ( isset( $rules['max_total'] ) && $total > (float) $rules['max_total'] ) {
			return false;
		}

		return true;
	}
}
