<?php
/**
 * Authenticated encryption for plugin credentials.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Crypto {
	private const CONTEXT = 'skypay-woocommerce-credentials-v1';

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, self::CONTEXT, $nonce, $key );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext requires a storage-safe encoding.
			return 's1:' . base64_encode( $nonce . $ciphertext );
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new RuntimeException( 'Authenticated encryption is unavailable.' );
		}

		$nonce = random_bytes( 12 );
		$tag   = '';
		$data  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::CONTEXT );
		if ( false === $data ) {
			throw new RuntimeException( 'Credential encryption failed.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext requires a storage-safe encoding.
		return 'o1:' . base64_encode( $nonce . $tag . $data );
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		try {
			if ( str_starts_with( $stored, 's1:' ) ) {
				return self::decrypt_sodium( substr( $stored, 3 ) );
			}
			if ( str_starts_with( $stored, 'o1:' ) ) {
				return self::decrypt_openssl( substr( $stored, 3 ) );
			}
		} catch ( Throwable $error ) {
			return '';
		}

		return '';
	}

	private static function decrypt_sodium( string $encoded ): string {
		if ( ! function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plugin's authenticated ciphertext envelope.
		$decoded = base64_decode( $encoded, true );
		$length  = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $decoded || strlen( $decoded ) <= $length ) {
			return '';
		}

		$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
			substr( $decoded, $length ),
			self::CONTEXT,
			substr( $decoded, 0, $length ),
			self::key()
		);
		return false === $plaintext ? '' : $plaintext;
	}

	private static function decrypt_openssl( string $encoded ): string {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plugin's authenticated ciphertext envelope.
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded || strlen( $decoded ) <= 28 ) {
			return '';
		}

		$plaintext = openssl_decrypt(
			substr( $decoded, 28 ),
			'aes-256-gcm',
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $decoded, 0, 12 ),
			substr( $decoded, 12, 16 ),
			self::CONTEXT
		);
		return false === $plaintext ? '' : $plaintext;
	}

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . self::CONTEXT, true );
	}
}
