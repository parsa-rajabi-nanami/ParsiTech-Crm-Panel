<?php

declare(strict_types=1);

namespace ParsiReach\Providers\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmsResult {

	public function __construct(
		public readonly bool $success,
		public readonly string $status,
		public readonly ?string $message_id = null,
		public readonly ?string $error_code = null,
		public readonly string $summary = '',
		public readonly bool $is_retryable = false
	) {}
}
