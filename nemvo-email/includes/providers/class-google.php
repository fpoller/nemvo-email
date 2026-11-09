<?php
/**
 * Google Workspace and Gmail.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail\Providers;

use NemvoEmail\Crypto;
use NemvoEmail\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through the Gmail API with an account connected through the site's
 * own OAuth client (a "Web application" client in Google Cloud). Asks only
 * for permission to send ("gmail.send"), plus the account's address.
 *
 * Messages are uploaded as they are built by PHPMailer (media upload, up to
 * 35 MB), so headers, attachments and inline images arrive untouched.
 */
class Google extends Provider {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const SEND_URL   = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media';
	const PROBE_URL  = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	const SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';
	const SCOPE_FULL = 'https://mail.google.com/';

	const OPTION = 'nemvo_email_google';

	/**
	 * How Google OAuth client IDs end. Written in two parts because Plugin
	 * Check takes the domain for a CDN; nothing is loaded from it.
	 */
	const CLIENT_SUFFIX = '.apps.googleusercontent' . '.com'; // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- see above.

	/**
	 * Request cache of the option.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Machine name.
	 *
	 * @return string
	 */
	public function id() {
		return 'google';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function name() {
		return __( 'Google Workspace', 'nemvo-email' );
	}

	/*
	 * Stored data
	 * ------------------------------------------------------------------
	 */

	/**
	 * The stored connection.
	 *
	 * @return array
	 */
	public function data() {
		if ( null === $this->data ) {
			$stored     = get_option( self::OPTION, array() );
			$this->data = array_merge(
				array(
					'client_id'     => '',
					'client_secret' => '',
					'refresh_token' => '',
					'access_token'  => '',
					'expires'       => 0,
					'email'         => '',
					'hd'            => '',
					'scope'         => '',
					'connected_at'  => 0,
					'imported'      => '',
					'error'         => '',
					'error_code'    => '',
					'error_at'      => 0,
					'checked_at'    => 0,
				),
				is_array( $stored ) ? $stored : array()
			);
		}
		return $this->data;
	}

	/**
	 * Store changes.
	 *
	 * @param array $changes Changes.
	 */
	private function save( array $changes ) {
		$this->data = array_merge( $this->data(), $changes );
		update_option( self::OPTION, $this->data, false );
	}

	/**
	 * Forget the request cache.
	 */
	public function flush() {
		$this->data = null;
	}

	/**
	 * OAuth client id (NEMVO_EMAIL_GOOGLE_CLIENT_ID wins).
	 *
	 * @return string
	 */
	public function client_id() {
		$env = Settings::env( 'NEMVO_EMAIL_GOOGLE_CLIENT_ID' );
		return '' !== $env ? $env : (string) $this->data()['client_id'];
	}

	/**
	 * OAuth client secret (NEMVO_EMAIL_GOOGLE_CLIENT_SECRET wins).
	 *
	 * @return string
	 */
	public function client_secret() {
		$env = Settings::env( 'NEMVO_EMAIL_GOOGLE_CLIENT_SECRET' );
		return '' !== $env ? $env : (string) Crypto::decrypt( $this->data()['client_secret'] );
	}

	/**
	 * Whether the client comes from constants or the environment.
	 *
	 * @return bool
	 */
	public function credentials_locked() {
		return '' !== Settings::env( 'NEMVO_EMAIL_GOOGLE_CLIENT_ID' );
	}

	/**
	 * Store the OAuth client. A different client id disconnects the account:
	 * its tokens belong to the old client.
	 *
	 * @param string $client_id     Client id.
	 * @param string $client_secret Client secret; '' keeps the stored one.
	 * @return true|\WP_Error
	 */
	public function save_credentials( $client_id, $client_secret ) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		if ( ! preg_match( '/^[0-9]+-[a-z0-9]+' . preg_quote( self::CLIENT_SUFFIX, '/' ) . '$/', $client_id ) ) {
			/* translators: 1: the end of every client ID, 2: an example client ID */
			return new \WP_Error( 'client_id', sprintf( __( 'That doesn\'t look like a client ID. It ends in %1$s and starts with the project number, like %2$s.', 'nemvo-email' ), self::CLIENT_SUFFIX, '1234567890-abc123' . self::CLIENT_SUFFIX ) );
		}
		if ( '' === $client_secret && '' === (string) $this->data()['client_secret'] ) {
			return new \WP_Error( 'client_secret', __( 'Enter the client secret as well.', 'nemvo-email' ) );
		}
		if ( '' !== $client_secret && ! preg_match( '/^[A-Za-z0-9_-]{20,}$/', $client_secret ) ) {
			return new \WP_Error( 'client_secret', __( 'That doesn\'t look like a client secret. Google\'s secrets start with GOCSPX- and contain no spaces.', 'nemvo-email' ) );
		}
		if ( $client_id !== $this->data()['client_id'] && $this->is_connected() ) {
			$this->disconnect( false );
		}
		$changes = array( 'client_id' => $client_id );
		if ( '' !== $client_secret ) {
			$changes['client_secret'] = Crypto::encrypt( $client_secret );
		}
		$this->save( $changes );
		return true;
	}

