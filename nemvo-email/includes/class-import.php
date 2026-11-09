<?php
/**
 * Import from WP Mail SMTP.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

use NemvoEmail\Providers\Google;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WP Mail SMTP's settings (its "wp_mail_smtp" option, or its WPMS_*
 * constants when WPMS_ON is true) and takes over the sender, "Do not send",
 * and the Gmail mailer's OAuth client and authorization. The authorization
 * keeps working because it belongs to the site's own OAuth client; it was
 * granted for full Gmail access ("https://mail.google.com/"), which includes
 * sending.
 */
class Import {

	const PLUGINS = array( 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php' );

	/**
	 * WP Mail SMTP's configuration, or null when there is none worth importing.
	 *
	 * @return array|null
	 */
	public static function wp_mail_smtp() {
		$option    = get_option( 'wp_mail_smtp' );
		$constants = defined( 'WPMS_ON' ) && true === constant( 'WPMS_ON' );
		if ( ! is_array( $option ) && ! $constants ) {
			return null;
		}
		$option = is_array( $option ) ? $option : array();
		$get    = static function ( $group, $key, $constant ) use ( $option, $constants ) {
			if ( $constants && defined( $constant ) ) {
				return constant( $constant );
			}
			return $option[ $group ][ $key ] ?? null;
		};
		$bool   = static function ( $value ) {
			return true === $value || 1 === $value || in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
		};

		$gmail     = is_array( $option['gmail'] ?? null ) ? $option['gmail'] : array();
		$mailer    = (string) $get( 'mail', 'mailer', 'WPMS_MAILER' );
		$refresh   = is_string( $gmail['refresh_token'] ?? null ) ? $gmail['refresh_token'] : '';
		$client    = array(
			'client_id'     => trim( (string) $get( 'gmail', 'client_id', 'WPMS_GMAIL_CLIENT_ID' ) ),
			'client_secret' => trim( (string) $get( 'gmail', 'client_secret', 'WPMS_GMAIL_CLIENT_SECRET' ) ),
		);
		$one_click = ! empty( $gmail['one_click_setup_enabled'] );

		$found = array(
			'active'           => self::active_plugin(),
			'mailer'           => '' !== $mailer ? $mailer : 'mail',
			'from_email'       => strtolower( trim( (string) $get( 'mail', 'from_email', 'WPMS_MAIL_FROM' ) ) ),
			'from_name'        => trim( (string) $get( 'mail', 'from_name', 'WPMS_MAIL_FROM_NAME' ) ),
			'force_from_email' => $bool( $get( 'mail', 'from_email_force', 'WPMS_MAIL_FROM_FORCE' ) ),
			'force_from_name'  => $bool( $get( 'mail', 'from_name_force', 'WPMS_MAIL_FROM_NAME_FORCE' ) ),
			'do_not_send'      => $bool( $get( 'general', 'do_not_send', 'WPMS_DO_NOT_SEND' ) ),
			'smtp_host'        => trim( (string) $get( 'smtp', 'host', 'WPMS_SMTP_HOST' ) ),
			'constants'        => $constants,
			'gmail'            => array(
				'client'    => '' !== $client['client_id'] && '' !== $client['client_secret'],
				'connected' => '' !== $refresh && ! $one_click,
				'one_click' => $one_click,
				'email'     => strtolower( (string) ( $gmail['user_details']['email'] ?? '' ) ),
			),
		);

		// Untouched defaults (PHP mail(), the admin address) aren't worth importing.
		if ( 'mail' === $found['mailer'] && ! $found['gmail']['client'] && ! $found['do_not_send'] ) {
			return null;
		}
		return $found;
	}

	/**
	 * The active WP Mail SMTP plugin file, or ''.
	 *
	 * @return string
	 */
	public static function active_plugin() {
		foreach ( self::PLUGINS as $file ) {
			if ( in_array( $file, (array) get_option( 'active_plugins', array() ), true ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Import everything that applies.
	 *
	 * @return array|\WP_Error {
	 *     @type string[]           $imported   What was imported (labels).
	 *     @type true|\WP_Error|null $connection Result of checking an imported Gmail connection.
	 * }
	 */
	public static function run() {
		$found = self::wp_mail_smtp();
		if ( ! $found ) {
			return new \WP_Error( 'nothing', __( 'WP Mail SMTP has no settings to import.', 'nemvo-email' ) );
		}

		$option   = get_option( 'wp_mail_smtp' );
		$option   = is_array( $option ) ? $option : array();
		$gmail    = is_array( $option['gmail'] ?? null ) ? $option['gmail'] : array();
		$google   = Providers::get( 'google' );
		$imported = array();
		$result   = null;

		// The Gmail mailer: client and authorization.
		if ( $google instanceof Google && $found['gmail']['client'] ) {
			$constants = $found['constants'];
			$client_id = $constants && defined( 'WPMS_GMAIL_CLIENT_ID' ) ? (string) constant( 'WPMS_GMAIL_CLIENT_ID' ) : (string) ( $gmail['client_id'] ?? '' );
			$secret    = $constants && defined( 'WPMS_GMAIL_CLIENT_SECRET' ) ? (string) constant( 'WPMS_GMAIL_CLIENT_SECRET' ) : (string) ( $gmail['client_secret'] ?? '' );

			if ( $found['gmail']['connected'] && '' !== $found['gmail']['email'] ) {
				$result     = $google->import(
					array(
						'client_id'     => trim( $client_id ),
						'client_secret' => trim( $secret ),
						'refresh_token' => (string) $gmail['refresh_token'],
						'email'         => $found['gmail']['email'],
						'scope'         => Google::SCOPE_FULL,
						'source'        => 'wp-mail-smtp',
					)
				);
				$imported[] = __( 'Google OAuth client', 'nemvo-email' );
				$imported[] = __( 'Gmail connection', 'nemvo-email' );
			} elseif ( ! $google->credentials_locked() ) {
				$saved = $google->save_credentials( $client_id, $secret );
				if ( ! is_wp_error( $saved ) ) {
					$imported[] = __( 'Google OAuth client', 'nemvo-email' );
				}
			}
		}

		// Sender.
		$sender  = array();
		$account = $google ? $google->account() : '';
		if ( is_email( $found['from_email'] ) ) {
			$sender['from_email'] = $found['from_email'] === $account ? '' : $found['from_email'];
			$imported[]           = __( 'From address', 'nemvo-email' );
		}
		if ( '' !== $found['from_name'] ) {
			$sender['from_name'] = sanitize_text_field( $found['from_name'] );
			$imported[]          = __( 'From name', 'nemvo-email' );
		}
		$sender['force_from_email'] = $found['force_from_email'] ? 1 : 0;
		$sender['force_from_name']  = $found['force_from_name'] ? 1 : 0;
		if ( $found['do_not_send'] ) {
			$sender['pause'] = 1;
			$imported[]      = __( '"Do not send" (sending paused)', 'nemvo-email' );
		}
		Settings::update( $sender + array( 'imported_from' => 'wp-mail-smtp' ) );

		return array(
			'imported'   => $imported,
			'connection' => $result,
		);
	}

	/**
	 * Deactivate WP Mail SMTP, so only one plugin handles email.
	 *
	 * @return bool
	 */
	public static function deactivate() {
		$file = self::active_plugin();
		if ( '' === $file ) {
			return false;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $file );
		return true;
	}
}
