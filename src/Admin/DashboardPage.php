<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Admin;

use DateTimeImmutable;
use DateTimeZone;
use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Scheduler\MaintenanceScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardPage {
	public function init(): void {}

	public static function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsishop-crm' ) );
		}

		global $wpdb;
		$logs_table = $wpdb->prefix . 'parsishop_crm_logs';
		$jobs_table = $wpdb->prefix . 'parsishop_crm_jobs';

		$local_now   = new DateTimeImmutable( 'now', wp_timezone() );
		$today_local = $local_now->setTime( 0, 0, 0 );
		$week_local  = $local_now->modify( '-6 days' )->setTime( 0, 0, 0 );
		$utc         = new DateTimeZone( 'UTC' );
		$today       = $today_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$week        = $week_local->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$sent_today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'sent' AND created_at >= %s", $today ) );
		$sent_week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'sent' AND created_at >= %s", $week ) );
		$failed_week = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'failed' AND created_at >= %s", $week ) );
		$skipped_week = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE status = 'skipped' AND created_at >= %s", $week ) );
		$pending_jobs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status IN ('pending', 'failed_retry')" );
		$paused_jobs  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'paused'" );

		$settings = SettingsPage::get_settings();
		if ( ! empty( $settings['sandbox_mode'] ) ) {
			$credit_text = __( 'حالت شبیه‌سازی فعال', 'parsishop-crm' );
		} elseif ( ! empty( $settings['enabled'] ) ) {
			$credit_info = MaintenanceScheduler::refresh_and_check_credit();
			$credit_text = $credit_info['success'] ? number_format_i18n( $credit_info['credit_toman'] ) . ' تومان' : __( 'استعلام ناموفق', 'parsishop-crm' );
		} else {
			$credit_text = __( 'سرویس غیرفعال است', 'parsishop-crm' );
		}
		?>
		<div class="wrap parsishop-crm-wrap" dir="rtl">
			<h1><?php esc_html_e( 'داشبورد ParsiReach', 'parsishop-crm' ); ?></h1>
			<p><?php esc_html_e( 'نمای کلی وضعیت اتوماسیون‌های بازاریابی فروشگاه ووکامرس.', 'parsishop-crm' ); ?></p>
			<div class="parsishop-crm-stats-grid">
				<?php self::stat_card( __( 'اعتبار پنل پیامک', 'parsishop-crm' ), $credit_text ); ?>
				<?php self::stat_card( __( 'درخواست ارسال ثبت‌شده امروز', 'parsishop-crm' ), number_format_i18n( $sent_today ) ); ?>
				<?php self::stat_card( __( 'درخواست ارسال ثبت‌شده در ۷ روز اخیر', 'parsishop-crm' ), number_format_i18n( $sent_week ) ); ?>
				<?php self::stat_card( __( 'ارسال ناموفق ۷ روز اخیر', 'parsishop-crm' ), number_format_i18n( $failed_week ) ); ?>
				<?php self::stat_card( __( 'ارسال‌های ردشده بر اساس رضایت/محدودیت', 'parsishop-crm' ), number_format_i18n( $skipped_week ) ); ?>
				<?php self::stat_card( __( 'کارهای در صف', 'parsishop-crm' ), number_format_i18n( $pending_jobs ) ); ?>
				<?php self::stat_card( __( 'کارهای متوقف‌شده', 'parsishop-crm' ), number_format_i18n( $paused_jobs ) ); ?>
			</div>
			<div class="parsishop-crm-card">
				<h2><?php esc_html_e( 'شروع سریع', 'parsishop-crm' ); ?></h2>
				<ol>
					<li><?php echo wp_kses_post( sprintf( __( 'اطلاعات API و شماره خط را در <a href="%s">تنظیمات پیامک</a> وارد کنید.', 'parsishop-crm' ), esc_url( admin_url( 'admin.php?page=parsishop-crm-settings' ) ) ) ); ?></li>
					<li><?php esc_html_e( 'ابتدا حالت شبیه‌سازی را فعال نگه دارید و اتصال و اتوماسیون را بررسی کنید.', 'parsishop-crm' ); ?></li>
					<li><?php esc_html_e( 'فیلد رضایت بازاریابی را در تسویه‌حساب فعال کنید؛ بدون رضایت صریح، ارسال بازاریابی انجام نمی‌شود.', 'parsishop-crm' ); ?></li>
					<li><?php echo wp_kses_post( sprintf( __( 'قواعد ارسال را در بخش <a href="%s">اتوماسیون‌ها</a> بسازید.', 'parsishop-crm' ), esc_url( admin_url( 'admin.php?page=parsishop-crm-plans' ) ) ) ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	private static function stat_card( string $label, string $value ): void {
		?>
		<div class="parsishop-crm-stat-card"><div class="parsishop-crm-stat-label"><?php echo esc_html( $label ); ?></div><div class="parsishop-crm-stat-value"><?php echo esc_html( $value ); ?></div></div>
		<?php
	}
}
