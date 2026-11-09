<?php
/**
 * Encryption of stored secrets and signing of OAuth state.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets (OAuth tokens, the client secret) are encrypted with
 * XSalsa20-Poly1305 (sodium's secretbox; WordPress bundles sodium_compat for
 * servers without the extension). The key comes from NEMVO_EMAIL_KEY when
 * defined, otherwise from the WordPress salts in wp-config.php, so a copy of
 * the database alone can't be decrypted. Changing the salts means connecting
 * Google again.
 */
class Crypto {

	const PREFIX = 'nve1:';

	/**
	 * The 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		$secret = Settings::env( 'NEMVO_EMAIL_KEY' );
		if ( '' === $secret ) {
			$secret = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return hash_hmac( 'sha256', 'nemvo-email-secrets', $secret, true );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Prefixed base64, or '' for ''.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encrypted binary data.
	}

	/**
	 * Decrypt a string from encrypt().
	 *
	 * @param string $value Encrypted value.
	 * @return string|null Plain text, '' for an empty value, null when it can't be decrypted.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- encrypted binary data.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * Sign data for a round trip through the browser (OAuth state).
	 *
	 * @param array $data Data; include "exp" (Unix time).
	 * @return string
	 */
	public static function sign( array $data ) {
		$payload = self::base64url( (string) wp_json_encode( $data ) );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) . 'nemvo-email-state' );
	}

	/**
	 * Verify signed data.
	 *
	 * @param string $signed Value from sign().
	 * @return array|null Data, or null when tampered with or expired.
	 */
	public static function verify( $signed ) {
		$parts = explode( '.', (string) $signed );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) . 'nemvo-email-state' ), $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) self::base64url_decode( $parts[0] ), true );
		if ( ! is_array( $data ) || empty( $data['exp'] ) || (int) $data['exp'] < time() ) {
			return null;
		}
		return $data;
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $data Binary data.
	 * @return string
	 */
	public static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth PKCE and state encoding.
	}

	/**
	 * Decode URL-safe base64.
	 *
	 * @param string $data Encoded data.
	 * @return string|false
	 */
	public static function base64url_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		return base64_decode( $data . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT and state decoding.
	}
}
