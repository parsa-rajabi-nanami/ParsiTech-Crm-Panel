<?php
declare(strict_types=1);
namespace ParsiShop\CRM\Admin;
use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Repositories\LogRepository;
use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Scheduler\OrderEventListener;
use ParsiShop\CRM\Repositories\JobRepository;
if ( ! defined( 'ABSPATH' ) ) exit;

class LogsPage {
    public function init() {
        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'handle_retry_action']);
    }
    
    public function register_page() {
        add_submenu_page(
            'parsishop-crm',
            'گزارش ارسال‌ها',
            'گزارش ارسال‌ها',
            Capabilities::MANAGE_CAP,
            'parsishop-crm-logs',
            [$this, 'render_page']
        );
    }

    public function handle_retry_action() {
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! isset( $_POST['retry_job'] ) ) {
            return;
        }
        if ( ! Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'parsishop-crm' ), '', array( 'response' => 403 ) );
        }

        $job_id = absint( wp_unslash( $_POST['retry_job'] ) );
        if ( $job_id < 1 ) {
            return;
        }
        check_admin_referer( 'retry_job_' . $job_id );

        $job = JobRepository::find( $job_id );
        if ( ! $job || ! in_array( $job->status, array( 'failed_permanent', 'failed_retry' ), true ) ) {
            return;
        }

        $action_args = array( 'job_id' => $job_id );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'parsishop_crm_execute_sms_job', $action_args, 'parsishop-crm' );
        } else {
            wp_clear_scheduled_hook( 'parsishop_crm_execute_sms_job', $action_args );
        }
        JobRepository::update( $job_id, array( 'status' => 'pending', 'attempts' => 0, 'last_error' => null ) );
        OrderEventListener::schedule_job( $job_id, time() + 5 );
        wp_safe_redirect( admin_url( 'admin.php?page=parsishop-crm-logs&retried=1' ) );
        exit;
    }

    public function render_page() {
        if ( ! Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsishop-crm' ) );
        }
        $paged = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = 20;
        
        $filters = [];
        if (!empty($_GET['filter_status'])) $filters['status'] = sanitize_key($_GET['filter_status']);
        if (!empty($_GET['filter_phone'])) $filters['phone'] = sanitize_text_field($_GET['filter_phone']);
        
        $data = LogRepository::paginate($filters, $paged, $per_page);
        $total_pages = ceil($data['total'] / $per_page);
        
        ?>
        <div class="wrap" dir="rtl">
            <h1 class="wp-heading-inline">گزارش ارسال پیامک</h1>
            <hr class="wp-header-end">
            
            <?php if (isset($_GET['retried'])): ?>
                <div class="notice notice-success is-dismissible"><p>درخواست تلاش مجدد برای ارسال در صف قرار گرفت.</p></div>
            <?php endif; ?>

            <form method="get" style="margin-bottom:15px; background:#fff; padding:15px; border:1px solid #ccc; border-radius:5px;">
                <input type="hidden" name="page" value="parsishop-crm-logs">
                <strong>فیلترها: </strong>
                <select name="filter_status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="sent" <?php selected($filters['status'] ?? '', 'sent'); ?>>ثبت ارسال در پنل</option>
                    <option value="failed" <?php selected($filters['status'] ?? '', 'failed'); ?>>ناموفق</option>
                    <option value="sandbox" <?php selected($filters['status'] ?? '', 'sandbox'); ?>>شبیه‌سازی</option>
                    <option value="skipped" <?php selected($filters['status'] ?? '', 'skipped'); ?>>ردشده (رضایت/سیاست)</option>
                </select>
                
                <input type="text" name="filter_phone" placeholder="جستجوی دقیق شماره..." value="<?php echo esc_attr($_GET['filter_phone'] ?? ''); ?>">
                <button type="submit" class="button">اعمال فیلتر</button>
                <a href="?page=parsishop-crm-logs" class="button">پاک کردن</a>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th>شناسه</th><th>شماره گیرنده</th><th>وضعیت</th><th>خلاصه وضعیت / خطا</th><th>سفارش</th><th>زمان (تاریخ سایت)</th><th>عملیات</th>
                </tr></thead>
                <tbody>
                <?php if(empty($data['items'])): ?>
                    <tr><td colspan="7">رکوردی یافت نشد.</td></tr>
                <?php else: foreach ($data['items'] as $log): ?>
                    <tr>
                        <td><?php echo esc_html((string)$log->id); ?></td>
                        <td dir="ltr" style="text-align:right;"><?php echo esc_html($log->phone_masked); ?></td>
                        <td>
                            <?php
                                $status_labels = array(
                                    'sent'    => __( 'ثبت ارسال در پنل', 'parsishop-crm' ),
                                    'failed'  => __( 'ناموفق', 'parsishop-crm' ),
                                    'sandbox' => __( 'شبیه‌سازی', 'parsishop-crm' ),
                                    'skipped' => __( 'ردشده', 'parsishop-crm' ),
                                );
                                echo esc_html( $status_labels[ $log->status ] ?? $log->status );
                            ?>
                        </td>
                        <td><?php echo esc_html((string)$log->api_response_summary); ?></td>
                        <td>
                            <?php if($log->order_id): ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $log->order_id ) ) ); ?>">#<?php echo esc_html((string)$log->order_id); ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td dir="ltr" style="text-align:right;"><?php echo esc_html(DateHelper::format_for_display($log->created_at)); ?></td>
                        <td>
                            <?php if ( 'failed' === $log->status && $log->job_id ): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="retry_job" value="<?php echo esc_attr( (string) absint( $log->job_id ) ); ?>">
                                    <?php wp_nonce_field( 'retry_job_' . absint( $log->job_id ) ); ?>
                                    <button type="submit" class="button button-small">ارسال مجدد</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '&laquo; قبلی',
                        'next_text' => 'بعدی &raquo;',
                        'total' => $total_pages,
                        'current' => $paged
                    ]);
                    ?>
                </div></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
