<?php
/**
 * Local encryption for customer-owned API credentials.
 *
 * @package MomentumLab\MediaFolder
 */

namespace MomentumLab\MediaFolder;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets at rest with keys derived from the site's WordPress salts.
 */
final class Secrets {

	/**
	 * Encrypt a plaintext value. Empty values stay empty.
	 *
	 * @param string $plain Plaintext secret.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding encrypted binary for option storage.
			return 's1:' . base64_encode( $nonce . $cipher );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$out = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $out ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding encrypted binary for option storage.
		return 'o1:' . base64_encode( $iv . $tag . $out );
	}

	/**
	 * Decrypt a stored value. Invalid or stale ciphertext fails closed.
	 *
	 * @param string $stored Encoded ciphertext.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );

		if ( str_starts_with( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted binary stored by this class.
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( str_starts_with( $stored, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted binary stored by this class.
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		return '';
	}
}
