<?php

declare(strict_types=1);

namespace ParsiReach\Admin;

use DateTimeImmutable;
use DateTimeZone;
use ParsiReach\Core\Capabilities;
use ParsiReach\Admin\SettingsPage;
use ParsiReach\Scheduler\MaintenanceScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardPage {
	public function init(): void {}

	public static function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsireach' ) );
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'parsireach_logs';
		$jobs_table = $wpdb->prefix . 'parsireach_jobs';

		$local_now   = new DateTimeImmutable( 'now', wp_timezone() );
		$today_local = $local_now->setTime( 0, 0, 0 );
		$week_local  = $local_now->modify( '-6 days' )->setTime( 0, 0, 0 );
		$utc         = new DateTimeZone( 'UTC' );
		$today       = $today_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$week        = $week_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$sent_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'sent' AND created_at >= %s", $today ) );
		$sent_week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'sent' AND created_at >= %s", $week ) );
		$failed_week = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'failed' AND created_at >= %s", $week ) );
		$pending_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status IN ('pending', 'failed_retry')" );
		$paused_jobs  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'paused'" );

		$settings = SettingsPage::get_settings();
		if ( ! empty( $settings['sandbox_mode'] ) ) {
			$credit_text = __( 'حالت شبیه‌سازی فعال', 'parsireach' );
		} elseif ( ! empty( $settings['enabled'] ) ) {
			$credit_info = MaintenanceScheduler::refresh_and_check_credit();
			$credit_text = $credit_info['success'] ? number_format_i18n( $credit_info['credit_toman'] ) . ' تومان' : __( 'استعلام ناموفق', 'parsireach' );
		} else {
			$credit_text = __( 'سرویس غیرفعال است', 'parsireach' );
		}
		?>
		<div class="wrap parsireach-wrap" dir="rtl">
			<h1><?php esc_html_e( 'داشبورد ParsiReach', 'parsireach' ); ?></h1>
			<p><?php esc_html_e( 'نمای کلی وضعیت اتوماسیون‌های بازاریابی فروشگاه ووکامرس.', 'parsireach' ); ?></p>
			<div class="parsireach-stats-grid">
				<?php self::stat_card( __( 'اعتبار پنل پیامک', 'parsireach' ), $credit_text ); ?>
				<?php self::stat_card( __( 'درخواست ارسال ثبت‌شده امروز', 'parsireach' ), number_format_i18n( $sent_today ) ); ?>
				<?php self::stat_card( __( 'درخواست ارسال ثبت‌شده در ۷ روز اخیر', 'parsireach' ), number_format_i18n( $sent_week ) ); ?>
				<?php self::stat_card( __( 'ارسال ناموفق ۷ روز اخیر', 'parsireach' ), number_format_i18n( $failed_week ) ); ?>
				<?php self::stat_card( __( 'کارهای در صف', 'parsireach' ), number_format_i18n( $pending_jobs ) ); ?>
				<?php self::stat_card( __( 'کارهای متوقف‌شده', 'parsireach' ), number_format_i18n( $paused_jobs ) ); ?>
			</div>
			<div class="parsireach-card">
				<h2><?php esc_html_e( 'شروع سریع', 'parsireach' ); ?></h2>
				<ol>
					<li><?php echo wp_kses_post( sprintf( __( 'اطلاعات API و شماره خط را در <a href="%s">تنظیمات پیامک</a> وارد کنید.', 'parsireach' ), esc_url( admin_url( 'admin.php?page=parsireach-settings' ) ) ) ); ?></li>
					<li><?php esc_html_e( 'در حالت Sandbox رویداد و گزارش‌ها را بررسی کنید؛ برای ارسال واقعی آن را خاموش کنید.', 'parsireach' ); ?></li>
					<li><?php echo wp_kses_post( sprintf( __( 'رویداد، تأخیر، پترن و تکرار را در بخش <a href="%s">اتوماسیون‌ها</a> تنظیم کنید.', 'parsireach' ), esc_url( admin_url( 'admin.php?page=parsireach-plans' ) ) ) ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	private static function stat_card( string $label, string $value ): void {
		?>
		<div class="parsireach-stat-card"><div class="parsireach-stat-label"><?php echo esc_html( $label ); ?></div><div class="parsireach-stat-value"><?php echo esc_html( $value ); ?></div></div>
		<?php
	}
}
