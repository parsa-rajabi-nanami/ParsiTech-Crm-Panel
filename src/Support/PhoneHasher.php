<?php

declare(strict_types=1);

namespace ParsiReach\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stable keyed phone hashes bind queued jobs to their order recipient. */
final class PhoneHasher {
	public static function hash( string $phone ): string {
		$normalized = PhoneNormalizer::normalize( $phone );
		if ( '' === $normalized ) {
			return '';
		}

		$secret = (string) get_option( 'parsireach_phone_hash_secret', '' );
		if ( '' === $secret ) {
			$generated = wp_generate_password( 64, true, true );
			if ( add_option( 'parsireach_phone_hash_secret', $generated, '', false ) ) {
				$secret = $generated;
			} else {
				$secret = (string) get_option( 'parsireach_phone_hash_secret', '' );
			}
		}

		return '' === $secret ? '' : hash_hmac( 'sha256', $normalized, $secret );
	}
}
