<?php

declare(strict_types=1);

namespace ParsiReach\Admin;

use ParsiReach\Core\Capabilities;
use ParsiReach\Providers\SmsProviderFactory;
use ParsiReach\Repositories\LogRepository;
use ParsiReach\Scheduler\MaintenanceScheduler;
use ParsiReach\Support\Crypto;
use ParsiReach\Support\PhoneNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const OPTION_NAME = 'parsireach_settings';
	public const PAGE_SLUG   = 'parsireach-settings';

	private ?string $page_hook = null;

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_options_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_parsireach_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_parsireach_send_test_sms', array( $this, 'ajax_send_test_sms' ) );
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
			'log_retention_days'     => 90,
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
		$decrypted = Crypto::decrypt( $encrypted );
		if ( '' !== $decrypted ) {
			return trim( $decrypted );
		}

		return '';
	}

	public function register_options_page(): void {
		$this->page_hook = (string) add_submenu_page(
			'parsireach',
			__( 'تنظیمات ParsiReach', 'parsireach' ),
			__( 'تنظیمات پیامک', 'parsireach' ),
			Capabilities::MANAGE_CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'parsireach_settings_group',
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
		$clean['log_retention_days']     = max( 7, min( 3650, (int) ( $input['log_retention_days'] ?? 90 ) ) );

		$raw_new_key = trim( (string) ( $input['new_api_key'] ?? '' ) );
		if ( '' !== $raw_new_key ) {
			$encrypted_key = Crypto::encrypt( $raw_new_key );
			if ( '' === $encrypted_key ) {
				add_settings_error( self::OPTION_NAME, 'api_key_encryption_failed', __( 'رمزنگاری کلید API ناموفق بود؛ کلید قبلی بدون تغییر باقی ماند.', 'parsireach' ), 'error' );
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
			\ParsiReach\Repositories\JobRepository::resume_paused_jobs();
		}

		return $clean;
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'parsireach' ) ) {
			return;
		}

		wp_enqueue_style(
			'parsireach-admin-rtl',
			PARSIREACH_URL . 'assets/css/admin-rtl.css',
			array(),
			PARSIREACH_VERSION
		);

		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_script(
			'parsireach-admin-settings',
			PARSIREACH_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			PARSIREACH_VERSION,
			true
		);

		wp_localize_script(
			'parsireach-admin-settings',
			'parsireachSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'parsireach_settings_ajax' ),
				'i18n'    => array(
					'testing' => __( 'در حال بررسی اتصال...', 'parsireach' ),
					'sending' => __( 'در حال ارسال پیامک تست...', 'parsireach' ),
					'error'   => __( 'خطا در برقراری ارتباط با سرور.', 'parsireach' ),
				),
			)
		);
	}

	public function render_page(): void {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'شما دسترسی لازم برای مشاهده این صفحه را ندارید.', 'parsireach' ) );
		}

		$settings       = self::get_settings();
		$decrypted_key  = self::get_decrypted_api_key();
		$masked_api_key = Crypto::mask( $decrypted_key );
		$api_key_unreadable = '' !== (string) ( $settings['api_key_encrypted'] ?? '' ) && '' === $decrypted_key;

		require PARSIREACH_PATH . 'templates/admin/settings.php';
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'parsireach_settings_ajax', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'عدم دسترسی مجاز.', 'parsireach' ) ), 403 );
		}

		$settings = self::get_settings();
		if ( '' === self::get_decrypted_api_key() ) {
			$message = ! empty( $settings['api_key_encrypted'] )
				? __( 'کلید ذخیره‌شده قابل رمزگشایی نیست. اگر کلیدهای امنیتی وردپرس تغییر کرده‌اند، API Key را دوباره وارد و ذخیره کنید.', 'parsireach' )
				: __( 'API Key ذخیره نشده است. ابتدا کلید را وارد و تنظیمات را ذخیره کنید.', 'parsireach' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}

		$res = MaintenanceScheduler::refresh_and_check_credit( true );

		if ( $res['success'] ) {
			wp_send_json_success(
				array(
					'message'      => sprintf(
						__( 'اتصال برقرار است. اعتبار فعلی پنل: %s تومان', 'parsireach' ),
						number_format_i18n( $res['credit_toman'] )
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
		check_ajax_referer( 'parsireach_settings_ajax', 'nonce' );

		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'عدم دسترسی مجاز.', 'parsireach' ) ), 403 );
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'برای ارسال تست، ابتدا سرویس را فعال و تنظیمات را ذخیره کنید.', 'parsireach' ) ) );
		}

		$raw_phone    = isset( $_POST['phone'] ) && is_scalar( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$pattern_code = isset( $_POST['pattern_code'] ) && is_scalar( $_POST['pattern_code'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern_code'] ) ) : '';
		$values_json  = isset( $_POST['pattern_values'] ) && is_string( $_POST['pattern_values'] ) ? trim( wp_unslash( $_POST['pattern_values'] ) ) : '';

		$phone = PhoneNormalizer::normalize( $raw_phone );
		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'شماره موبایل واردشده معتبر نیست.', 'parsireach' ) ) );
		}

		$values = json_decode( $values_json, true );
		if ( '' === $pattern_code || ! str_starts_with( ltrim( $values_json ), '{' ) || ! is_array( $values ) || empty( $values ) ) {
			wp_send_json_error( array( 'message' => __( 'کد پترن و یک JSON object معتبر از متغیرها الزامی است.', 'parsireach' ) ) );
		}
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$values[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$provider = SmsProviderFactory::make( false );

		$result = $provider->send_pattern( $phone, $pattern_code, $values );

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
