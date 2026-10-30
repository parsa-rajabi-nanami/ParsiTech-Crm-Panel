<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Scheduler;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Providers\SmsProviderFactory;
use ParsiShop\CRM\Repositories\LogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MaintenanceScheduler {

	public const HOOK_DAILY_CLEANUP = 'parsishop_crm_daily_maintenance';
	public const CREDIT_TRANSIENT   = 'parsishop_crm_cached_credit_irr';
	public const LOW_CREDIT_OPTION  = 'parsishop_crm_low_credit_flag';

	public static function init(): void {
		add_action( self::HOOK_DAILY_CLEANUP, array( self::class, 'run_daily_maintenance' ) );
		add_action( 'admin_notices', array( self::class, 'render_low_credit_admin_notice' ) );
	}

	public static function schedule_recurring_tasks(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_DAILY_CLEANUP, array(), 'parsishop-crm' ) ) {
				as_schedule_recurring_action( time() + 300, 12 * HOUR_IN_SECONDS, self::HOOK_DAILY_CLEANUP, array(), 'parsishop-crm' );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_DAILY_CLEANUP ) ) {
			wp_schedule_event( time() + 300, 'twicedaily', self::HOOK_DAILY_CLEANUP );
		}
	}

	public static function unschedule_recurring_tasks(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_DAILY_CLEANUP, array(), 'parsishop-crm' );
		}
		wp_clear_scheduled_hook( self::HOOK_DAILY_CLEANUP );
	}

	public static function run_daily_maintenance(): void {
		$settings       = SettingsPage::get_settings();
		$retention_days = (int) ( $settings['log_retention_days'] ?? 90 );

		if ( $retention_days > 0 ) {
			LogRepository::purge_older_than( $retention_days );
		}

		if ( ! empty( $settings['enabled'] ) && empty( $settings['sandbox_mode'] ) ) {
			self::refresh_and_check_credit();
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
					'message'      => __( 'موجودی از حافظه موقت خوانده شد.', 'parsishop-crm' ),
				);
			}
		}

		$provider = SmsProviderFactory::make( $force_live );
		$res      = $provider->get_credit();

		if ( $res['success'] ) {
			$credit_irr   = (float) $res['credit_irr'];
			$credit_toman = round( $credit_irr / 10 );

			set_transient( self::CREDIT_TRANSIENT, $credit_irr, 30 * MINUTE_IN_SECONDS );

			$settings        = SettingsPage::get_settings();
			$threshold_toman = (float) ( $settings['low_credit_threshold'] ?? 50000 );

			if ( $credit_toman < $threshold_toman ) {
				self::trigger_low_credit_alert( $credit_toman );
			} else {
				delete_option( self::LOW_CREDIT_OPTION );
			}

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

	public static function trigger_low_credit_alert( ?float $current_toman = null ): void {
		$existing = get_option( self::LOW_CREDIT_OPTION, false );
		$now      = time();

		update_option(
			self::LOW_CREDIT_OPTION,
			array(
				'credit_toman' => $current_toman,
				'detected_at'  => $now,
				'emailed_at'   => is_array( $existing ) ? ( $existing['emailed_at'] ?? 0 ) : 0,
			),
			false
		);

		$last_emailed = is_array( $existing ) ? (int) ( $existing['emailed_at'] ?? 0 ) : 0;
		if ( ( $now - $last_emailed ) < DAY_IN_SECONDS ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$shop_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf( '[%s] هشدار کاهش اعتبار پنل پیامک ParsiReach', $shop_name );
		$amount    = null !== $current_toman ? number_format_i18n( $current_toman ) . ' تومان' : 'کمتر از حد مجاز';
		$body      = sprintf(
			"مدیر گرامی %s،\n\nاعتبار پنل پیامک فروشگاه شما به «%s» رسیده است.\nبرای جلوگیری از توقف ارسال پیامک‌های مارکتینگ، لطفاً نسبت به شارژ پنل پیامک خود اقدام نمایید.\n\nصفحه تنظیمات: %s",
			$shop_name,
			$amount,
			admin_url( 'admin.php?page=parsishop-crm-settings' )
		);

		$mail_sent = wp_mail( $admin_email, $subject, $body );
		if ( ! $mail_sent ) {
			return;
		}

		update_option(
			self::LOW_CREDIT_OPTION,
			array(
				'credit_toman' => $current_toman,
				'detected_at'  => $now,
				'emailed_at'   => $now,
			),
			false
		);
	}

	public static function render_low_credit_admin_notice(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			return;
		}

		$alert = get_option( self::LOW_CREDIT_OPTION, false );
		if ( empty( $alert ) || ! is_array( $alert ) ) {
			return;
		}

		$amount_text = isset( $alert['credit_toman'] )
			? sprintf( ' (موجودی فعلی: %s تومان)', number_format_i18n( (float) $alert['credit_toman'] ) )
			: '';

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'هشدار ParsiReach:', 'parsishop-crm' ),
			esc_html( 'اعتبار پنل پیامک شما رو به اتمام است' . $amount_text . '. لطفاً حساب خود را شارژ کنید.' ),
			esc_url( admin_url( 'admin.php?page=parsishop-crm-settings' ) ),
			esc_html__( 'بررسی وضعیت در تنظیمات', 'parsishop-crm' )
		);
	}
}
