<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Scheduler;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Core\Privacy;
use ParsiShop\CRM\Providers\Contracts\SmsResult;
use ParsiShop\CRM\Providers\SmsProviderFactory;
use ParsiShop\CRM\Repositories\ConsentRepository;
use ParsiShop\CRM\Repositories\JobRepository;
use ParsiShop\CRM\Repositories\LogRepository;
use ParsiShop\CRM\Repositories\OptoutRepository;
use ParsiShop\CRM\Repositories\PlanRepository;
use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Support\RecipientHasher;
use ParsiShop\CRM\Support\TokenParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmsJobDispatcher {
	public static function init(): void {
		add_action( 'parsishop_crm_execute_sms_job', array( self::class, 'execute' ), 10, 1 );
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

		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'سفارش لغو یا مرجوع شده است.' ) );
			return;
		}

		if ( ! PlanMatcher::matches( $plan, $order ) ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'شرایط هدف‌گیری پلن در زمان ارسال برقرار نیست.' ) );
			return;
		}

		$phone = PhoneNormalizer::normalize( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'شماره موبایل سفارش معتبر نیست.' ) );
			return;
		}

		$hash_new    = RecipientHasher::hash( 'sms', $phone );
		$hash_legacy = PhoneNormalizer::hash( $phone );
		if ( ! hash_equals( (string) $job->customer_phone_hash, $hash_new ) && ! hash_equals( (string) $job->customer_phone_hash, $hash_legacy ) ) {
			JobRepository::update( $job_id, array( 'status' => 'cancelled', 'last_error' => 'شماره موبایل سفارش بعد از زمان‌بندی تغییر کرده است.' ) );
			return;
		}

		if ( OptoutRepository::is_opted_out( 'sms', $phone ) ) {
			self::skip_job( $job_id, $phone, $plan, $order, 'مخاطب دریافت پیامک بازاریابی را لغو کرده است.' );
			return;
		}

		if ( ! empty( $settings['require_marketing_consent'] ) && ! ConsentRepository::is_consented( 'sms', $phone ) ) {
			self::skip_job( $job_id, $phone, $plan, $order, 'رضایت صریح برای دریافت پیامک بازاریابی ثبت نشده است.' );
			return;
		}

		if ( (int) $plan->frequency_cap_count > 0 && (int) $plan->frequency_cap_days > 0 ) {
			$recent = JobRepository::count_recent_sent_to_phone( $hash_new, (int) $plan->frequency_cap_days, $hash_legacy );
			if ( $recent >= (int) $plan->frequency_cap_count ) {
				self::skip_job( $job_id, $phone, $plan, $order, 'سقف تعداد پیام مجاز برای این مخاطب تکمیل شده است.' );
				return;
			}
		}

		// Action Scheduler/WP-Cron can execute late when the site has little traffic.
		// Re-check at dispatch time so a delayed queue item never breaches quiet hours.
		$allowed_time = DateHelper::adjust_to_allowed_window( time() );
		if ( $allowed_time > time() + 2 ) {
			JobRepository::update(
				$job_id,
				array(
					'status'    => 'pending',
					'attempts'  => max( 0, (int) $job->attempts - 1 ),
					'last_error' => 'ارسال به‌دلیل خارج‌بودن از بازه زمانی مجاز به تعویق افتاد.',
				)
			);
			OrderEventListener::schedule_job( $job_id, $allowed_time );
			return;
		}

		$provider = SmsProviderFactory::make();
		try {
			if ( 'pattern' === $plan->send_type ) {
				$raw_values = json_decode( (string) $plan->message_template, true );
				if ( ! is_array( $raw_values ) || '' === (string) $plan->pattern_code ) {
					throw new \RuntimeException( 'تعریف پترن یا متغیرهای آن معتبر نیست.' );
				}
				$values = TokenParser::parse_array( $raw_values, $order, $phone );
				if ( empty( $values ) ) {
					throw new \RuntimeException( 'پترن هیچ متغیر متنی معتبری ندارد.' );
				}
				$result = $provider->send_pattern( $phone, (string) $plan->pattern_code, $values );
			} else {
				$message = TokenParser::parse_string( (string) $plan->message_template, $order, $phone );
				if ( ! empty( $settings['append_optout_text'] ) ) {
					$unsubscribe_url = Privacy::get_unsubscribe_url( 'sms', $phone );
					$footer          = trim( (string) ( $settings['optout_footer_text'] ?? '' ) );
					if ( '' !== $unsubscribe_url && ! str_contains( $message, $unsubscribe_url ) ) {
						$message .= "\n" . ( '' !== $footer ? $footer . ' | ' : '' ) . 'لغو آنلاین: ' . $unsubscribe_url;
					} elseif ( '' !== $footer && ! str_contains( $message, $footer ) ) {
						$message .= "\n" . $footer;
					}
				}
				$result = $provider->send_text( $phone, $message );
			}
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
			OrderEventListener::schedule_job( $job_id, DateHelper::adjust_to_allowed_window( time() + $delay ) );
			return;
		}

		JobRepository::update( $job_id, array( 'status' => 'failed_permanent', 'last_error' => $result->summary ) );
	}

	private static function skip_job( int $job_id, string $phone, object $plan, \WC_Order $order, string $reason ): void {
		$result = new SmsResult( success: false, status: 'skipped', error_code: 'POLICY_SKIP', summary: $reason );
		LogRepository::record( $phone, 'policy', $result, $job_id, (int) $plan->id, $order->get_id() );
		JobRepository::update( $job_id, array( 'status' => 'skipped', 'last_error' => $reason ) );
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
		$next_time = DateHelper::adjust_to_allowed_window( time() + $delay );
		$new_id    = JobRepository::insert_idempotent(
			(int) $job->order_id,
			(int) $plan->id,
			$next_step,
			(string) $job->customer_phone_hash,
			gmdate( 'Y-m-d H:i:s', $next_time )
		);

		if ( $new_id > 0 ) {
			OrderEventListener::schedule_job( $new_id, $next_time );
		}
	}
}
