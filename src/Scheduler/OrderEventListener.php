<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Scheduler;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Repositories\JobRepository;
use ParsiShop\CRM\Repositories\PlanRepository;
use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Support\RecipientHasher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderEventListener {
	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'handle_status_change' ), 10, 4 );
	}

	/** @param mixed $order */
	public static function handle_status_change( $order_id, $old_status, $new_status, $order ): void {
		$order_id = absint( $order_id );
		if ( in_array( $new_status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			JobRepository::cancel_by_order( $order_id, 'وضعیت سفارش به لغوشده/مستردشده تغییر کرد' );
			return;
		}

		$settings = SettingsPage::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$plans = PlanRepository::get_active_plans( 'wc-' . sanitize_key( (string) $new_status ) );
		if ( empty( $plans ) ) {
			return;
		}

		$phone      = PhoneNormalizer::normalize( (string) $order->get_billing_phone() );
		$phone_hash = RecipientHasher::hash( 'sms', $phone );
		if ( '' === $phone || '' === $phone_hash ) {
			return;
		}

		foreach ( $plans as $plan ) {
			if ( ! PlanMatcher::matches( $plan, $order ) ) {
				continue;
			}

			$delay_seconds       = self::calc_delay( $plan );
			$scheduled_timestamp = DateHelper::adjust_to_allowed_window( time() + $delay_seconds );
			$scheduled_at_utc    = gmdate( 'Y-m-d H:i:s', $scheduled_timestamp );
			$job_id              = JobRepository::insert_idempotent( $order_id, (int) $plan->id, 1, $phone_hash, $scheduled_at_utc );

			if ( $job_id > 0 ) {
				self::schedule_job( $job_id, $scheduled_timestamp );
			}
		}
	}

	public static function schedule_job( int $job_id, int $timestamp ): void {
		$args = array( 'job_id' => $job_id );
		$when = max( time() + 1, $timestamp );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' ) ) {
				return;
			}
			as_schedule_single_action( $when, 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' );
			return;
		}
		if ( ! wp_next_scheduled( 'parsishop_crm_execute_sms_job', $args ) ) {
			wp_schedule_single_event( $when, 'parsishop_crm_execute_sms_job', $args );
		}
	}

	private static function calc_delay( object $plan ): int {
		$unit  = (string) $plan->initial_delay_unit;
		$value = max( 0, (int) $plan->initial_delay_val );
		$units = array(
			'hours'  => HOUR_IN_SECONDS,
			'days'   => DAY_IN_SECONDS,
			'weeks'  => WEEK_IN_SECONDS,
			'months' => 30 * DAY_IN_SECONDS,
		);
		return $value * ( $units[ $unit ] ?? 0 );
	}
}
