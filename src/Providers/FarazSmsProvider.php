<?php

declare(strict_types=1);

namespace ParsiReach\Providers;

use ParsiReach\Providers\Contracts\SmsProviderInterface;
use ParsiReach\Providers\Contracts\SmsResult;
use ParsiReach\Support\PhoneNormalizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** IPPanel Edge API (the current REST interface used by FarazSMS/IPPanel). */
final class FarazSmsProvider implements SmsProviderInterface {
	private const BASE_URL = 'https://edge.ippanel.com/v1';
	private const TIMEOUT  = 15;

	public function __construct(
		private readonly string $api_key,
		private readonly string $originator
	) {}

	public function get_provider_slug(): string {
		return 'ippanel';
	}

	public function send_pattern( string $recipient, string $pattern_code, array $values ): SmsResult {
		$e164       = PhoneNormalizer::to_e164( $recipient );
		$originator = PhoneNormalizer::normalize_originator( $this->originator );
		if ( '' === $this->api_key || '' === $originator || '' === $e164 || '' === trim( $pattern_code ) ) {
			return $this->configuration_error();
		}

		$clean_values = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( is_scalar( $value ) && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key ) ) {
				$clean_values[ $key ] = (string) $value;
			}
		}

		$payload = array(
			'sending_type' => 'pattern',
			'from_number'  => $originator,
			'code'         => trim( $pattern_code ),
			'recipients'   => array( $e164 ),
			'params'       => (object) $clean_values,
		);

		return $this->dispatch_request( '/api/send', $payload );
	}

	public function get_credit(): array {
		if ( '' === $this->api_key ) {
			return array(
				'success'    => false,
				'credit_irr' => 0.0,
				'message'    => __( 'کلید API وارد نشده است. ابتدا کلید را ذخیره کنید.', 'parsireach' ),
			);
		}

		$response = wp_remote_get(
			self::BASE_URL . '/api/payment/credit/mine',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->get_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'    => false,
				'credit_irr' => 0.0,
				'message'    => 'خطای ارتباط با سرور پیامک: ' . $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$decoded   = json_decode( wp_remote_retrieve_body( $response ), true );
		$api_ok    = ! empty( $decoded['meta']['status'] );

		if ( $http_code >= 200 && $http_code < 300 && $api_ok && isset( $decoded['data']['credit'] ) ) {
			return array(
				'success'    => true,
				'credit_irr' => (float) $decoded['data']['credit'],
				'message'    => __( 'اتصال با موفقیت برقرار شد.', 'parsireach' ),
			);
		}

		return array(
			'success'    => false,
			'credit_irr' => 0.0,
			'message'    => $this->extract_error( $decoded, $http_code ),
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
		$api_ok    = ! empty( $decoded['meta']['status'] );

		if ( $http_code >= 200 && $http_code < 300 && $api_ok ) {
			$ids        = $decoded['data']['message_outbox_ids'] ?? array();
			$message_id = is_array( $ids ) && isset( $ids[0] ) ? (string) $ids[0] : null;
			return new SmsResult(
				success: true,
				status: 'sent',
				message_id: $message_id,
				summary: null !== $message_id ? 'درخواست ارسال ثبت شد (شناسه: ' . $message_id . ')' : 'درخواست ارسال در پنل ثبت شد.',
				is_retryable: false
			);
		}

		$error = $this->extract_error( $decoded, $http_code );
		$retryable = in_array( $http_code, array( 408, 425, 429 ), true ) || $http_code >= 500;

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
			if ( isset( $decoded['meta']['message'] ) ) {
				$message = $decoded['meta']['message'];
			} elseif ( isset( $decoded['message'] ) ) {
				$message = $decoded['message'];
			} elseif ( is_array( $decoded['error'] ?? null ) ) {
				$message = $decoded['error']['message'] ?? '';
			} elseif ( is_string( $decoded['error'] ?? null ) ) {
				$message = $decoded['error'];
			}
		}
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
		}
		$message = sanitize_text_field( (string) $message );
		return '' !== $message ? $message : 'خطای وب‌سرویس با کد HTTP ' . $http_code;
	}


	private static function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	private function configuration_error(): SmsResult {
		return new SmsResult(
			success: false,
			status: 'failed',
			error_code: 'INVALID_CONFIG',
			summary: 'کلید API، شماره خط فرستنده، شماره گیرنده یا اطلاعات پترن معتبر نیست.',
			is_retryable: false
		);
	}

	/** @return array<string, string> */
	private function get_headers(): array {
		return array(
			'Authorization' => $this->api_key,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}
}
