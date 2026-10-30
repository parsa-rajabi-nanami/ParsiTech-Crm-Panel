<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PhoneNormalizer {
	public static function normalize( string $raw_phone ): string {
		$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic_digits  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$latin_digits   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

		$phone = str_replace( $persian_digits, $latin_digits, $raw_phone );
		$phone = str_replace( $arabic_digits, $latin_digits, $phone );
		$phone = preg_replace( '/[^\d+]/u', '', $phone ) ?? '';
		$phone = ltrim( $phone, '+' );

		if ( str_starts_with( $phone, '0098' ) ) {
			$phone = '0' . substr( $phone, 4 );
		} elseif ( str_starts_with( $phone, '98' ) && 12 === strlen( $phone ) ) {
			$phone = '0' . substr( $phone, 2 );
		} elseif ( 10 === strlen( $phone ) && str_starts_with( $phone, '9' ) ) {
			$phone = '0' . $phone;
		}

		return self::is_valid( $phone ) ? $phone : '';
	}

	public static function to_e164( string $raw_phone ): string {
		$normalized = self::normalize( $raw_phone );
		return '' === $normalized ? '' : '+98' . substr( $normalized, 1 );
	}


	/** Normalize an Iranian sender line to E.164; sender IDs may be fixed/short codes. */
	public static function normalize_originator( string $raw_number ): string {
		$persian_digits = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$arabic_digits  = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$latin_digits   = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

		$value = str_replace( $persian_digits, $latin_digits, $raw_number );
		$value = str_replace( $arabic_digits, $latin_digits, $value );
		$value = preg_replace( '/[^\d+]/u', '', $value ) ?? '';

		if ( str_starts_with( $value, '00' ) ) {
			$value = '+' . substr( $value, 2 );
		} elseif ( str_starts_with( $value, '98' ) ) {
			$value = '+' . $value;
		} elseif ( str_starts_with( $value, '0' ) ) {
			$value = '+98' . substr( $value, 1 );
		} elseif ( ! str_starts_with( $value, '+' ) && '' !== $value ) {
			// IPPanel sender short-codes are commonly entered without the +98 prefix.
			$value = '+98' . $value;
		}

		return 1 === preg_match( '/^\+[1-9]\d{6,14}$/', $value ) ? $value : '';
	}

	public static function is_valid( string $phone ): bool {
		return 1 === preg_match( '/^09\d{9}$/', $phone );
	}

	public static function mask( string $phone ): string {
		$normalized = self::normalize( $phone );
		if ( '' === $normalized ) {
			return 'INVALID';
		}
		return substr( $normalized, 0, 4 ) . '***' . substr( $normalized, 7, 4 );
	}

	public static function hash( string $phone ): string {
		$normalized = self::normalize( $phone );
		if ( '' === $normalized ) {
			return '';
		}
		return hash_hmac( 'sha256', $normalized, wp_salt( 'auth' ) . 'parsishop_phone_hash' );
	}
}
