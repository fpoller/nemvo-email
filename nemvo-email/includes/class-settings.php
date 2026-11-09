<?php
/**
 * Settings.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings in one option, plus constants and environment variables.
 * Provider credentials live with their provider (see Providers\Google).
 */
class Settings {

	const OPTION = 'nemvo_email_settings';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'provider'          => 'google',
			// Empty: the connected account's own address.
			'from_email'        => '',
			// Empty: the site title.
			'from_name'         => '',
			// Gmail only sends as the account and its "Send mail as" addresses, so other senders are replaced.
			'force_from_email'  => 1,
			'force_from_name'   => 0,
			// A replaced sender (a contact form visitor, say) becomes the Reply-To.
			'reply_to_original' => 1,
			'pause'             => 0,
			'log_retention'     => 90,
			'log_content'       => 0,
			// Empty: the provider's default DKIM selector ("google").
			'dkim_selector'     => '',
			// Setup progress, for the guide.
			'sender_saved'      => 0,
			'test_sent'         => 0,
			'setup_done'        => 0,
			'imported_from'     => '',
		);
	}

	/**
	 * All settings, or one.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return self::$cache[ $key ] ?? null;
	}

	/**
	 * Store changed values (already sanitized).
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$all = array_merge( self::get(), $values );
		update_option( self::OPTION, $all, true );
		self::$cache = null;
	}

	/**
	 * Forget the request cache (after another process changed the option).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize the sender settings form.
	 *
	 * @param array $input Raw input (unslashed).
	 * @return array
	 */
	public static function sanitize_sender( array $input ) {
		$email = sanitize_email( (string) ( $input['from_email'] ?? '' ) );
		return array(
			'from_email'        => is_email( $email ) ? strtolower( $email ) : '',
			'from_name'         => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'force_from_email'  => empty( $input['force_from_email'] ) ? 0 : 1,
			'force_from_name'   => empty( $input['force_from_name'] ) ? 0 : 1,
			'reply_to_original' => empty( $input['reply_to_original'] ) ? 0 : 1,
		);
	}

	/**
	 * Sanitize the general settings form.
	 *
	 * @param array $input Raw input (unslashed).
	 * @return array
	 */
	public static function sanitize_general( array $input ) {
		return array(
			'pause'         => empty( $input['pause'] ) ? 0 : 1,
			'log_retention' => max( 1, min( 3650, absint( $input['log_retention'] ?? 90 ) ) ),
			'log_content'   => empty( $input['log_content'] ) ? 0 : 1,
		);
	}

	/**
	 * Sanitize a DKIM selector.
	 *
	 * @param string $selector Selector.
	 * @return string '' for the provider's default.
	 */
	public static function sanitize_selector( $selector ) {
		$selector = strtolower( trim( sanitize_text_field( (string) $selector ) ) );
		return preg_match( '/^[a-z0-9][a-z0-9._-]{0,62}$/', $selector ) ? $selector : '';
	}

	/**
	 * Sending paused: by the setting or by NEMVO_EMAIL_PAUSE (e.g. on a staging copy).
	 *
	 * @return bool
	 */
	public static function paused() {
		$env = self::env( 'NEMVO_EMAIL_PAUSE' );
		if ( '' !== $env ) {
			return filter_var( $env, FILTER_VALIDATE_BOOLEAN );
		}
		return (bool) self::get( 'pause' );
	}

	/**
	 * Whether the pause is set by a constant or environment variable.
	 *
	 * @return bool
	 */
	public static function pause_locked() {
		return '' !== self::env( 'NEMVO_EMAIL_PAUSE' );
	}

	/**
	 * A wp-config.php constant or environment variable. NAME_FILE points at a
	 * file holding the value (Docker secrets).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function env( $name ) {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}
		$value = getenv( $name );
		if ( false === $value || '' === $value ) {
			$file = getenv( $name . '_FILE' );
			if ( $file && is_readable( $file ) ) {
				return trim( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local secrets file.
			}
			return '';
		}
		return trim( (string) $value );
	}
}
