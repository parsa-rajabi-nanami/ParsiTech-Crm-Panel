<?php

declare(strict_types=1);

namespace ParsiReach\Admin;

use ParsiReach\Core\Capabilities;
use ParsiReach\Repositories\JobRepository;
use ParsiReach\Repositories\LogRepository;
use ParsiReach\Scheduler\OrderEventListener;
use ParsiReach\Support\DateHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogsPage {
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			'parsireach',
			__( 'گزارش ارسال‌ها', 'parsireach' ),
			__( 'گزارش ارسال‌ها', 'parsireach' ),
			Capabilities::MANAGE_CAP,
			'parsireach-logs',
			array( $this, 'render_page' )
		);
	}

	public function handle_actions(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['logs_action'] ) ) {
			return;
		}
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'parsireach' ), '', array( 'response' => 403 ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['logs_action'] ) );
		if ( 'retry' === $action ) {
			$job_id = isset( $_POST['retry_job'] ) ? absint( wp_unslash( $_POST['retry_job'] ) ) : 0;
			check_admin_referer( 'parsireach_logs_action' );
			$job = JobRepository::find( $job_id );
			if ( $job && in_array( $job->status, array( 'failed_permanent', 'failed_retry' ), true ) ) {
				$args = array( 'job_id' => $job_id );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'parsireach_execute_sms_job', $args, 'parsireach' );
				} else {
					wp_clear_scheduled_hook( 'parsireach_execute_sms_job', $args );
				}
				JobRepository::update( $job_id, array( 'status' => 'pending', 'attempts' => 0, 'last_error' => null ) );
				OrderEventListener::schedule_job( $job_id, time() + 5 );
			}
			$this->redirect_after_action( 'retried' );
		}

		check_admin_referer( 'parsireach_logs_action' );
		if ( 'delete_all' === $action ) {
			LogRepository::delete_all();
		} elseif ( 'delete_selected' === $action ) {
			$ids = isset( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] )
				? array_map( 'absint', wp_unslash( $_POST['log_ids'] ) )
				: array();
			LogRepository::delete_ids( $ids );
		}
		$this->redirect_after_action( 'deleted' );
	}

	private function redirect_after_action( string $result ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'parsireach-logs', 'result' => $result ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'parsireach' ) );
		}

		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$filters  = array();
		if ( ! empty( $_GET['filter_status'] ) ) {
			$filters['status'] = sanitize_key( wp_unslash( $_GET['filter_status'] ) );
		}
		if ( ! empty( $_GET['filter_phone'] ) ) {
			$filters['phone'] = sanitize_text_field( wp_unslash( $_GET['filter_phone'] ) );
		}
		$data        = LogRepository::paginate( $filters, $paged, $per_page );
		$total_pages = (int) ceil( $data['total'] / $per_page );
		?>
		<div class="wrap parsireach-wrap" dir="rtl">
			<h1><?php esc_html_e( 'گزارش ارسال پیامک', 'parsireach' ); ?></h1>
			<?php if ( isset( $_GET['result'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'retried' === sanitize_key( wp_unslash( $_GET['result'] ) ) ? esc_html__( 'ارسال مجدد در صف قرار گرفت.', 'parsireach' ) : esc_html__( 'عملیات روی گزارش‌ها انجام شد.', 'parsireach' ); ?></p></div>
			<?php endif; ?>
			<form method="get" class="parsireach-card">
				<input type="hidden" name="page" value="parsireach-logs">
				<strong><?php esc_html_e( 'فیلترها:', 'parsireach' ); ?></strong>
				<select name="filter_status">
					<option value=""><?php esc_html_e( 'همه وضعیت‌ها', 'parsireach' ); ?></option>
					<?php foreach ( array( 'sent' => 'ثبت ارسال در پنل', 'failed' => 'ناموفق', 'sandbox' => 'شبیه‌سازی', 'skipped' => 'ردشده' ) as $status => $label ) : ?>
						<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'] ?? '', $status ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="filter_phone" placeholder="<?php esc_attr_e( 'جستجوی شماره', 'parsireach' ); ?>" value="<?php echo esc_attr( $filters['phone'] ?? '' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'اعمال فیلتر', 'parsireach' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=parsireach-logs' ) ); ?>" class="button"><?php esc_html_e( 'پاک‌کردن فیلتر', 'parsireach' ); ?></a>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'parsireach_logs_action' ); ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.parsireach-log-select').forEach((item) => item.checked = this.checked)"></td><th><?php esc_html_e( 'شناسه', 'parsireach' ); ?></th><th><?php esc_html_e( 'شماره گیرنده', 'parsireach' ); ?></th><th><?php esc_html_e( 'وضعیت', 'parsireach' ); ?></th><th><?php esc_html_e( 'خلاصه وضعیت / خطا', 'parsireach' ); ?></th><th><?php esc_html_e( 'سفارش', 'parsireach' ); ?></th><th><?php esc_html_e( 'زمان', 'parsireach' ); ?></th><th><?php esc_html_e( 'عملیات', 'parsireach' ); ?></th></tr></thead>
					<tbody>
					<?php if ( empty( $data['items'] ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'رکوردی یافت نشد.', 'parsireach' ); ?></td></tr>
					<?php else : foreach ( $data['items'] as $log ) : ?>
						<tr>
							<th class="check-column"><input class="parsireach-log-select" type="checkbox" name="log_ids[]" value="<?php echo esc_attr( (string) absint( $log->id ) ); ?>"></th>
							<td><?php echo esc_html( (string) $log->id ); ?></td>
							<td dir="ltr"><?php echo esc_html( $log->phone_masked ); ?></td>
							<td><?php echo esc_html( (string) $log->status ); ?></td>
							<td><?php echo esc_html( (string) $log->api_response_summary ); ?></td>
							<td><?php if ( $log->order_id ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $log->order_id ) ) ); ?>">#<?php echo esc_html( (string) $log->order_id ); ?></a><?php else : ?>—<?php endif; ?></td>
							<td><?php echo esc_html( DateHelper::format_for_display( $log->created_at ) ); ?></td>
							<td><?php if ( 'failed' === $log->status && $log->job_id ) : ?><button type="submit" class="button button-small" name="logs_action" value="retry" formaction="<?php echo esc_url( admin_url( 'admin.php?page=parsireach-logs' ) ); ?>" onclick="this.form.retry_job.value='<?php echo esc_js( (string) absint( $log->job_id ) ); ?>'"><?php esc_html_e( 'ارسال مجدد', 'parsireach' ); ?></button><?php endif; ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
				<input type="hidden" name="retry_job" value="0">
				<p>
					<button type="submit" class="button button-secondary" name="logs_action" value="delete_selected" onclick="return confirm('<?php echo esc_js( __( 'رکوردهای انتخاب‌شده حذف شوند؟', 'parsireach' ) ); ?>')"><?php esc_html_e( 'حذف انتخاب‌شده‌ها', 'parsireach' ); ?></button>
					<button type="submit" class="button button-link-delete" name="logs_action" value="delete_all" onclick="return confirm('<?php echo esc_js( __( 'تمام گزارش‌های ارسال حذف شوند؟ این کار برگشت‌پذیر نیست.', 'parsireach' ) ); ?>')"><?php esc_html_e( 'حذف همه گزارش‌ها', 'parsireach' ); ?></button>
				</p>
			</form>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( array_merge( array( 'page' => 'parsireach-logs' ), $filters, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'format' => '', 'prev_text' => '&laquo; قبلی', 'next_text' => 'بعدی &raquo;', 'total' => $total_pages, 'current' => $paged ) ) ); ?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
