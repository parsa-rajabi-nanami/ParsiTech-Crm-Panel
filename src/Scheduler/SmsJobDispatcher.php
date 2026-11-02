<?php

declare(strict_types=1);

namespace ParsiReach\Scheduler;

use ParsiReach\Admin\SettingsPage;
use ParsiReach\Providers\Contracts\SmsResult;
use ParsiReach\Providers\SmsProviderFactory;
use ParsiReach\Repositories\JobRepository;
use ParsiReach\Repositories\LogRepository;
use ParsiReach\Repositories\PlanRepository;
use ParsiReach\Support\PhoneNormalizer;
use ParsiReach\Support\PhoneHasher;
use ParsiReach\Support\TokenParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmsJobDispatcher {
	public static function init(): void {
		add_action( 'parsireach_execute_sms_job', array( self::class, 'execute' ), 10, 1 );
	}

	public static function execute( int $job_id ): void {
		if ( ! JobRepository::acquire_lock( $job_id ) ) {
			return;
		}

		$job = JobRepository::find( $job_id );
		if ( ! $job ) {
			return;
		}

		$settings = SettingsPage::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			JobRepository::update(
				$job_id,
				array(
					'status'   => 'paused',
					'attempts' => max( 0, (int) $job->attempts - 1 ),
					'last_error' => 'ارسال خودکار در تنظیمات غیرفعال است.',
				)
			);
			return;
		}

		$order = wc_get_order( (int) $job->order_id );
		$plan  = PlanRepository::find( (int) $job->plan_id );

		if ( ! $order || ! $plan ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'سفارش یا اتوماسیون یافت نشد.' ) );
			return;
		}
		if ( 'paused' === $plan->status ) {
			JobRepository::update( $job_id, array( 'status' => 'paused', 'attempts' => max( 0, (int) $job->attempts - 1 ), 'last_error' => 'اتوماسیون توسط مدیر متوقف شده است.' ) );
			return;
		}
		if ( 'active' !== $plan->status ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'اتوماسیون فعال نیست.' ) );
			return;
		}

		if ( ! PlanMatcher::matches( $plan, $order ) ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'وضعیت سفارش از زمان ایجاد اتوماسیون تغییر کرده است.' ) );
			return;
		}

		$phone = PhoneNormalizer::normalize( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'شماره موبایل سفارش معتبر نیست.' ) );
			return;
		}

		$hash = PhoneHasher::hash( $phone );
		if ( ! hash_equals( (string) $job->phone_hash, $hash ) ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'شماره موبایل سفارش بعد از زمان‌بندی تغییر کرده است.' ) );
			return;
		}

		$provider = SmsProviderFactory::make();
		try {
			$raw_values = json_decode( (string) $plan->pattern_values, true );
			if ( ! is_array( $raw_values ) || '' === (string) $plan->pattern_code ) {
				throw new \RuntimeException( 'تعریف پترن یا متغیرهای آن معتبر نیست.' );
			}
			$values = TokenParser::parse_array( $raw_values, $order );
			if ( empty( $values ) ) {
				throw new \RuntimeException( 'پترن هیچ متغیر متنی معتبری ندارد.' );
			}
			$result = $provider->send_pattern( $phone, (string) $plan->pattern_code, $values );
		} catch ( \Throwable $exception ) {
			$result = new SmsResult(
				success: false,
				status: 'failed',
				error_code: 'DISPATCH_EXCEPTION',
				summary: ( function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( $exception->getMessage() ), 0, 250, 'UTF-8' ) : substr( sanitize_text_field( $exception->getMessage() ), 0, 250 ) ),
				is_retryable: false
			);
		}

		LogRepository::record( $phone, $provider->get_provider_slug(), $result, $job_id, (int) $plan->id, $order->get_id() );

		if ( $result->success ) {
			JobRepository::update( $job_id, array( 'status' => 'completed', 'last_error' => null ) );
			self::schedule_next_step( $job, $plan );
			return;
		}

		if ( $result->is_retryable && (int) $job->attempts < 3 ) {
			$delay = min( 3600, 300 * ( 2 ** max( 0, ( (int) $job->attempts ) - 1 ) ) );
			JobRepository::update( $job_id, array( 'status' => 'failed_retry', 'last_error' => $result->summary ) );
			OrderEventListener::schedule_job( $job_id, time() + $delay );
			return;
		}

		JobRepository::update( $job_id, array( 'status' => 'failed_permanent', 'last_error' => $result->summary ) );
	}

	private static function schedule_next_step( object $job, object $plan ): void {
		if ( (int) $job->step_number >= (int) $plan->repeat_count ) {
			return;
		}

		$next_step = (int) $job->step_number + 1;
		$unit      = (string) $plan->repeat_interval_unit;
		$value     = max( 0, (int) $plan->repeat_interval_val );
		$units     = array(
			'hours'  => HOUR_IN_SECONDS,
			'days'   => DAY_IN_SECONDS,
			'weeks'  => WEEK_IN_SECONDS,
			'months' => 30 * DAY_IN_SECONDS,
		);
		$delay     = $value * ( $units[ $unit ] ?? 0 );
		$next_time = time() + $delay;
		$new_id    = JobRepository::insert_idempotent(
			(int) $job->order_id,
			(int) $plan->id,
			$next_step,
			(string) $job->phone_hash,
			gmdate( 'Y-m-d H:i:s', $next_time )
		);

		if ( $new_id > 0 ) {
			OrderEventListener::schedule_job( $new_id, $next_time );
		}
	}
}
