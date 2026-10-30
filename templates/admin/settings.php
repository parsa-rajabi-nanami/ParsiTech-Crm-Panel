<?php
/**
 * Admin Settings View Template.
 *
 * @var array<string, mixed> $settings
 * @var string               $masked_api_key
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap parsishop-crm-wrap" dir="rtl">
	<h1><?php esc_html_e( 'تنظیمات پیامک — ParsiReach (نسخه ۲.۰)', 'parsishop-crm' ); ?></h1>
	<?php settings_errors( 'parsishop_crm_settings' ); ?>

	<form method="post" action="options.php" autocomplete="off">
		<?php settings_fields( 'parsishop_crm_settings_group' ); ?>

		<div class="parsishop-crm-card">
			<h2><?php esc_html_e( '۱. وضعیت سرویس و احراز هویت پنل IPPanel Edge', 'parsishop-crm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'فعال‌سازی ارسال پیامک', 'parsishop-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'فعال بودن سیستم ارسال خودکار پیامک‌های CRM', 'parsishop-crm' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'حالت تست (Sandbox / Dry-run)', 'parsishop-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[sandbox_mode]" value="1" <?php checked( ! empty( $settings['sandbox_mode'] ) ); ?> />
							<?php esc_html_e( 'فقط ثبت در جدول گزارش‌ها بدون ارسال واقعی به وب‌سرویس پیامک (مناسب تست قوانین و زمان‌بندی)', 'parsishop-crm' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'حفاظت از رضایت بازاریابی', 'parsishop-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[require_marketing_consent]" value="1" <?php checked( ! empty( $settings['require_marketing_consent'] ) ); ?> />
							<?php esc_html_e( 'ارسال بازاریابی فقط برای شماره‌هایی انجام شود که رضایت صریح ثبت کرده‌اند (پیشنهادی).', 'parsishop-crm' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'در صورت فعال بودن، مشتریان قدیمی بدون سابقه رضایت تا زمان ثبت رضایت پیام تبلیغاتی دریافت نمی‌کنند.', 'parsishop-crm' ); ?></p>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[checkout_consent_field]" value="1" <?php checked( ! empty( $settings['checkout_consent_field'] ) ); ?> />
							<?php esc_html_e( 'نمایش چک‌باکس اختیاری رضایت دریافت پیامک بازاریابی در تسویه‌حساب کلاسیک ووکامرس.', 'parsishop-crm' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="parsishop_new_api_key"><?php esc_html_e( 'کلید دسترسی API (IPPanel Edge)', 'parsishop-crm' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="parsishop_new_api_key"
							name="parsishop_crm_settings[new_api_key]"
							value=""
							class="regular-text ltr-input"
							placeholder="<?php echo '' !== $masked_api_key ? esc_attr( $masked_api_key . ' (ذخیره‌شده)' ) : esc_attr__( 'کلید API را وارد کنید...', 'parsishop-crm' ); ?>"
							autocomplete="new-password"
						/>
						<?php if ( '' !== $masked_api_key ) : ?>
							<p class="description">
								<span class="parsishop-badge-ok"><?php esc_html_e( 'کلید فعلی به‌صورت رمزنگاری‌شده ذخیره شده است:', 'parsishop-crm' ); ?> <code><?php echo esc_html( $masked_api_key ); ?></code></span>
								<?php esc_html_e( 'برای حفظ کلید فعلی، فیلد بالا را خالی بگذارید.', 'parsishop-crm' ); ?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'کلید API را از پنل IPPanel در بخش برنامه‌نویسان ← کلیدهای دسترسی دریافت کنید؛ سپس تنظیمات را ذخیره کنید.', 'parsishop-crm' ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $masked_api_key ) : ?>
							<label><input type="checkbox" name="parsishop_crm_settings[clear_api_key]" value="1" /> <?php esc_html_e( 'حذف کلید ذخیره‌شده', 'parsishop-crm' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="parsishop_sender_number"><?php esc_html_e( 'شماره خط فرستنده', 'parsishop-crm' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="parsishop_sender_number"
							name="parsishop_crm_settings[sender_number]"
							value="<?php echo esc_attr( (string) $settings['sender_number'] ); ?>"
							class="regular-text ltr-input"
							placeholder="+983000505"
						/>
						<p class="description"><?php esc_html_e( 'شماره خط مجاز پنل را در قالب E.164 وارد کنید؛ نمونه خط ایران: ‎+983000505. این شماره باید برای کلید API شما مجاز باشد.', 'parsishop-crm' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'بررسی اعتبار و اتصال', 'parsishop-crm' ); ?></th>
					<td>
						<button type="button" id="parsishop-btn-test-connection" class="button button-secondary">
							<?php esc_html_e( 'تست اتصال و استعلام اعتبار', 'parsishop-crm' ); ?>
						</button>
						<span id="parsishop-connection-result" class="parsishop-inline-status"></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="parsishop-crm-card">
			<h2><?php esc_html_e( '۲. بازه زمانی مجاز ارسال و هشدارها', 'parsishop-crm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'محدودیت ساعت ارسال (Quiet Hours)', 'parsishop-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[enforce_time_window]" value="1" <?php checked( ! empty( $settings['enforce_time_window'] ) ); ?> />
							<?php esc_html_e( 'ارسال پیامک‌های مارکتینگ فقط در بازه ساعتی مشخص انجام شود (پیام‌های خارج از بازه به شروع بازه بعدی منتقل می‌شوند)', 'parsishop-crm' ); ?>
						</label>
						<div class="parsishop-time-range">
							<label>
								<?php esc_html_e( 'از ساعت:', 'parsishop-crm' ); ?>
								<input type="time" name="parsishop_crm_settings[window_start]" value="<?php echo esc_attr( (string) $settings['window_start'] ); ?>" />
							</label>
							<label>
								<?php esc_html_e( 'تا ساعت:', 'parsishop-crm' ); ?>
								<input type="time" name="parsishop_crm_settings[window_end]" value="<?php echo esc_attr( (string) $settings['window_end'] ); ?>" />
							</label>
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="parsishop_low_credit"><?php esc_html_e( 'حد آستانه هشدار کمبود اعتبار (تومان)', 'parsishop-crm' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="parsishop_low_credit"
							name="parsishop_crm_settings[low_credit_threshold]"
							value="<?php echo esc_attr( (string) $settings['low_credit_threshold'] ); ?>"
							min="0"
							step="1000"
							class="small-text"
						/>
						<span><?php esc_html_e( 'تومان (در صورت کاهش اعتبار به کمتر از این مبلغ، به مدیر ایمیل و اعلان نمایش داده می‌شود)', 'parsishop-crm' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="parsishop_retention_days"><?php esc_html_e( 'مدت نگهداری لاگ‌ها (روز)', 'parsishop-crm' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="parsishop_retention_days"
							name="parsishop_crm_settings[log_retention_days]"
							value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>"
							min="7"
							max="3650"
							class="small-text"
						/>
						<span><?php esc_html_e( 'روز (پاکسازی خودکار لاگ‌های قدیمی‌تر برای حفظ سرعت دیتابیس)', 'parsishop-crm' ); ?></span>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'عبارت لغو اشتراک در ارسال عادی', 'parsishop-crm' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsishop_crm_settings[append_optout_text]" value="1" <?php checked( ! empty( $settings['append_optout_text'] ) ); ?> />
							<?php esc_html_e( 'افزودن خودکار عبارت لغو اشتراک به انتهای پیامک‌های غیرپترن (تبلیغاتی):', 'parsishop-crm' ); ?>
						</label>
						<input
							type="text"
							name="parsishop_crm_settings[optout_footer_text]"
							value="<?php echo esc_attr( (string) $settings['optout_footer_text'] ); ?>"
							class="regular-text"
						/>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'ذخیره تنظیمات', 'parsishop-crm' ) ); ?>
	</form>

	<div class="parsishop-crm-card">
		<h2><?php esc_html_e( '۳. ارسال پیامک آزمایشی', 'parsishop-crm' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="parsishop_test_phone"><?php esc_html_e( 'شماره موبایل گیرنده', 'parsishop-crm' ); ?></label></th>
				<td>
					<input type="text" id="parsishop_test_phone" class="regular-text ltr-input" placeholder="09123456789" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="parsishop_test_pattern"><?php esc_html_e( 'کد پترن (اختیاری)', 'parsishop-crm' ); ?></label></th>
				<td>
					<input type="text" id="parsishop_test_pattern" class="regular-text ltr-input" placeholder="مثال: abcd1234 (اگر خالی باشد پیام متنی ساده ارسال می‌شود)" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'متغیر نمونه پترن (اختیاری)', 'parsishop-crm' ); ?></th>
				<td>
					<input type="text" id="parsishop_test_var_key" class="regular-text ltr-input" placeholder="نام متغیر (مثال: customer_name)" style="width:180px;" />
					<input type="text" id="parsishop_test_var_val" class="regular-text" placeholder="مقدار (مثال: علی)" style="width:180px;" />
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<button type="button" id="parsishop-btn-send-test-sms" class="button button-secondary">
						<?php esc_html_e( 'ارسال پیامک آزمایشی', 'parsishop-crm' ); ?>
					</button>
					<span id="parsishop-test-sms-result" class="parsishop-inline-status"></span>
				</td>
			</tr>
		</table>
	</div>
</div>
