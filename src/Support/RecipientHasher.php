<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stable keyed hashes used for consent and suppression records. */
final class RecipientHasher {
	public static function hash( string $channel, string $recipient ): string {
		$channel = sanitize_key( $channel );

		if ( 'sms' === $channel ) {
			$normalized = PhoneNormalizer::normalize( $recipient );
			if ( '' === $normalized ) {
				return '';
			}
			$recipient = $normalized;
		} elseif ( 'email' === $channel ) {
			$recipient = strtolower( trim( sanitize_email( $recipient ) ) );
			if ( ! is_email( $recipient ) ) {
				return '';
			}
		} else {
			return '';
		}

		$secret = self::secret();
		return '' !== $secret ? hash_hmac( 'sha256', $channel . '|' . $recipient, $secret ) : '';
	}

	public static function signature( string $channel, string $recipient_hash ): string {
		$secret = self::secret();
		return '' !== $secret ? hash_hmac( 'sha256', 'unsubscribe|' . $channel . '|' . $recipient_hash, $secret ) : '';
	}

	private static function secret(): string {
		$option_name = 'parsishop_crm_recipient_hash_secret';
		$secret      = (string) get_option( $option_name, '' );

		if ( '' === $secret ) {
			$generated = wp_generate_password( 64, true, true );
			if ( add_option( $option_name, $generated, '', false ) ) {
				$secret = $generated;
			} else {
				$secret = (string) get_option( $option_name, '' );
			}
		}

		return $secret;
	}
}
