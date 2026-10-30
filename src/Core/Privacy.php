<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Core;

use ParsiShop\CRM\Admin\SettingsPage;
use ParsiShop\CRM\Repositories\ConsentRepository;
use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Support\RecipientHasher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy {
	public static function init(): void {
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'checkout_checkbox' ), 10 );
		add_action( 'woocommerce_checkout_update_order_meta', array( self::class, 'save_checkout_checkbox' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'handle_unsubscribe_request' ) );
	}

	public static function checkout_checkbox(): void {
		$settings = SettingsPage::get_settings();
		if ( empty( $settings['checkout_consent_field'] ) ) {
			return;
		}

		woocommerce_form_field(
			'parsishop_crm_consent',
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide' ),
				'label'    => __( 'مایلم پیامک‌های پیشنهاد، تخفیف و اطلاع‌رسانی‌های بازاریابی فروشگاه را دریافت کنم. (اختیاری)', 'parsishop-crm' ),
				'required' => false,
				'default'  => 0,
			),
			0
		);
	}

	/**
	 * @param int   $order_id WooCommerce order ID.
	 * @param array $data Checkout data.
	 */
	public static function save_checkout_checkbox( $order_id, $data = array() ): void {
		$settings = SettingsPage::get_settings();
		$consent_value = isset( $_POST['parsishop_crm_consent'] ) ? wp_unslash( $_POST['parsishop_crm_consent'] ) : '';
		if ( empty( $settings['checkout_consent_field'] ) || ! is_scalar( $consent_value ) || '1' !== (string) $consent_value ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$phone = PhoneNormalizer::normalize( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return;
		}

		if ( ! ConsentRepository::set_consent( 'sms', $phone, true, 'checkout' ) ) {
			return;
		}

		$order->update_meta_data( '_parsishop_crm_marketing_consent', 'yes' );
		$order->update_meta_data( '_parsishop_crm_marketing_consent_at', DateHelper::now_utc() );
		$order->save();
	}

	public static function get_unsubscribe_url( string $channel, string $recipient ): string {
		$channel = sanitize_key( $channel );
		$hash    = RecipientHasher::hash( $channel, $recipient );
		if ( '' === $hash ) {
			return '';
		}

		$signature = RecipientHasher::signature( $channel, $hash );
		if ( '' === $signature ) {
			return '';
		}
		return add_query_arg(
			array(
				'pr_unsubscribe' => '1',
				'channel'        => $channel,
				'recipient'      => $hash,
				'signature'      => $signature,
			),
			home_url( '/' )
		);
	}

	public static function handle_unsubscribe_request(): void {
		if ( empty( $_GET['pr_unsubscribe'] ) ) {
			return;
		}

		$raw_channel = isset( $_GET['channel'] ) ? wp_unslash( $_GET['channel'] ) : '';
		$raw_hash = isset( $_GET['recipient'] ) ? wp_unslash( $_GET['recipient'] ) : '';
		$raw_signature = isset( $_GET['signature'] ) ? wp_unslash( $_GET['signature'] ) : '';
		$channel = is_scalar( $raw_channel ) ? sanitize_key( (string) $raw_channel ) : '';
		$hash = is_scalar( $raw_hash ) ? sanitize_text_field( (string) $raw_hash ) : '';
		$signature = is_scalar( $raw_signature ) ? sanitize_text_field( (string) $raw_signature ) : '';
		$valid_hash = 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
		$valid_channel = in_array( $channel, array( 'sms', 'email' ), true );
		$valid_sig = $valid_channel && $valid_hash && hash_equals( RecipientHasher::signature( $channel, $hash ), $signature );

		if ( ! $valid_channel || ! $valid_hash || ! $valid_sig ) {
			wp_die(
				esc_html__( 'لینک لغو اشتراک معتبر نیست یا منقضی شده است.', 'parsishop-crm' ),
				esc_html__( 'درخواست نامعتبر', 'parsishop-crm' ),
				array( 'response' => 400 )
			);
		}

		$success = ConsentRepository::set_unsubscribed_by_hash( $channel, $hash, 'unsubscribe_link' );
		$message = $success
			? __( 'درخواست شما ثبت شد. دیگر پیام‌های بازاریابی این کانال را دریافت نخواهید کرد.', 'parsishop-crm' )
			: __( 'ثبت لغو اشتراک با خطا مواجه شد. لطفاً دوباره تلاش کنید.', 'parsishop-crm' );

		wp_die(
			'<div dir="rtl" style="max-width:620px;margin:40px auto;padding:28px;font:16px/2 sans-serif;background:#fff;border:1px solid #ddd;border-radius:12px;text-align:center">' . esc_html( $message ) . '</div>',
			esc_html__( 'مدیریت اشتراک پیام‌های بازاریابی', 'parsishop-crm' ),
			array( 'response' => $success ? 200 : 500, 'back_link' => true )
		);
	}
}
