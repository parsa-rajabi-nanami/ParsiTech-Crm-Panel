<?php

declare(strict_types=1);

namespace ParsiReach\Providers;

use ParsiReach\Providers\Contracts\SmsProviderInterface;
use ParsiReach\Providers\Contracts\SmsResult;
use ParsiReach\Support\PhoneNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** FarazSMS public API (iranpayamak.com). */
final class FarazSmsProvider implements SmsProviderInterface {
	private const BASE_URL = 'https://api.iranpayamak.com/ws/v1';
	private const TIMEOUT  = 15;

	public function __construct(
		private readonly string $api_key,
		private readonly string $originator
	) {}

	public function get_provider_slug(): string {
		return 'farazsms';
	}

	public function send_pattern( string $recipient, string $pattern_code, array $values ): SmsResult {
		$phone       = PhoneNormalizer::normalize( $recipient );
		$line_number = self::normalize_digits( $this->originator );
		if ( '' === trim( $this->api_key ) || '' === $line_number || '' === $phone || '' === trim( $pattern_code ) ) {
			return $this->configuration_error();
		}

		$attributes = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( is_scalar( $value ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key ) ) {
				$attributes[ $key ] = (string) $value;
			}
		}
		if ( empty( $attributes ) ) {
			return $this->configuration_error();
		}

		$payload = array(
			'code'          => trim( $pattern_code ),
			'attributes'    => (object) $attributes,
			'recipient'     => $phone,
			'line_number'   => $line_number,
			'number_format' => 'english',
		);

		return $this->dispatch_request( '/sms/pattern', $payload );
	}

	public function get_credit(): array {
		if ( '' === trim( $this->api_key ) ) {
			return array(
				'success'     => false,
				'credit_toman'=> 0.0,
				'message'     => __( 'کلید API وارد نشده است. ابتدا کلید را ذخیره کنید.', 'parsireach' ),
			);
		}

		$response = wp_remote_get(
			self::BASE_URL . '/account/balance',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'      => false,
				'credit_toman' => 0.0,
				'message'      => 'خطای ارتباط با سرور پیامک: ' . $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
		$amount    = is_array( $decoded ) && is_array( $decoded['data'] ?? null )
			? ( $decoded['data']['balance_amount'] ?? $decoded['data']['balanceAmount'] ?? null )
			: null;

		if ( $http_code >= 200 && $http_code < 300 && is_array( $decoded ) && 'success' === ( $decoded['status'] ?? '' ) && is_numeric( $amount ) ) {
			return array(
				'success'      => true,
				'credit_toman' => (float) $amount,
				'message'      => __( 'اتصال با موفقیت برقرار شد.', 'parsireach' ),
			);
		}

		return array(
			'success'      => false,
			'credit_toman' => 0.0,
			'message'      => $this->extract_error( $decoded, $http_code ),
		);
	}

	/** @param array<string, mixed> $payload */
	private function dispatch_request( string $endpoint, array $payload ): SmsResult {
		$response = wp_remote_post(
			self::BASE_URL . $endpoint,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new SmsResult(
				success: false,
				status: 'failed',
				error_code: 'NETWORK_ERROR',
				summary: self::truncate( 'خطای شبکه: ' . $response->get_error_message(), 250 ),
				is_retryable: true
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_ok    = is_array( $decoded ) && 'success' === ( $decoded['status'] ?? '' );

		if ( $http_code >= 200 && $http_code < 300 && $api_ok ) {
			$data       = $decoded['data'] ?? null;
			$message_id = is_scalar( $data ) && '' !== (string) $data && '0' !== (string) $data ? (string) $data : null;
			return new SmsResult(
				success: true,
				status: 'sent',
				message_id: $message_id,
				summary: self::response_message( $decoded ) ?: 'درخواست ارسال در پنل فراز ثبت شد.',
				is_retryable: false
			);
		}

		$error      = $this->extract_error( $decoded, $http_code );
		$retryable  = in_array( $http_code, array( 408, 425, 429 ), true ) || $http_code >= 500;
		return new SmsResult(
			success: false,
			status: 'failed',
			error_code: 'HTTP_' . $http_code,
			summary: self::truncate( $error, 250 ),
			is_retryable: $retryable
		);
	}

	/** @param mixed $decoded */
	private function extract_error( $decoded, int $http_code ): string {
		$message = '';
		if ( is_array( $decoded ) ) {
			$message = $decoded['messages'] ?? $decoded['message'] ?? $decoded['errors'] ?? '';
			if ( '' === $message && is_array( $decoded['data'] ?? null ) ) {
				$message = $decoded['data']['message'] ?? $decoded['data']['errors'] ?? '';
			}
		}
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
		}
		$message = sanitize_text_field( (string) $message );
		return '' !== $message ? $message : 'خطای وب‌سرویس فراز با کد HTTP ' . $http_code;
	}

	/** @param array<string, mixed> $decoded */
	private static function response_message( array $decoded ): string {
		$message = $decoded['messages'] ?? $decoded['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
		}
		return sanitize_text_field( (string) $message );
	}

	private static function normalize_digits( string $value ): string {
		$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic_digits  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$latin_digits   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$value          = str_replace( $persian_digits, $latin_digits, $value );
		$value          = str_replace( $arabic_digits, $latin_digits, $value );
		return preg_replace( '/\D/u', '', $value ) ?? '';
	}

	private static function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	private function configuration_error(): SmsResult {
		return new SmsResult(
			success: false,
			status: 'failed',
			error_code: 'INVALID_CONFIG',
			summary: 'کلید API، شماره خط فرستنده، شماره گیرنده یا کد پترن معتبر نیست.',
			is_retryable: false
		);
	}

	/** @return array<string, string> */
	private function get_headers(): array {
		return array(
			'Api-Key'      => trim( $this->api_key ),
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
	}
}
