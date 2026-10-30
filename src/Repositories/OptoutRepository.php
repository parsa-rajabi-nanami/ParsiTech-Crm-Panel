<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Repositories;

use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Support\RecipientHasher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OptoutRepository {
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'parsishop_crm_optouts';
	}

	public static function is_opted_out( string $channel, string $recipient ): bool {
		global $wpdb;

		$channel = sanitize_key( $channel );
		$new_hash = RecipientHasher::hash( $channel, $recipient );
		if ( '' === $new_hash ) {
			return true;
		}

		$hashes = array( $new_hash );
		// Read pre-2.0 suppression hashes during the migration window.
		if ( 'sms' === $channel ) {
			$legacy = PhoneNormalizer::hash( $recipient );
		} else {
			$email  = strtolower( trim( sanitize_email( $recipient ) ) );
			$legacy = is_email( $email ) ? hash_hmac( 'sha256', $email, wp_salt( 'auth' ) . 'parsishop_email_hash' ) : '';
		}
		if ( '' !== $legacy ) {
			$hashes[] = $legacy;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$table        = self::table();
		$prepared     = $wpdb->prepare(
			"SELECT id FROM {$table} WHERE channel = %s AND recipient_hash IN ({$placeholders}) LIMIT 1",
			...array_merge( array( $channel ), $hashes )
		);
		$optout = $wpdb->get_var( $prepared );
		if ( '' !== (string) $wpdb->last_error || null !== $optout ) {
			return true; // Fail closed if the suppression lookup cannot be trusted.
		}

		$consent_table = $wpdb->prefix . 'parsishop_crm_marketing_consents';
		$consent_sql   = $wpdb->prepare(
			"SELECT id FROM {$consent_table} WHERE channel = %s AND status = 'unsubscribed' AND recipient_hash IN ({$placeholders}) LIMIT 1",
			...array_merge( array( $channel ), $hashes )
		);
		$unsubscribed = $wpdb->get_var( $consent_sql );
		return '' !== (string) $wpdb->last_error || null !== $unsubscribed;
	}

	public static function add_optout( string $channel, string $recipient, string $source = 'user_link' ): bool {
		$hash = RecipientHasher::hash( $channel, $recipient );
		if ( '' === $hash ) {
			return false;
		}
		return self::add_optout_by_hash( $channel, $hash, self::mask_recipient( $channel, $recipient ), $source );
	}

	public static function add_optout_by_hash( string $channel, string $hash, string $masked = 'Hidden', string $source = 'user_link' ): bool {
		global $wpdb;
		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, array( 'sms', 'email' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return false;
		}

		$table = self::table();
		$res   = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (channel, recipient_hash, recipient_masked, source, created_at)
				VALUES (%s, %s, %s, %s, %s)",
				$channel,
				$hash,
				sanitize_text_field( $masked ),
				sanitize_key( $source ),
				DateHelper::now_utc()
			)
		);

		return false !== $res;
	}

	public static function remove_optout( string $channel, string $recipient ): void {
		global $wpdb;
		$channel = sanitize_key( $channel );
		$hash    = RecipientHasher::hash( $channel, $recipient );
		if ( '' === $hash ) {
			return;
		}
		$hashes = array( $hash );
		if ( 'sms' === $channel ) {
			$legacy = PhoneNormalizer::hash( $recipient );
		} else {
			$email  = strtolower( trim( sanitize_email( $recipient ) ) );
			$legacy = is_email( $email ) ? hash_hmac( 'sha256', $email, wp_salt( 'auth' ) . 'parsishop_email_hash' ) : '';
		}
		if ( '' !== $legacy ) {
			$hashes[] = $legacy;
		}
		$table = self::table();
		$marks = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$sql   = $wpdb->prepare( "DELETE FROM {$table} WHERE channel = %s AND recipient_hash IN ({$marks})", ...array_merge( array( $channel ), $hashes ) );
		$wpdb->query( $sql );
	}

	private static function mask_recipient( string $channel, string $recipient ): string {
		if ( 'sms' === $channel ) {
			return PhoneNormalizer::mask( $recipient );
		}
		$email = strtolower( trim( sanitize_email( $recipient ) ) );
		$parts = explode( '@', $email );
		if ( 2 !== count( $parts ) ) {
			return 'INVALID_EMAIL';
		}
		return substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
	}
}
