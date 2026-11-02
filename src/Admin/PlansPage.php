<?php

declare(strict_types=1);

namespace ParsiReach\Admin;

use ParsiReach\Core\Capabilities;
use ParsiReach\Repositories\PlanRepository;
use ParsiReach\Support\DateHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlansPage {
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'parsireach',
			__( 'اتوماسیون‌های بازاریابی', 'parsireach' ),
			__( 'اتوماسیون‌ها', 'parsireach' ),
			Capabilities::MANAGE_CAP,
			'parsireach-plans',
			array( $this, 'render_page' )
		);
	}

	private function handle_actions(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['parsireach_plan_action'] ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'parsireach' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'parsireach_plan_action' );

		$action = sanitize_key( wp_unslash( $_POST['parsireach_plan_action'] ) );
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
			add_settings_error( 'parsireach_plans', 'plan_name_required', __( 'نام اتوماسیون الزامی است.', 'parsireach' ), 'error' );
			return;
		}

		$trigger = isset( $_POST['trigger_status'] ) ? sanitize_key( wp_unslash( $_POST['trigger_status'] ) ) : 'completed';
		$available_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( ! isset( $available_statuses[ 'wc-' . $trigger ] ) ) {
			$trigger = 'completed';
		}

		$pattern_code = isset( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
		if ( '' === $pattern_code ) {
			add_settings_error( 'parsireach_plans', 'pattern_required', __( 'برای ارسال پترن، کد پترن الزامی است.', 'parsireach' ), 'error' );
			return;
		}

		$pattern_values = isset( $_POST['pattern_values'] ) ? trim( wp_unslash( $_POST['pattern_values'] ) ) : '';
		$values = json_decode( $pattern_values, true );
		if ( ! str_starts_with( ltrim( $pattern_values ), '{' ) || ! is_array( $values ) || empty( $values ) ) {
			add_settings_error( 'parsireach_plans', 'pattern_values_invalid', __( 'متغیرهای پترن باید یک JSON object معتبر باشند.', 'parsireach' ), 'error' );
			return;
		}
		$pattern_values = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );

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
			add_settings_error( 'parsireach_plans', 'repeat_interval_required', __( 'برای پیام‌های تکراری، فاصله ارسال باید حداقل یک واحد باشد.', 'parsireach' ), 'error' );
			return;
		}

		$now = DateHelper::now_utc();
		$data = array(
			'name'                 => $name,
			'status'               => 'active',
			'trigger_status'       => $trigger,
			'initial_delay_val'    => $delay_value,
			'initial_delay_unit'   => $delay_unit,
			'repeat_count'         => $repeat_count,
			'repeat_interval_val'  => $repeat_value,
			'repeat_interval_unit' => $repeat_unit,
			'pattern_code'         => $pattern_code,
			'pattern_values'       => $pattern_values,
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		if ( PlanRepository::insert( $data ) > 0 ) {
			$this->redirect_after_action( 'created' );
		} else {
			add_settings_error( 'parsireach_plans', 'plan_create_failed', __( 'ذخیره اتوماسیون در پایگاه داده ناموفق بود.', 'parsireach' ), 'error' );
		}
	}


	private function redirect_after_action( string $action ): void {
		$url = add_query_arg(
			array( 'page' => 'parsireach-plans', 'pr_action' => sanitize_key( $action ) ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsireach' ) );
		}

		$this->handle_actions();
		$plans = PlanRepository::get_all_plans();
		?>
		<div class="wrap parsireach-wrap" dir="rtl">
			<h1><?php esc_html_e( 'اتوماسیون بازاریابی پیامکی', 'parsireach' ); ?></h1>
			<p><?php esc_html_e( 'با تغییر وضعیت سفارش، برای شماره ثبت‌شده در سفارش پیام پترن ارسال می‌شود. ابتدا سرویس را در تنظیمات فعال کنید.', 'parsireach' ); ?></p>
			<?php settings_errors( 'parsireach_plans' ); ?>
			<?php if ( isset( $_GET['pr_action'] ) ) : $action_notice = sanitize_key( wp_unslash( $_GET['pr_action'] ) ); ?>
				<?php if ( 'created' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'اتوماسیون با موفقیت ایجاد شد.', 'parsireach' ); ?></p></div><?php endif; ?>
				<?php if ( 'updated' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'وضعیت اتوماسیون به‌روزرسانی شد.', 'parsireach' ); ?></p></div><?php endif; ?>
				<?php if ( 'deleted' === $action_notice ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'اتوماسیون حذف شد.', 'parsireach' ); ?></p></div><?php endif; ?>
			<?php endif; ?>

			<div class="parsireach-card">
				<h2><?php esc_html_e( 'ایجاد اتوماسیون جدید', 'parsireach' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'parsireach_plan_action' ); ?>
					<input type="hidden" name="parsireach_plan_action" value="create">
					<table class="form-table" role="presentation">
						<tr><th><label for="pr-plan-name"><?php esc_html_e( 'نام اتوماسیون', 'parsireach' ); ?></label></th><td><input id="pr-plan-name" type="text" name="plan_name" class="regular-text" required maxlength="191" placeholder="مثلاً بازگشت مشتری پس از خرید"></td></tr>
						<tr><th><label for="pr-trigger"><?php esc_html_e( 'رویداد شروع', 'parsireach' ); ?></label></th><td><select id="pr-trigger" name="trigger_status">
							<?php foreach ( wc_get_order_statuses() as $status_key => $status_label ) : $status_slug = preg_replace( '/^wc-/', '', (string) $status_key ); ?>
								<option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( 'completed', $status_slug ); ?>><?php echo esc_html( $status_label ); ?></option>
							<?php endforeach; ?>
						</select><p class="description"><?php esc_html_e( 'تمام وضعیت‌های سفارش ثبت‌شده در ووکامرس، از جمله وضعیت‌های افزونه‌های دیگر، نمایش داده می‌شوند.', 'parsireach' ); ?></p></td></tr>
						<tr><th><label for="pr-delay"><?php esc_html_e( 'تأخیر اولین پیام', 'parsireach' ); ?></label></th><td><input id="pr-delay" type="number" name="delay_val" class="small-text" min="0" max="3650" value="0"><select name="delay_unit"><option value="hours"><?php esc_html_e( 'ساعت', 'parsireach' ); ?></option><option value="days" selected><?php esc_html_e( 'روز', 'parsireach' ); ?></option><option value="weeks"><?php esc_html_e( 'هفته', 'parsireach' ); ?></option><option value="months"><?php esc_html_e( 'ماه (۳۰ روز)', 'parsireach' ); ?></option></select></td></tr>
						<tr><th><label for="pr-pattern"><?php esc_html_e( 'کد پترن', 'parsireach' ); ?></label></th><td><input id="pr-pattern" type="text" name="pattern_code" class="regular-text" dir="ltr" required placeholder="Pattern code"></td></tr>
						<tr><th><label for="pr-template"><?php esc_html_e( 'متغیرهای پترن (JSON)', 'parsireach' ); ?></label></th><td><textarea id="pr-template" name="pattern_values" rows="5" class="large-text code" required placeholder="{&quot;name&quot;:&quot;{customer_name}&quot;,&quot;order&quot;:&quot;{order_number}&quot;}"></textarea><p class="description"><?php esc_html_e( 'متغیرهای پترن را به شکل JSON وارد کنید. جای‌گذاری متغیرها: {customer_name}، {customer_last_name}، {order_number}، {order_date}، {order_total}، {product_name} و {shop_name}.', 'parsireach' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'تکرار پیام', 'parsireach' ); ?></th><td><label><?php esc_html_e( 'تعداد کل ارسال‌ها', 'parsireach' ); ?> <input type="number" name="repeat_count" min="1" max="10" value="1" class="small-text"></label> <label><?php esc_html_e( 'فاصله بین ارسال‌ها', 'parsireach' ); ?> <input type="number" name="repeat_interval_val" min="0" max="3650" value="0" class="small-text"></label> <select name="repeat_interval_unit"><option value="hours"><?php esc_html_e( 'ساعت', 'parsireach' ); ?></option><option value="days" selected><?php esc_html_e( 'روز', 'parsireach' ); ?></option><option value="weeks"><?php esc_html_e( 'هفته', 'parsireach' ); ?></option><option value="months"><?php esc_html_e( 'ماه', 'parsireach' ); ?></option></select></td></tr>
					</table>
					<?php submit_button( __( 'ایجاد اتوماسیون', 'parsireach' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="parsireach-card">
				<h2><?php esc_html_e( 'اتوماسیون‌های ثبت‌شده', 'parsireach' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th><?php esc_html_e( 'شناسه', 'parsireach' ); ?></th><th><?php esc_html_e( 'نام', 'parsireach' ); ?></th><th><?php esc_html_e( 'وضعیت', 'parsireach' ); ?></th><th><?php esc_html_e( 'رویداد', 'parsireach' ); ?></th><th><?php esc_html_e( 'تأخیر', 'parsireach' ); ?></th><th><?php esc_html_e( 'عملیات', 'parsireach' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $plans ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'هنوز اتوماسیونی ایجاد نشده است.', 'parsireach' ); ?></td></tr>
					<?php else : foreach ( $plans as $plan ) : ?>
						<?php
						$trigger_names = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
						$trigger_label = $trigger_names[ 'wc-' . (string) $plan->trigger_status ] ?? (string) $plan->trigger_status;
						$delay_units = array( 'hours' => __( 'ساعت', 'parsireach' ), 'days' => __( 'روز', 'parsireach' ), 'weeks' => __( 'هفته', 'parsireach' ), 'months' => __( 'ماه', 'parsireach' ) );
						$delay_label = (string) $plan->initial_delay_val . ' ' . ( $delay_units[ $plan->initial_delay_unit ] ?? $plan->initial_delay_unit );
					?>
						<tr>
							<td><?php echo esc_html( (string) $plan->id ); ?></td>
							<td><strong><?php echo esc_html( $plan->name ); ?></strong></td>
							<td><?php echo esc_html( 'active' === $plan->status ? __( 'فعال', 'parsireach' ) : __( 'متوقف', 'parsireach' ) ); ?></td>
							<td dir="ltr"><?php echo esc_html( $trigger_label ); ?></td>
							<td><?php echo esc_html( $delay_label ); ?></td>
							<td>
								<form method="post" style="display:inline-block">
									<?php wp_nonce_field( 'parsireach_plan_action' ); ?>
									<input type="hidden" name="parsireach_plan_action" value="toggle"><input type="hidden" name="plan_id" value="<?php echo esc_attr( (string) $plan->id ); ?>">
									<button class="button button-small" type="submit"><?php echo esc_html( 'active' === $plan->status ? __( 'توقف', 'parsireach' ) : __( 'فعال‌سازی', 'parsireach' ) ); ?></button>
								</form>
								<form method="post" style="display:inline-block" onsubmit="return confirm('<?php echo esc_js( __( 'این اتوماسیون و کارهای زمان‌بندی‌شده آن حذف شوند؟', 'parsireach' ) ); ?>')">
									<?php wp_nonce_field( 'parsireach_plan_action' ); ?>
									<input type="hidden" name="parsireach_plan_action" value="delete"><input type="hidden" name="plan_id" value="<?php echo esc_attr( (string) $plan->id ); ?>">
									<button class="button button-small button-link-delete" type="submit"><?php esc_html_e( 'حذف', 'parsireach' ); ?></button>
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
