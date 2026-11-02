<?php

declare(strict_types=1);

namespace ParsiReach\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts sensitive credentials (API Keys) using Libsodium
 * with an OpenSSL AES-256-GCM fallback, keyed from WordPress salts.
 * Never falls back to unencrypted/base64 storage.
 */
final class Crypto {

	private const PREFIX_SODIUM  = 'v1:sodium:';
	private const PREFIX_OPENSSL = 'v1:openssl:';

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::derive_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::PREFIX_SODIUM . base64_encode( $nonce . $ciphertext );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv         = random_bytes( 12 );
			$tag        = '';
			$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false !== $ciphertext ) {
				return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $ciphertext );
			}
		}

		return '';
	}

	public static function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}

		$key = self::derive_key();

		if ( str_starts_with( $payload, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$decrypted  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

			return false !== $decrypted ? $decrypted : '';
		}

		if ( str_starts_with( $payload, self::PREFIX_OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $payload, strlen( self::PREFIX_OPENSSL ) ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv         = substr( $raw, 0, 12 );
			$tag        = substr( $raw, 12, 16 );
			$ciphertext = substr( $raw, 28 );
			$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			return false !== $decrypted ? $decrypted : '';
		}

		return '';
	}

	public static function mask( string $secret ): string {
		$len = strlen( $secret );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 8 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', 12 ) . substr( $secret, -4 );
	}

	private static function derive_key(): string {
		$salt_material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $salt_material, 32, 'parsireach-encryption-v1' );
	}
}