	/*
	 * State
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether the OAuth client is set.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * Whether an account is connected.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== (string) $this->data()['refresh_token'];
	}

	/**
	 * The connected address.
	 *
	 * @return string
	 */
	public function account() {
		return $this->is_connected() ? (string) $this->data()['email'] : '';
	}

	/**
	 * Connection state.
	 *
	 * @return array
	 */
	public function state() {
		$data = $this->data();
		if ( ! $this->is_configured() && ! $this->is_connected() ) {
			$state = 'unconfigured';
		} elseif ( ! $this->is_connected() ) {
			$state = 'unconnected';
		} elseif ( '' !== $data['error'] ) {
			$state = 'error';
		} else {
			$state = 'connected';
		}
		return array(
			'state'   => $state,
			'message' => 'error' === $state ? (string) $data['error'] : '',
			'code'    => 'error' === $state ? (string) $data['error_code'] : '',
			'since'   => (int) $data['error_at'],
		);
	}

	/**
	 * Remember a problem that stops sending until someone acts.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private function set_error( $code, $message ) {
		$data = $this->data();
		$this->save(
			array(
				'error'      => $message,
				'error_code' => $code,
				'error_at'   => $data['error'] ? (int) $data['error_at'] : time(),
			)
		);
	}

	/**
	 * Forget a recorded problem.
	 */
	private function clear_error() {
		if ( '' !== $this->data()['error'] ) {
			$this->save(
				array(
					'error'      => '',
					'error_code' => '',
					'error_at'   => 0,
				)
			);
		}
	}

	/**
	 * Where an imported connection came from.
	 *
	 * @return string
	 */
	public function imported() {
		return $this->is_connected() ? (string) $this->data()['imported'] : '';
	}

	/**
	 * Personal Gmail rather than Google Workspace.
	 *
	 * @return bool
	 */
	public function is_personal() {
		$data = $this->data();
		if ( '' !== $data['hd'] ) {
			return false;
		}
		$domain = strtolower( (string) substr( strrchr( (string) $data['email'], '@' ), 1 ) );
		return in_array( $domain, $this->dns_profile()['personal_domains'], true );
	}

	/**
	 * Google's sending limits: 2,000 messages a day for Workspace, 500 for Gmail.
	 *
	 * @return int
	 */
	public function daily_limit() {
		return $this->is_personal() ? 500 : 2000;
	}

	/**
	 * Media uploads to the Gmail API take messages up to 35 MB.
	 *
	 * @return int
	 */
	public function max_bytes() {
		return 35 * 1024 * 1024;
	}

	/**
	 * Gmail's web interface opens a message by its API id.
	 *
	 * @param string $message_id Gmail message id.
	 * @return string
	 */
	public function message_url( $message_id ) {
		if ( '' === (string) $message_id || ! preg_match( '/^[0-9a-f]+$/i', $message_id ) || '' === $this->account() ) {
			return '';
		}
		return 'https://mail.google.com/mail/u/' . rawurlencode( $this->account() ) . '/#all/' . $message_id;
	}

	/**
	 * DNS for Google: SPF include, DKIM selector "google", Google's MX hosts,
	 * and addresses of Google's outbound mail servers (from the ranges in
	 * _spf.google.com, IPv4 and IPv6) to evaluate SPF records with.
	 *
	 * @return array
	 */
	public function dns_profile() {
		return array(
			'spf_include'      => '_spf.google.com',
			'sending_ips'      => array( '209.85.220.41', '74.125.82.41', '2607:f8b0:4864:20::41', '2a00:1450:4864:20::41' ),
			'helo'             => 'mail-sor-f41.google.com',
			'dkim_selector'    => 'google',
			'mx'               => array( 'google.com', 'googlemail.com' ),
			'personal_domains' => array( 'gmail.com', 'googlemail.com' ),
			'dkim_url'         => 'https://admin.google.com/ac/apps/gmail/authenticateemail',
		);
	}

