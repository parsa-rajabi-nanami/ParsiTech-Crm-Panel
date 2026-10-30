<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Admin;

use ParsiShop\CRM\Core\Capabilities;
use ParsiShop\CRM\Providers\SmsProviderFactory;
use ParsiShop\CRM\Repositories\LogRepository;
use ParsiShop\CRM\Scheduler\MaintenanceScheduler;
use ParsiShop\CRM\Support\Crypto;
use ParsiShop\CRM\Support\PhoneNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const OPTION_NAME = 'parsishop_crm_settings';
	public const PAGE_SLUG   = 'parsishop-crm-settings';

	private ?string $page_hook = null;

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_options_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_parsishop_crm_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_parsishop_crm_send_test_sms', array( $this, 'ajax_send_test_sms' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_default_settings(): array {
		return array(
			'enabled'                => false,
			'sandbox_mode'           => true,
			'api_key_encrypted'      => '',
			'sender_number'          => '',
			'enforce_time_window'    => true,
			'window_start'           => '09:00',
			'window_end'             => '21:00',
			'low_credit_threshold'   => 50000,
			'log_retention_days'     => 90,
			'append_optout_text'     => true,
			'optout_footer_text'     => 'لغو ۱۱',
			'checkout_consent_field' => false,
			'require_marketing_consent' => true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_default_settings() );
	}

	public static function get_decrypted_api_key(): string {
		$settings  = self::get_settings();
		$encrypted = (string) ( $settings['api_key_encrypted'] ?? '' );
		return Crypto::decrypt( $encrypted );
	}

	public function register_options_page(): void {
		$this->page_hook = (string) add_submenu_page(
			'parsishop-crm',
			__( 'تنظیمات ParsiReach', 'parsishop-crm' ),
			__( 'تنظیمات پیامک', 'parsishop-crm' ),
			Capabilities::MANAGE_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'parsishop_crm_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_default_settings(),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_settings();
		}

		$current = self::get_settings();
		$clean   = array();

		$clean['enabled']                = ! empty( $input['enabled'] );
		$clean['sandbox_mode']           = ! empty( $input['sandbox_mode'] );
		$clean['sender_number']          = sanitize_text_field( (string) ( $input['sender_number'] ?? '' ) );
		$clean['enforce_time_window']    = ! empty( $input['enforce_time_window'] );
		$clean['window_start']           = $this->sanitize_time_hhmm( (string) ( $input['window_start'] ?? '09:00' ), '09:00' );
		$clean['window_end']             = $this->sanitize_time_hhmm( (string) ( $input['window_end'] ?? '21:00' ), '21:00' );
		$clean['low_credit_threshold']   = max( 0, (int) ( $input['low_credit_threshold'] ?? 50000 ) );
		$clean['log_retention_days']     = max( 7, min( 3650, (int) ( $input['log_retention_days'] ?? 90 ) ) );
		$clean['append_optout_text']     = ! empty( $input['append_optout_text'] );
		$clean['optout_footer_text']     = sanitize_text_field( (string) ( $input['optout_footer_text'] ?? 'لغو ۱۱' ) );
		$clean['checkout_consent_field'] = ! empty( $input['checkout_consent_field'] );
		$clean['require_marketing_consent'] = ! empty( $input['require_marketing_consent'] );
		if ( $clean['window_start'] === $clean['window_end'] ) {
			$clean['window_start'] = '09:00';
			$clean['window_end']   = '21:00';
		}

		$raw_new_key = trim( (string) ( $input['new_api_key'] ?? '' ) );
		if ( '' !== $raw_new_key ) {
			$encrypted_key = Crypto::encrypt( $raw_new_key );
			if ( '' === $encrypted_key ) {
				add_settings_error( self::OPTION_NAME, 'api_key_encryption_failed', __( 'رمزنگاری کلید API ناموفق بود؛ کلید قبلی بدون تغییر باقی ماند.', 'parsishop-crm' ), 'error' );
				$clean['api_key_encrypted'] = $current['api_key_encrypted'] ?? '';
			} else {
				$clean['api_key_encrypted'] = $encrypted_key;
			}
		} elseif ( ! empty( $input['clear_api_key'] ) ) {
			$clean['api_key_encrypted'] = '';
		} else {
			$clean['api_key_encrypted'] = $current['api_key_encrypted'] ?? '';
		}

		if ( empty( $current['enabled'] ) && ! empty( $clean['enabled'] ) ) {
			\ParsiShop\CRM\Repositories\JobRepository::resume_paused_jobs();
		}

		return $clean;
	}

	private function sanitize_time_hhmm( string $value, string $fallback ): string {
		if ( 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim( $value ) ) ) {
			return trim( $value );
		}
		return $fallback;
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'parsishop-crm' ) ) {
			return;
		}

		wp_enqueue_style(
			'parsishop-crm-admin-rtl',
			PARSISHOP_CRM_URL . 'assets/css/admin-rtl.css',
			array(),
			PARSISHOP_CRM_VERSION
		);

		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_script(
			'parsishop-crm-admin-settings',
			PARSISHOP_CRM_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			PARSISHOP_CRM_VERSION,
			true
		);

		wp_localize_script(
			'parsishop-crm-admin-settings',
			'parsishopCrmSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'parsishop_crm_settings_ajax' ),
				'i18n'    => array(
					'testing' => __( 'در حال بررسی اتصال...', 'parsishop-crm' ),
					'sending' => __( 'در حال ارسال پیامک تست...', 'parsishop-crm' ),
					'error'   => __( 'خطا در برقراری ارتباط با سرور.', 'parsishop-crm' ),
				),
			)
		);
	}

	public function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsishop-crm' ) );
		}

		$settings       = self::get_settings();
		$decrypted_key  = self::get_decrypted_api_key();
		$masked_api_key = Crypto::mask( $decrypted_key );

		require PARSISHOP_CRM_PATH . 'templates/admin/settings.php';
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'parsishop_crm_settings_ajax', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'عدم دسترسی مجاز.', 'parsishop-crm' ) ), 403 );
		}

		$res = MaintenanceScheduler::refresh_and_check_credit( true );

		if ( $res['success'] ) {
			wp_send_json_success(
				array(
					'message'      => sprintf(
						__( 'اتصال برقرار است. اعتبار فعلی پنل: %s تومان (%s ریال)', 'parsishop-crm' ),
						number_format_i18n( $res['credit_toman'] ),
						number_format_i18n( $res['credit_irr'] )
					),
					'credit_toman' => $res['credit_toman'],
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => $res['message'],
			)
		);
	}

	public function ajax_send_test_sms(): void {
		check_ajax_referer( 'parsishop_crm_settings_ajax', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'عدم دسترسی مجاز.', 'parsishop-crm' ) ), 403 );
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'برای ارسال تست، ابتدا سرویس را فعال و تنظیمات را ذخیره کنید.', 'parsishop-crm' ) ) );
		}

		$raw_phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$pattern_code = isset( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
		$test_var_key = isset( $_POST['var_key'] ) ? sanitize_text_field( wp_unslash( $_POST['var_key'] ) ) : '';
		$test_var_val = isset( $_POST['var_val'] ) ? sanitize_text_field( wp_unslash( $_POST['var_val'] ) ) : '';

		$phone = PhoneNormalizer::normalize( $raw_phone );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'شماره موبایل واردشده معتبر نیست.', 'parsishop-crm' ) ) );
		}

		$provider = SmsProviderFactory::make( false );

		if ( '' !== $pattern_code ) {
			$vars = array();
			if ( '' !== $test_var_key ) {
				$vars[ $test_var_key ] = $test_var_val;
			}
			$result = $provider->send_pattern( $phone, $pattern_code, $vars );
		} else {
			$shop_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			$msg       = sprintf( "پیامک تست ParsiReach %s\nلغو ۱۱", $shop_name );
			$result    = $provider->send_text( $phone, $msg );
		}

		LogRepository::record( $phone, $provider->get_provider_slug(), $result );

		if ( $result->success ) {
			wp_send_json_success(
				array(
					'message' => $result->summary,
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => $result->summary,
			)
		);
	}
}
