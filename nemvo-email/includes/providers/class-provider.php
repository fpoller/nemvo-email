<?php
/**
 * Email provider interface.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * A service that sends complete MIME messages for WordPress over an API,
 * with an account connected through OAuth. Google Workspace is the first;
 * others (Microsoft 365, …) implement the same methods.
 */
abstract class Provider {

	/**
	 * Machine name, stored in the log ("google").
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Display name ("Google Workspace").
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether the app credentials (OAuth client) are in place.
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * Whether an account is connected (it may still be in an error state).
	 *
	 * @return bool
	 */
	abstract public function is_connected();

	/**
	 * The connected account's address, or ''.
	 *
	 * @return string
	 */
	abstract public function account();

	/**
	 * Connection state for screens and checks.
	 *
	 * @return array {
	 *     @type string $state   unconfigured | unconnected | connected | error
	 *     @type string $message Problem description in the error state.
	 *     @type int    $since   When the error was first seen.
	 * }
	 */
	abstract public function state();

	/**
	 * Send a complete MIME message (with To, Cc, Bcc and Subject headers).
	 *
	 * @param string $mime Message.
	 * @return array|\WP_Error array( 'id' => provider message id ).
	 */
	abstract public function send( $mime );

	/**
	 * Check the connection without sending anything.
	 *
	 * @return true|\WP_Error
	 */
	abstract public function check();

	/**
	 * The OAuth redirect URI to register with the provider.
	 *
	 * @return string
	 */
	abstract public function redirect_uri();

	/**
	 * Where to send the browser to connect an account.
	 *
	 * @return string
	 */
	abstract public function connect_url();

	/**
	 * Finish connecting after the provider redirects back.
	 *
	 * @param array $query Query arguments of the redirect.
	 * @return true|\WP_Error
	 */
	abstract public function handle_callback( array $query );

	/**
	 * Forget the connected account.
	 *
	 * @param bool $revoke Also revoke the authorization at the provider.
	 */
	abstract public function disconnect( $revoke = true );

	/**
	 * What the provider needs in DNS for a sender domain.
	 *
	 * @return array {
	 *     @type string   $spf_include      SPF include domain.
	 *     @type string   $dkim_selector    Default DKIM selector.
	 *     @type string[] $mx               Host suffixes of the provider's mail servers.
	 *     @type string[] $personal_domains Consumer domains the user can't change DNS for.
	 *     @type string   $dkim_url         Where DKIM is set up.
	 * }
	 */
	abstract public function dns_profile();

	/**
	 * Where the connection came from when it was imported ("wp-mail-smtp"), or ''.
	 *
	 * @return string
	 */
	public function imported() {
		return '';
	}

	/**
	 * A consumer account (like @gmail.com) rather than an organisation's.
	 *
	 * @return bool
	 */
	public function is_personal() {
		return false;
	}

	/**
	 * Messages per day the account may send, 0 if unknown.
	 *
	 * @return int
	 */
	public function daily_limit() {
		return 0;
	}

	/**
	 * Largest message in bytes, 0 if unknown.
	 *
	 * @return int
	 */
	public function max_bytes() {
		return 0;
	}

	/**
	 * A link to a sent message in the provider's web interface.
	 *
	 * @param string $message_id Provider message id.
	 * @return string
	 */
	public function message_url( $message_id ) {
		unset( $message_id );
		return '';
	}
}