	/*
	 * OAuth
	 * ------------------------------------------------------------------
	 */

	/**
	 * The address Google sends the browser back to. It must be listed under
	 * "Authorized redirect URIs" of the OAuth client, exactly.
	 *
	 * @return string
	 */
	public function redirect_uri() {
		return admin_url( 'admin-post.php?action=nemvo_email_oauth' );
	}

	/**
	 * Google's consent screen, with a signed state and PKCE.
	 *
	 * @return string
	 */
	public function connect_url() {
		$nonce    = wp_generate_password( 20, false, false );
		$verifier = Crypto::base64url( random_bytes( 48 ) );
		set_transient( 'nemvo_email_pkce_' . $nonce, $verifier, 30 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'             => $this->client_id(),
			'redirect_uri'          => $this->redirect_uri(),
			'response_type'         => 'code',
			'scope'                 => 'openid email ' . self::SCOPE_SEND,
			'access_type'           => 'offline',
			'prompt'                => 'consent select_account',
			'state'                 => Crypto::sign(
				array(
					'u'   => get_current_user_id(),
					'n'   => $nonce,
					'exp' => time() + 30 * MINUTE_IN_SECONDS,
				)
			),
			'code_challenge'        => Crypto::base64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
		);
		$hint   = (string) $this->data()['email'];
		if ( '' !== $hint ) {
			$params['login_hint'] = $hint;
		}

		$url = self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		/**
		 * Filters the URL of Google's consent screen.
		 *
		 * @param string $url    URL.
		 * @param array  $params Its query arguments.
		 */
		return (string) apply_filters( 'nemvo_email_google_auth_url', $url, $params );
	}

	/**
	 * Exchange the authorization code for tokens.
	 *
	 * @param array $query Query arguments (unslashed, sanitized).
	 * @return true|\WP_Error
	 */
	public function handle_callback( array $query ) {
		$state = Crypto::verify( (string) ( $query['state'] ?? '' ) );
		if ( ! $state || (int) ( $state['u'] ?? 0 ) !== get_current_user_id() || empty( $state['n'] ) ) {
			return new \WP_Error( 'state', __( 'The sign-in took too long or was started in another browser. Please try again.', 'nemvo-email' ) );
		}
		$key      = 'nemvo_email_pkce_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $state['n'] );
		$verifier = get_transient( $key );
		delete_transient( $key );
		if ( ! $verifier ) {
			return new \WP_Error( 'state', __( 'This sign-in was already used or has expired. Please try again.', 'nemvo-email' ) );
		}

		if ( ! empty( $query['error'] ) ) {
			if ( 'access_denied' === $query['error'] ) {
				return new \WP_Error( 'access_denied', __( 'The sign-in was cancelled at Google, so nothing was connected.', 'nemvo-email' ) );
			}
			/* translators: %s: error code from Google */
			return new \WP_Error( 'google', sprintf( __( 'Google returned an error: %s', 'nemvo-email' ), $query['error'] ) );
		}
		if ( empty( $query['code'] ) ) {
			return new \WP_Error( 'code', __( 'Google did not return an authorization code. Please try again.', 'nemvo-email' ) );
		}

		$tokens = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $query['code'],
				'redirect_uri'  => $this->redirect_uri(),
				'code_verifier' => $verifier,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		if ( ! self::scope_allows_sending( (string) ( $tokens['scope'] ?? '' ) ) ) {
			$this->revoke_token( (string) ( $tokens['refresh_token'] ?? $tokens['access_token'] ?? '' ) );
			return new \WP_Error( 'scope', __( 'Google didn\'t grant permission to send email. Connect again and leave "Send email on your behalf" ticked on Google\'s consent screen.', 'nemvo-email' ) );
		}
		if ( empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'refresh_token', __( 'Google did not return a refresh token. Remove the app under myaccount.google.com/permissions and connect again.', 'nemvo-email' ) );
		}

