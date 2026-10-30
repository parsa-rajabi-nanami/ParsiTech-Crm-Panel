<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Admin;

use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Repositories\PlanRepository;
use ParsiShop\CRM\Support\DateHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlansPage {
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'parsishop-crm',
			__( 'اتوماسیون‌های بازاریابی', 'parsishop-crm' ),
			__( 'اتوماسیون‌ها', 'parsishop-crm' ),
			Capabilities::MANAGE_CAP,
			'parsishop-crm-plans',
			array( $this, 'render_page' )
		);
	}

	private function handle_actions(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['parsishop_plan_action'] ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'parsishop-crm' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'parsishop_plan_action' );

		$action = sanitize_key( wp_unslash( $_POST['parsishop_plan_action'] ) );
		$id     = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

		if ( 'create' === $action ) {
			$this->create_plan();
			return;
		}

		if ( $id < 1 ) {
			return;
		}

		if ( 'toggle' === $action ) {
			$plan = PlanRepository::find( $id );
			if ( $plan && PlanRepository::set_status( $id, 'active' === $plan->status ? 'paused' : 'active' ) ) {
				$this->redirect_after_action( 'updated' );
			}
		} elseif ( 'delete' === $action && PlanRepository::delete( $id ) ) {
			$this->redirect_after_action( 'deleted' );
		}
	}

	private function create_plan(): void {
		$name = isset( $_POST['plan_name'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_name'] ) ) : '';
		if ( '' === $name ) {
			add_settings_error( 'parsishop_crm_plans', 'plan_name_required', __( 'نام اتوماسیون الزامی است.', 'parsishop-crm' ), 'error' );
			return;
		}

		$trigger = isset( $_POST['trigger_status'] ) ? sanitize_key( wp_unslash( $_POST['trigger_status'] ) ) : 'completed';
		$allowed_triggers = array( 'processing', 'completed', 'on-hold' );
		if ( ! in_array( $trigger, $allowed_triggers, true ) ) {
			$trigger = 'completed';
		}

		$send_type = isset( $_POST['send_type'] ) ? sanitize_key( wp_unslash( $_POST['send_type'] ) ) : 'text';
		if ( ! in_array( $send_type, array( 'text', 'pattern' ), true ) ) {
			$send_type = 'text';
		}

		$pattern_code = isset( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
		if ( 'pattern' === $send_type && '' === $pattern_code ) {
			add_settings_error( 'parsishop_crm_plans', 'pattern_required', __( 'برای ارسال پترن، کد پترن الزامی است.', 'parsishop-crm' ), 'error' );
			return;
		}

		$template = isset( $_POST['message_template'] ) ? trim( wp_unslash( $_POST['message_template'] ) ) : '';
		if ( 'pattern' === $send_type ) {
			$values = json_decode( $template, true );
			if ( ! str_starts_with( ltrim( $template ), '{' ) || ! is_array( $values ) || empty( $values ) ) {
				add_settings_error( 'parsishop_crm_plans', 'pattern_values_invalid', __( 'متغیرهای پترن باید یک JSON object معتبر باشند.', 'parsishop-crm' ), 'error' );
				return;
			}
			$template = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );
		} elseif ( '' === $template ) {
			add_settings_error( 'parsishop_crm_plans', 'message_required', __( 'متن پیام الزامی است.', 'parsishop-crm' ), 'error' );
			return;
		} else {
			$template = sanitize_textarea_field( $template );
		}

		$delay_value = isset( $_POST['delay_val'] ) ? min( 3650, max( 0, absint( $_POST['delay_val'] ) ) ) : 0;
		$delay_unit  = isset( $_POST['delay_unit'] ) ? sanitize_key( wp_unslash( $_POST['delay_unit'] ) ) : 'days';
		$units       = array( 'hours', 'days', 'weeks', 'months' );
		if ( ! in_array( $delay_unit, $units, true ) ) {
			$delay_unit = 'days';
		}

		$repeat_count = isset( $_POST['repeat_count'] ) ? min( 10, max( 1, absint( $_POST['repeat_count'] ) ) ) : 1;
		$repeat_value = isset( $_POST['repeat_interval_val'] ) ? min( 3650, max( 0, absint( $_POST['repeat_interval_val'] ) ) ) : 0;
		$repeat_unit  = isset( $_POST['repeat_interval_unit'] ) ? sanitize_key( wp_unslash( $_POST['repeat_interval_unit'] ) ) : 'days';
		if ( ! in_array( $repeat_unit, $units, true ) ) {
			$repeat_unit = 'days';
		}
		if ( $repeat_count > 1 && $repeat_value < 1 ) {
			add_settings_error( 'parsishop_crm_plans', 'repeat_interval_required', __( 'برای پیام‌های تکراری، فاصله ارسال باید حداقل یک واحد باشد.', 'parsishop-crm' ), 'error' );
			return;
		}

		$min_total = isset( $_POST['min_total'] ) && '' !== $_POST['min_total'] ? max( 0, (float) wp_unslash( $_POST['min_total'] ) ) : null;
		$max_total = isset( $_POST['max_total'] ) && '' !== $_POST['max_total'] ? max( 0, (float) wp_unslash( $_POST['max_total'] ) ) : null;
		if ( null !== $min_total && null !== $max_total && $min_total > $max_total ) {
			add_settings_error( 'parsishop_crm_plans', 'invalid_total_range', __( 'حداقل مبلغ سفارش نباید از حداکثر مبلغ بیشتر باشد.', 'parsishop-crm' ), 'error' );
			return;
		}

		$rules = array();
		if ( null !== $min_total ) {
			$rules['min_total'] = $min_total;
		}
		if ( null !== $max_total ) {
			$rules['max_total'] = $max_total;
		}
		$cap_count = isset( $_POST['frequency_cap_count'] ) ? min( 20, absint( $_POST['frequency_cap_count'] ) ) : 0;
		$cap_days  = isset( $_POST['frequency_cap_days'] ) ? min( 365, absint( $_POST['frequency_cap_days'] ) ) : 0;
		if ( $cap_count < 1 || $cap_days < 1 ) {
			$cap_count = 0;
			$cap_days  = 0;
		}

		$now = DateHelper::now_utc();
		$data = array(
			'name'                 => $name,
			'status'               => 'active',
			'trigger_statuses'     => wp_json_encode( array( 'wc-' . $trigger ) ),
			'delay_base'           => 'status_change',
			'initial_delay_val'    => $delay_value,
			'initial_delay_unit'   => $delay_unit,
			'repeat_count'         => $repeat_count,
			'repeat_interval_val'  => $repeat_value,
			'repeat_interval_unit' => $repeat_unit,
			'send_type'            => $send_type,
			'pattern_code'         => 'pattern' === $send_type ? $pattern_code : null,
			'message_template'     => $template,
			'targeting_rules'      => wp_json_encode( $rules ),
			'stop_rules'           => wp_json_encode( array() ),
			'frequency_cap_count'  => $cap_count,
			'frequency_cap_days'   => $cap_days,
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		if ( PlanRepository::insert( $data ) > 0 ) {
			$this->redirect_after_action( 'created' );
		} else {
			add_settings_error( 'parsishop_crm_plans', 'plan_create_failed', __( 'ذخیره اتوماسیون در پایگاه داده ناموفق بود.', 'parsishop-crm' ), 'error' );
		}
	}


	private function redirect_after_action( string $action ): void {
		$url = add_query_arg(
			array( 'page' => 'parsishop-crm-plans', 'pr_action' => sanitize_key( $action ) ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsishop-crm' ) );
		}

		$this->handle_actions();
		$plans = PlanRepository::get_all_plans();
		?>
		<div class="wrap parsishop-crm-wrap" dir="rtl">
			<h1><?php esc_html_e( 'اتوماسیون بازاریابی پیامکی', 'parsishop-crm' ); ?></h1>
			<p><?php esc_html_e( 'برای اجرای اتوماسیون، ابتدا سرویس پیامک را در تنظیمات فعال کنید و رضایت بازاریابی مخاطب را ثبت نمایید.', 'parsishop-crm' ); ?></p>
			<?php settings_errors( 'parsishop_crm_plans' ); ?>
			<?php if ( isset( $_GET['pr_action'] ) ) : $action_notice = sanitize_key( wp_unslash( $_GET['pr_action'] ) ); ?>
				<?php if ( 'created' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'اتوماسیون با موفقیت ایجاد شد.', 'parsishop-crm' ); ?></p></div><?php endif; ?>
				<?php if ( 'updated' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'وضعیت اتوماسیون به‌روزرسانی شد.', 'parsishop-crm' ); ?></p></div><?php endif; ?>
				<?php if ( 'deleted' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'اتوماسیون حذف شد.', 'parsishop-crm' ); ?></p></div><?php endif; ?>
			<?php endif; ?>

			<div class="parsishop-crm-card">
				<h2><?php esc_html_e( 'ایجاد اتوماسیون جدید', 'parsishop-crm' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'parsishop_plan_action' ); ?>
					<input type="hidden" name="parsishop_plan_action" value="create">
					<table class="form-table" role="presentation">
						<tr><th><label for="pr-plan-name"><?php esc_html_e( 'نام اتوماسیون', 'parsishop-crm' ); ?></label></th><td><input id="pr-plan-name" type="text" name="plan_name" class="regular-text" required maxlength="191" placeholder="مثلاً بازگشت مشتری پس از خرید"></td></tr>
						<tr><th><label for="pr-trigger"><?php esc_html_e( 'رویداد شروع', 'parsishop-crm' ); ?></label></th><td><select id="pr-trigger" name="trigger_status"><option value="processing"><?php esc_html_e( 'سفارش در حال انجام', 'parsishop-crm' ); ?></option><option value="completed" selected><?php esc_html_e( 'سفارش تکمیل‌شده', 'parsishop-crm' ); ?></option><option value="on-hold"><?php esc_html_e( 'سفارش در انتظار بررسی', 'parsishop-crm' ); ?></option></select></td></tr>
						<tr><th><label for="pr-delay"><?php esc_html_e( 'تأخیر اولین پیام', 'parsishop-crm' ); ?></label></th><td><input id="pr-delay" type="number" name="delay_val" class="small-text" min="0" max="3650" value="0"><select name="delay_unit"><option value="hours"><?php esc_html_e( 'ساعت', 'parsishop-crm' ); ?></option><option value="days" selected><?php esc_html_e( 'روز', 'parsishop-crm' ); ?></option><option value="weeks"><?php esc_html_e( 'هفته', 'parsishop-crm' ); ?></option><option value="months"><?php esc_html_e( 'ماه (۳۰ روز)', 'parsishop-crm' ); ?></option></select></td></tr>
						<tr><th><label for="pr-type"><?php esc_html_e( 'نوع ارسال', 'parsishop-crm' ); ?></label></th><td><select id="pr-type" name="send_type"><option value="text"><?php esc_html_e( 'پیامک متنی', 'parsishop-crm' ); ?></option><option value="pattern"><?php esc_html_e( 'پترن تأییدشده IPPanel', 'parsishop-crm' ); ?></option></select></td></tr>
						<tr><th><label for="pr-pattern"><?php esc_html_e( 'کد پترن', 'parsishop-crm' ); ?></label></th><td><input id="pr-pattern" type="text" name="pattern_code" class="regular-text" dir="ltr" placeholder="Pattern code"><p class="description"><?php esc_html_e( 'فقط در حالت پترن الزامی است.', 'parsishop-crm' ); ?></p></td></tr>
						<tr><th><label for="pr-template"><?php esc_html_e( 'متن پیام یا JSON متغیرهای پترن', 'parsishop-crm' ); ?></label></th><td><textarea id="pr-template" name="message_template" rows="5" class="large-text code" required placeholder="سلام {customer_name}، سفارش {order_number} شما ثبت شد."></textarea><p class="description"><?php esc_html_e( 'توکن‌ها: {customer_name}، {customer_last_name}، {order_number}، {order_date}، {order_total}، {product_name}، {shop_name} و {unsubscribe_url}. در پترن، JSON مانند {"name":"{customer_name}","order":"{order_number}"} وارد کنید. متن لغو اشتراک را باید در خود پترن تأییدشده یا متغیر مربوط به لینک لغو بگنجانید؛ پترن پس از تأیید قابل الحاق متن نیست.', 'parsishop-crm' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'شرط مبلغ سفارش', 'parsishop-crm' ); ?></th><td><label><?php esc_html_e( 'حداقل (واحد پول فروشگاه)', 'parsishop-crm' ); ?> <input type="number" name="min_total" min="0" step="any" class="small-text"></label> &nbsp; <label><?php esc_html_e( 'حداکثر (واحد پول فروشگاه)', 'parsishop-crm' ); ?> <input type="number" name="max_total" min="0" step="any" class="small-text"></label></td></tr>
						<tr><th><?php esc_html_e( 'محدودیت تعداد پیام', 'parsishop-crm' ); ?></th><td><label><?php esc_html_e( 'حداکثر تعداد', 'parsishop-crm' ); ?> <input type="number" name="frequency_cap_count" min="0" max="20" value="0" class="small-text"></label> <label><?php esc_html_e( 'در بازه روز', 'parsishop-crm' ); ?> <input type="number" name="frequency_cap_days" min="0" max="365" value="0" class="small-text"></label><p class="description"><?php esc_html_e( 'صفر یعنی بدون محدودیت. محدودیت روی ارسال‌های موفق قبلی همان شماره اعمال می‌شود.', 'parsishop-crm' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'تکرار پیام', 'parsishop-crm' ); ?></th><td><label><?php esc_html_e( 'تعداد کل ارسال‌ها', 'parsishop-crm' ); ?> <input type="number" name="repeat_count" min="1" max="10" value="1" class="small-text"></label> <label><?php esc_html_e( 'فاصله بین ارسال‌ها', 'parsishop-crm' ); ?> <input type="number" name="repeat_interval_val" min="0" max="3650" value="0" class="small-text"></label> <select name="repeat_interval_unit"><option value="hours"><?php esc_html_e( 'ساعت', 'parsishop-crm' ); ?></option><option value="days" selected><?php esc_html_e( 'روز', 'parsishop-crm' ); ?></option><option value="weeks"><?php esc_html_e( 'هفته', 'parsishop-crm' ); ?></option><option value="months"><?php esc_html_e( 'ماه', 'parsishop-crm' ); ?></option></select></td></tr>
					</table>
					<?php submit_button( __( 'ایجاد اتوماسیون', 'parsishop-crm' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="parsishop-crm-card">
				<h2><?php esc_html_e( 'اتوماسیون‌های ثبت‌شده', 'parsishop-crm' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th><?php esc_html_e( 'شناسه', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'نام', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'وضعیت', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'رویداد', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'نوع', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'تأخیر', 'parsishop-crm' ); ?></th><th><?php esc_html_e( 'عملیات', 'parsishop-crm' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $plans ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'هنوز اتوماسیونی ایجاد نشده است.', 'parsishop-crm' ); ?></td></tr>
					<?php else : foreach ( $plans as $plan ) : ?>
						<?php
						$triggers = json_decode( (string) $plan->trigger_statuses, true );
						$trigger_names = array( 'wc-processing' => __( 'در حال انجام', 'parsishop-crm' ), 'wc-completed' => __( 'تکمیل‌شده', 'parsishop-crm' ), 'wc-on-hold' => __( 'در انتظار بررسی', 'parsishop-crm' ) );
						$trigger_label = is_array( $triggers ) ? implode( '، ', array_map( static fn( $trigger ) => $trigger_names[ $trigger ] ?? $trigger, $triggers ) ) : '';
						$delay_units = array( 'hours' => __( 'ساعت', 'parsishop-crm' ), 'days' => __( 'روز', 'parsishop-crm' ), 'weeks' => __( 'هفته', 'parsishop-crm' ), 'months' => __( 'ماه', 'parsishop-crm' ) );
						$delay_label = (string) $plan->initial_delay_val . ' ' . ( $delay_units[ $plan->initial_delay_unit ] ?? $plan->initial_delay_unit );
					?>
						<tr>
							<td><?php echo esc_html( (string) $plan->id ); ?></td>
							<td><strong><?php echo esc_html( $plan->name ); ?></strong></td>
							<td><?php echo esc_html( 'active' === $plan->status ? __( 'فعال', 'parsishop-crm' ) : __( 'متوقف', 'parsishop-crm' ) ); ?></td>
							<td dir="ltr"><?php echo esc_html( $trigger_label ); ?></td>
							<td><?php echo esc_html( 'pattern' === $plan->send_type ? __( 'پترن', 'parsishop-crm' ) : __( 'متنی', 'parsishop-crm' ) ); ?></td>
							<td><?php echo esc_html( $delay_label ); ?></td>
							<td>
								<form method="post" style="display:inline-block">
									<?php wp_nonce_field( 'parsishop_plan_action' ); ?>
									<input type="hidden" name="parsishop_plan_action" value="toggle"><input type="hidden" name="plan_id" value="<?php echo esc_attr( (string) $plan->id ); ?>">
									<button class="button button-small" type="submit"><?php echo esc_html( 'active' === $plan->status ? __( 'توقف', 'parsishop-crm' ) : __( 'فعال‌سازی', 'parsishop-crm' ) ); ?></button>
								</form>
								<form method="post" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'این اتوماسیون و کارهای زمان‌بندی‌شده آن حذف شوند؟', 'parsishop-crm' ) ); ?>')">
									<?php wp_nonce_field( 'parsishop_plan_action' ); ?>
									<input type="hidden" name="parsishop_plan_action" value="delete"><input type="hidden" name="plan_id" value="<?php echo esc_attr( (string) $plan->id ); ?>">
									<button class="button button-small button-link-delete" type="submit"><?php esc_html_e( 'حذف', 'parsishop-crm' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
