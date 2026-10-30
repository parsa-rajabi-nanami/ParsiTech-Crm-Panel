<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Providers;

use ParsiShop\CRM\Providers\Contracts\SmsProviderInterface;
use ParsiShop\CRM\Providers\Contracts\SmsResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SandboxSmsProvider implements SmsProviderInterface {

	public function get_provider_slug(): string {
		return 'sandbox';
	}

	public function send_pattern( string $recipient, string $pattern_code, array $values ): SmsResult {
		$keys = implode( ', ', array_keys( $values ) );
		return new SmsResult(
			success: true,
			status: 'sandbox',
			message_id: 'SANDBOX-' . wp_generate_password( 8, false ),
			summary: sprintf( 'حالت تست (پترن: %s | متغیرها: %s)', $pattern_code, $keys ),
			is_retryable: false
		);
	}

	public function send_text( string $recipient, string $message ): SmsResult {
		return new SmsResult(
			success: true,
			status: 'sandbox',
			message_id: 'SANDBOX-' . wp_generate_password( 8, false ),
			summary: sprintf( 'حالت تست (پیام متنی - طول: %d کاراکتر)', ( function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message ) ) ),
			is_retryable: false
		);
	}

	public function get_credit(): array {
		return array(
			'success'    => false,
			'credit_irr' => 0.0,
			'message'    => __( 'حالت شبیه‌سازی فعال است؛ موجودی واقعی پنل استعلام نمی‌شود.', 'parsishop-crm' ),
		);
	}
}
