<?php
/**
 * Admin Settings View Template.
 *
 * @var array<string, mixed> $settings
 * @var string               $masked_api_key
 * @var bool                 $api_key_unreadable
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap parsireach-wrap" dir="rtl">
	<h1><?php esc_html_e( 'تنظیمات پیامک — ParsiReach', 'parsireach' ); ?></h1>
	<?php settings_errors( 'parsireach_settings' ); ?>
	<?php if ( $api_key_unreadable ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'کلید API ذخیره‌شده قابل رمزگشایی نیست. اگر کلیدهای امنیتی وردپرس تغییر کرده‌اند، API Key را دوباره وارد و تنظیمات را ذخیره کنید.', 'parsireach' ); ?></p></div><?php endif; ?>

	<form method="post" action="options.php" autocomplete="off">
		<?php settings_fields( 'parsireach_settings_group' ); ?>

		<div class="parsireach-card">
			<h2><?php esc_html_e( '۱. وضعیت سرویس و احراز هویت پنل فراز اس‌ام‌اس', 'parsireach' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'فعال‌سازی ارسال پیامک', 'parsireach' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsireach_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'فعال بودن ارسال خودکار پیامک', 'parsireach' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'حالت تست (Sandbox / Dry-run)', 'parsireach' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="parsireach_settings[sandbox_mode]" value="1" <?php checked( ! empty( $settings['sandbox_mode'] ) ); ?> />
							<?php esc_html_e( 'فقط ثبت در جدول گزارش‌ها بدون ارسال واقعی به وب‌سرویس پیامک (مناسب تست قوانین و زمان‌بندی)', 'parsireach' ); ?>
						</label>
					</td>
				</tr>


				<tr>
					<th scope="row">
						<label for="parsireach_new_api_key"><?php esc_html_e( 'کلید API فراز اس‌ام‌اس', 'parsireach' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							id="parsireach_new_api_key"
							name="parsireach_settings[new_api_key]"
							value=""
							class="regular-text ltr-input"
							placeholder="<?php echo '' !== $masked_api_key ? esc_attr( $masked_api_key . ' (ذخیره‌شده)' ) : esc_attr__( 'کلید API را وارد کنید...', 'parsireach' ); ?>"
							autocomplete="new-password"
						/>
						<?php if ( '' !== $masked_api_key ) : ?>
							<p class="description">
								<span class="parsireach-badge-ok"><?php esc_html_e( 'کلید فعلی به‌صورت رمزنگاری‌شده ذخیره شده است:', 'parsireach' ); ?> <code><?php echo esc_html( $masked_api_key ); ?></code></span>
								<?php esc_html_e( 'برای حفظ کلید فعلی، فیلد بالا را خالی بگذارید.', 'parsireach' ); ?>
							</p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'کلید API را از پنل فراز اس‌ام‌اس دریافت کنید؛ کلید را بدون پیشوند Bearer وارد و تنظیمات را ذخیره کنید.', 'parsireach' ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $masked_api_key ) : ?>
							<label><input type="checkbox" name="parsireach_settings[clear_api_key]" value="1" /> <?php esc_html_e( 'حذف کلید ذخیره‌شده', 'parsireach' ); ?></label>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="parsireach_sender_number"><?php esc_html_e( 'شماره خط فرستنده', 'parsireach' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="parsireach_sender_number"
							name="parsireach_settings[sender_number]"
							value="<?php echo esc_attr( (string) $settings['sender_number'] ); ?>"
							class="regular-text ltr-input"
							placeholder="50002178584000"
						/>
						<p class="description"><?php esc_html_e( 'شماره خط را دقیقاً مطابق مقدار line_number در پنل فراز وارد کنید؛ آن را به قالب E.164 یا +98 تبدیل نکنید. خط باید برای حساب شما مجاز باشد.', 'parsireach' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'بررسی اعتبار و اتصال', 'parsireach' ); ?></th>
					<td>
						<button type="button" id="parsireach-btn-test-connection" class="button button-secondary">
							<?php esc_html_e( 'تست اتصال و استعلام اعتبار', 'parsireach' ); ?>
						</button>
						<span id="parsireach-connection-result" class="parsireach-inline-status"></span>
					</td>
				</tr>
			</table>
		</div>

		<div class="parsireach-card">
			<h2><?php esc_html_e( '۲. نگهداری گزارش‌ها', 'parsireach' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="parsireach_retention_days"><?php esc_html_e( 'مدت نگهداری لاگ‌ها (روز)', 'parsireach' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="parsireach_retention_days"
							name="parsireach_settings[log_retention_days]"
							value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>"
							min="7"
							max="3650"
							class="small-text"
						/>
						<span><?php esc_html_e( 'روز (پاکسازی خودکار لاگ‌های قدیمی‌تر برای حفظ سرعت دیتابیس)', 'parsireach' ); ?></span>
					</td>
				</tr>

			</table>
		</div>

		<?php submit_button( __( 'ذخیره تنظیمات', 'parsireach' ) ); ?>
	</form>

	<div class="parsireach-card">
		<h2><?php esc_html_e( '۳. ارسال پیامک آزمایشی', 'parsireach' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="parsireach_test_phone"><?php esc_html_e( 'شماره موبایل گیرنده', 'parsireach' ); ?></label></th>
				<td>
					<input type="text" id="parsireach_test_phone" class="regular-text ltr-input" placeholder="09123456789" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="parsireach_test_pattern"><?php esc_html_e( 'کد پترن', 'parsireach' ); ?></label></th>
				<td>
					<input type="text" id="parsireach_test_pattern" class="regular-text ltr-input" placeholder="abcd1234" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'متغیرهای پترن (JSON)', 'parsireach' ); ?></th>
				<td>
					<textarea id="parsireach_test_pattern_values" class="large-text code" rows="3" placeholder="{&quot;name&quot;:&quot;نمونه&quot;}"></textarea>
					<p class="description"><?php esc_html_e( 'ارسال پیام تست به شماره واقعی هزینه دارد.', 'parsireach' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<button type="button" id="parsireach-btn-send-test-sms" class="button button-secondary">
						<?php esc_html_e( 'ارسال پیامک آزمایشی', 'parsireach' ); ?>
					</button>
					<span id="parsireach-test-sms-result" class="parsireach-inline-status"></span>
				</td>
			</tr>
		</table>
	</div>
</div>
