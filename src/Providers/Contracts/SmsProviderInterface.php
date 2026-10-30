<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Providers\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SmsProviderInterface {

	/**
	 * @param string                $recipient
	 * @param string                $pattern_code
	 * @param array<string, scalar> $values
	 */
	public function send_pattern( string $recipient, string $pattern_code, array $values ): SmsResult;

	public function send_text( string $recipient, string $message ): SmsResult;

	/**
	 * @return array{success: bool, credit_irr: float, message: string}
	 */
	public function get_credit(): array;

	public function get_provider_slug(): string;
}