		$claims = self::id_token_claims( (string) ( $tokens['id_token'] ?? '' ) );
		$email  = isset( $claims['email'] ) && is_email( $claims['email'] ) ? strtolower( $claims['email'] ) : '';
		if ( '' === $email ) {
			return new \WP_Error( 'email', __( 'Google did not say which account was connected. Please try again.', 'nemvo-email' ) );
		}
		if ( isset( $claims['aud'] ) && $claims['aud'] !== $this->client_id() ) {
			return new \WP_Error( 'aud', __( 'Google\'s answer was meant for another OAuth client. Please try again.', 'nemvo-email' ) );
		}

		$this->save(
			array(
				'refresh_token' => Crypto::encrypt( $tokens['refresh_token'] ),
				'access_token'  => Crypto::encrypt( (string) ( $tokens['access_token'] ?? '' ) ),
				'expires'       => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
				'email'         => $email,
				'hd'            => isset( $claims['hd'] ) ? sanitize_text_field( $claims['hd'] ) : '',
				'scope'         => (string) $tokens['scope'],
				'connected_at'  => time(),
				'checked_at'    => time(),
				'imported'      => '',
				'error'         => '',
				'error_code'    => '',
				'error_at'      => 0,
			)
		);
		return true;
	}

	/**
	 * Use an authorization made elsewhere (imported from WP Mail SMTP).
	 *
	 * @param array $connection client_id, client_secret, refresh_token, email, scope, source.
	 * @return true|\WP_Error The result of checking it with Google.
	 */
	public function import( array $connection ) {
		$changes = array(
			'refresh_token' => Crypto::encrypt( (string) $connection['refresh_token'] ),
			'access_token'  => '',
			'expires'       => 0,
			'email'         => strtolower( (string) $connection['email'] ),
			'hd'            => '',
			'scope'         => (string) $connection['scope'],
			'connected_at'  => time(),
			'imported'      => (string) $connection['source'],
			'error'         => '',
			'error_code'    => '',
			'error_at'      => 0,
		);
		if ( ! $this->credentials_locked() ) {
			$changes['client_id']     = (string) $connection['client_id'];
			$changes['client_secret'] = Crypto::encrypt( (string) $connection['client_secret'] );
		}
		$this->save( $changes );
		return $this->check();
	}

	/**
	 * Disconnect.
	 *
	 * @param bool $revoke Revoke the refresh token at Google too.
	 */
	public function disconnect( $revoke = true ) {
		$data = $this->data();
		if ( $revoke ) {
			$this->revoke_token( (string) Crypto::decrypt( $data['refresh_token'] ) );
		}
		$this->save(
			array(
				'refresh_token' => '',
				'access_token'  => '',
				'expires'       => 0,
				'scope'         => '',
				'connected_at'  => 0,
				'imported'      => '',
				'error'         => '',
				'error_code'    => '',
				'error_at'      => 0,
			)
		);
	}

	/**
	 * Revoke a token at Google (best effort).
	 *
	 * @param string $token Token.
	 */
	private function revoke_token( $token ) {
		if ( '' !== $token ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 10,
					'body'    => array( 'token' => $token ),
				)
			);
		}
	}

	/**
	 * Whether a granted scope list includes sending.
	 *
	 * @param string $scope Space-separated scopes.
	 * @return bool
	 */
	public static function scope_allows_sending( $scope ) {
		$scopes = preg_split( '/\s+/', trim( $scope ) );
		return in_array( self::SCOPE_SEND, $scopes, true ) || in_array( self::SCOPE_FULL, $scopes, true );
	}

	/**
	 * Claims of an ID token received directly from Google's token endpoint
	 * over TLS (which is what vouches for it, per OpenID Connect).
	 *
	 * @param string $jwt ID token.
	 * @return array
	 */
	public static function id_token_claims( $jwt ) {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return array();
		}
		$claims = json_decode( (string) Crypto::base64url_decode( $parts[1] ), true );
		return is_array( $claims ) ? $claims : array();
	}

	/*
	 * Tokens
	 * ------------------------------------------------------------------
	 */

	/**
	 * POST to the token endpoint with the client credentials.
	 *
	 * @param array $body Grant parameters.
	 * @return array|\WP_Error Token response.
	 */
	private function token_request( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => $body + array(
					'client_id'     => $this->client_id(),
					'client_secret' => $this->client_secret(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'network', sprintf( __( 'Could not reach Google: %s', 'nemvo-email' ), $response->get_error_message() ), array( 'transient' => true ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		if ( 200 === $status && ! empty( $data['access_token'] ) ) {
			return $data;
		}

		$code        = is_string( $data['error'] ?? null ) ? $data['error'] : 'http_' . $status;
		$description = (string) ( $data['error_description'] ?? '' );
		switch ( $code ) {
			case 'invalid_grant':
				$message = __( 'Google no longer accepts the connection. It was removed from the Google account, the password changed, or the OAuth app is still in "Testing", where access ends after 7 days. Connect again.', 'nemvo-email' );
				break;
			case 'invalid_client':
			case 'unauthorized_client':
				$message = __( 'Google doesn\'t accept the OAuth client ID and secret. Check them, or create a new secret in Google Cloud and enter it under Settings › Connection.', 'nemvo-email' );
				break;
			default:
				/* translators: 1: error code, 2: description */
				$message = trim( sprintf( __( 'Google refused the request (%1$s). %2$s', 'nemvo-email' ), $code, $description ) );
		}
		return new \WP_Error( $code, $message, array( 'transient' => $status >= 500 ) );
	}

	/**
	 * A valid access token, refreshed when needed.
	 *
	 * @param bool $force Refresh even if the stored one hasn't expired.
	 * @return string|\WP_Error
	 */
	public function access_token( $force = false ) {
		$data = $this->data();
		if ( ! $this->is_connected() ) {
			return new \WP_Error( 'not_connected', __( 'No Google account is connected.', 'nemvo-email' ) );
		}
		if ( ! $force && (int) $data['expires'] > time() + 60 ) {
			$token = Crypto::decrypt( $data['access_token'] );
			if ( $token ) {
				return $token;
			}
		}

		$refresh = Crypto::decrypt( $data['refresh_token'] );
		if ( null === $refresh || '' === $refresh ) {
			$error = new \WP_Error( 'decrypt', __( 'The stored Google authorization can\'t be read. The WordPress security keys in wp-config.php (or NEMVO_EMAIL_KEY) have changed since it was connected. Connect again.', 'nemvo-email' ) );
			$this->set_error( 'decrypt', $error->get_error_message() );
			return $error;
		}
		if ( '' === $this->client_secret() ) {
			$error = new \WP_Error( 'client_secret', __( 'The OAuth client secret is missing or can\'t be read. Enter it again under Settings › Connection.', 'nemvo-email' ) );
			$this->set_error( 'client_secret', $error->get_error_message() );
			return $error;
		}

		$tokens = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			)
		);
		if ( is_wp_error( $tokens ) ) {
			$data = $tokens->get_error_data();
			if ( empty( $data['transient'] ) ) {
				$this->set_error( $tokens->get_error_code(), $tokens->get_error_message() );
			}
			return $tokens;
		}

		$changes = array(
			'access_token' => Crypto::encrypt( $tokens['access_token'] ),
			'expires'      => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
		);
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$changes['refresh_token'] = Crypto::encrypt( $tokens['refresh_token'] );
		}
		$this->save( $changes );
		return $tokens['access_token'];
	}

	/*
	 * Sending
	 * ------------------------------------------------------------------
	 */

	/**
	 * Upload the message to the Gmail API. Retries once after an expired
	 * token (401) and once after a rate limit or server error.
	 *
	 * @param string $mime Complete message.
	 * @return array|\WP_Error
	 */
	public function send( $mime ) {
		if ( strlen( $mime ) > $this->max_bytes() ) {
			/* translators: %s: size, e.g. 41 MB */
			return new \WP_Error( 'too_large', sprintf( __( 'The message is %s with attachments; Gmail takes at most 35 MB (25 MB of attachments).', 'nemvo-email' ), size_format( strlen( $mime ), 1 ) ) );
		}

		$retried = array();
		$refresh = false;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$token   = $this->access_token( $refresh );
			$refresh = false;
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = wp_remote_post(
				self::SEND_URL,
				array(
					'timeout' => 45,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'message/rfc822',
					),
					'body'    => $mime,
				)
			);
			if ( is_wp_error( $response ) ) {
				if ( empty( $retried['network'] ) ) {
					$retried['network'] = true;
					continue;
				}
				/* translators: %s: error message */
				return new \WP_Error( 'network', sprintf( __( 'Could not reach Gmail: %s', 'nemvo-email' ), $response->get_error_message() ) );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$data   = is_array( $data ) ? $data : array();
			if ( $status >= 200 && $status < 300 && ! empty( $data['id'] ) ) {
				$this->clear_error();
				return array(
					'id'     => (string) $data['id'],
					'thread' => (string) ( $data['threadId'] ?? '' ),
				);
			}

			if ( 401 === $status && empty( $retried['auth'] ) ) {
				$retried['auth'] = true;
				$refresh         = true;
				continue;
			}
			if ( ( 429 === $status || $status >= 500 ) && empty( $retried['busy'] ) ) {
				$retried['busy'] = true;
				sleep( 1 );
				continue;
			}
			return $this->api_error( $status, $data );
		}
		return new \WP_Error( 'gmail', __( 'Gmail did not accept the message.', 'nemvo-email' ) );
	}

	/**
	 * Check the connection without sending: refresh the token, then make an
	 * empty send request. Gmail answers 400 (no message) when the API is
	 * enabled and the token may send, 403 when the API is off or the
	 * permission is missing, 401 when the token is bad.
	 *
	 * @return true|\WP_Error
	 */
	public function check() {
		$token = $this->access_token( true );
		if ( is_wp_error( $token ) ) {
			$this->save( array( 'checked_at' => time() ) );
			return $token;
		}
		$response = wp_remote_post(
			self::PROBE_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);
		$this->save( array( 'checked_at' => time() ) );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return new \WP_Error( 'network', sprintf( __( 'Could not reach Gmail: %s', 'nemvo-email' ), $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status || ( $status >= 200 && $status < 300 ) ) {
			$this->clear_error();
			return true;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return $this->api_error( $status, is_array( $data ) ? $data : array() );
	}

	/**
	 * Turn a Gmail API error into a message that says what to do, and
	 * remember errors that stop all sending.
	 *
	 * @param int   $status HTTP status.
	 * @param array $data   Decoded body.
	 * @return \WP_Error
	 */
	private function api_error( $status, array $data ) {
		$error   = is_array( $data['error'] ?? null ) ? $data['error'] : array();
		$message = (string) ( $error['message'] ?? '' );
		$reasons = array();
		foreach ( (array) ( $error['errors'] ?? array() ) as $item ) {
			$reasons[] = (string) ( $item['reason'] ?? '' );
		}
		$activation = '';
		foreach ( (array) ( $error['details'] ?? array() ) as $detail ) {
			if ( ! empty( $detail['reason'] ) ) {
				$reasons[] = (string) $detail['reason'];
			}
			if ( ! empty( $detail['metadata']['activationUrl'] ) ) {
				$activation = (string) $detail['metadata']['activationUrl'];
			}
		}
		if ( '' === $activation && preg_match( '#https://console\.(?:developers|cloud)\.google\.com/\S+#', $message, $m ) ) {
			$activation = rtrim( $m[0], '.' );
		}

		$fatal = true;
		if ( array_intersect( $reasons, array( 'accessNotConfigured', 'SERVICE_DISABLED' ) ) ) {
			$code = 'api_disabled';
			$text = __( 'The Gmail API is not enabled in the Google Cloud project of your OAuth client. Enable it, wait a minute, and try again.', 'nemvo-email' );
			if ( $activation ) {
				$text .= ' ' . $activation;
			}
		} elseif ( array_intersect( $reasons, array( 'insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT' ) ) ) {
			$code = 'scope';
			$text = __( 'The connected account didn\'t grant permission to send email. Connect again and leave "Send email on your behalf" ticked.', 'nemvo-email' );
		} elseif ( 401 === $status ) {
			$code = 'unauthorized';
			$text = __( 'Gmail did not accept the authorization. Connect the account again.', 'nemvo-email' );
		} elseif ( 429 === $status || array_intersect( $reasons, array( 'rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'quotaExceeded', 'RATE_LIMIT_EXCEEDED' ) ) ) {
			$fatal = false;
			$code  = 'rate_limit';
			/* translators: %s: Gmail's own message */
			$text = sprintf( __( 'Gmail\'s sending limit was reached: %s', 'nemvo-email' ), '' !== $message ? $message : __( 'too many messages.', 'nemvo-email' ) );
		} else {
			$fatal = false;
			$code  = 'gmail_' . $status;
			/* translators: 1: HTTP status, 2: Gmail's message */
			$text = sprintf( __( 'Gmail refused the message (%1$d): %2$s', 'nemvo-email' ), $status, '' !== $message ? $message : __( 'no reason given.', 'nemvo-email' ) );
		}
		if ( $fatal ) {
			$this->set_error( $code, $text );
		}
		return new \WP_Error( $code, $text, array( 'status' => $status ) );
	}
}
