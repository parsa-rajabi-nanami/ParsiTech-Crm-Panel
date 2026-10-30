<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Repositories;

use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Support\PhoneNormalizer;
use ParsiShop\CRM\Support\RecipientHasher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConsentRepository {
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'parsishop_crm_marketing_consents';
	}

	public static function is_consented( string $channel, string $recipient ): bool {
		global $wpdb;
		$hash = RecipientHasher::hash( $channel, $recipient );
		if ( '' === $hash ) {
			return false;
		}

		$table = self::table();
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE channel = %s AND recipient_hash = %s LIMIT 1",
				sanitize_key( $channel ),
				$hash
			)
		);

		return 'subscribed' === $status;
	}

	public static function set_consent( string $channel, string $recipient, bool $consented, string $source = 'checkout' ): bool {
		global $wpdb;
		$channel = sanitize_key( $channel );
		$hash    = RecipientHasher::hash( $channel, $recipient );

		if ( '' === $hash ) {
			return false;
		}

		$now    = DateHelper::now_utc();
		$status = $consented ? 'subscribed' : 'unsubscribed';
		$mask   = self::mask( $channel, $recipient );
		$table  = self::table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (channel, recipient_hash, recipient_masked, status, source, consented_at, updated_at)
				VALUES (%s, %s, %s, %s, %s, NULLIF(%s, ''), %s)
				ON DUPLICATE KEY UPDATE
				recipient_masked = VALUES(recipient_masked),
				status = VALUES(status),
				source = VALUES(source),
				consented_at = VALUES(consented_at),
				updated_at = VALUES(updated_at)",
				$channel,
				$hash,
				$mask,
				$status,
				sanitize_key( $source ),
				$consented ? $now : '',
				$now
			)
		);

		if ( false === $result ) {
			return false;
		}

		if ( $consented ) {
			OptoutRepository::remove_optout( $channel, $recipient );
		} else {
			OptoutRepository::add_optout( $channel, $recipient, $source );
		}

		return true;
	}

	public static function set_unsubscribed_by_hash( string $channel, string $recipient_hash, string $source = 'unsubscribe_link' ): bool {
		global $wpdb;
		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, array( 'sms', 'email' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $recipient_hash ) ) {
			return false;
		}

		$now   = DateHelper::now_utc();
		$table = self::table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (channel, recipient_hash, recipient_masked, status, source, consented_at, updated_at)
				VALUES (%s, %s, %s, 'unsubscribed', %s, NULL, %s)
				ON DUPLICATE KEY UPDATE status = 'unsubscribed', source = VALUES(source), updated_at = VALUES(updated_at)",
				$channel,
				$recipient_hash,
				'Hidden',
				sanitize_key( $source ),
				$now
			)
		);

		if ( false === $result ) {
			return false;
		}

		return OptoutRepository::add_optout_by_hash( $channel, $recipient_hash, 'Hidden', $source );
	}

	private static function mask( string $channel, string $recipient ): string {
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
