<?php

declare(strict_types=1);

namespace ParsiReach\Scheduler;

use ParsiReach\Admin\SettingsPage;
use ParsiReach\Providers\SmsProviderFactory;
use ParsiReach\Repositories\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MaintenanceScheduler {

	public const HOOK_DAILY_CLEANUP = 'parsireach_daily_maintenance';
	public const CREDIT_TRANSIENT   = 'parsireach_cached_credit_irr';

	public static function init(): void {
		add_action( self::HOOK_DAILY_CLEANUP, array( self::class, 'run_daily_maintenance' ) );
	}

	public static function schedule_recurring_tasks(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_DAILY_CLEANUP, array(), 'parsireach' ) ) {
				as_schedule_recurring_action( time() + 300, 12 * HOUR_IN_SECONDS, self::HOOK_DAILY_CLEANUP, array(), 'parsireach' );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_DAILY_CLEANUP ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::HOOK_DAILY_CLEANUP );
		}
	}

	public static function unschedule_recurring_tasks(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_DAILY_CLEANUP, array(), 'parsireach' );
		}
		wp_clear_scheduled_hook( self::HOOK_DAILY_CLEANUP );
	}

	public static function run_daily_maintenance(): void {
		$settings       = SettingsPage::get_settings();
		$retention_days = (int) ( $settings['log_retention_days'] ?? 90 );

		if ( $retention_days > 0 ) {
			LogRepository::purge_older_than( $retention_days );
		}

	}

	/**
	 * @return array{success: bool, credit_irr: float, credit_toman: float, message: string}
	 */
	public static function refresh_and_check_credit( bool $force_live = false ): array {
		if ( ! $force_live ) {
			$cached_credit = get_transient( self::CREDIT_TRANSIENT );
			if ( false !== $cached_credit && is_numeric( $cached_credit ) ) {
				$credit_irr   = (float) $cached_credit;
				$credit_toman = round( $credit_irr / 10 );
				return array(
					'success'      => true,
					'credit_irr'   => $credit_irr,
					'credit_toman' => $credit_toman,
					'message'      => __( 'موجودی از حافظه موقت خوانده شد.', 'parsireach' ),
				);
			}
		}

		$provider = SmsProviderFactory::make( $force_live );
		$res      = $provider->get_credit();

		if ( $res['success'] ) {
			$credit_irr   = (float) $res['credit_irr'];
			$credit_toman = round( $credit_irr / 10 );

			set_transient( self::CREDIT_TRANSIENT, $credit_irr, 30 * MINUTE_IN_SECONDS );

			return array(
				'success'      => true,
				'credit_irr'   => $credit_irr,
				'credit_toman' => $credit_toman,
				'message'      => $res['message'],
			);
		}

		return array(
			'success'      => false,
			'credit_irr'   => 0.0,
			'credit_toman' => 0.0,
			'message'      => $res['message'],
		);
	}

}
