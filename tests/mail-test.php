<?php
/**
 * Integration tests: the wp_mail() takeover, the Gmail API client, OAuth,
 * sender rules, logging, statistics, the REST API, DNS checks, the WP Mail
 * SMTP import, Site Health and privacy. Run in the dev container:
 *
 *   docker compose -f .devcontainer/docker-compose.yml exec -T wordpress wp eval-file /workspace/tests/mail-test.php
 *
 * Google and DNS are replaced by fakes defined here, which record every
 * request. Settings are restored and the test's log entries removed at the
 * end, so the dev site is left as it was. WP Mail SMTP is installed for the
 * import tests if it is missing (and left inactive).
 *
 * @package NemvoEmail
 */

// phpcs:disable -- test code.

use NemvoEmail\Crypto;
use NemvoEmail\Dns;
use NemvoEmail\Health;
use NemvoEmail\Import;
use NemvoEmail\Log;
use NemvoEmail\Mailer;
use NemvoEmail\Privacy;
use NemvoEmail\Providers;
use NemvoEmail\Providers\Google;
use NemvoEmail\Settings;
use NemvoEmail\Stats;

defined( 'ABSPATH' ) || exit;

$GLOBALS['nve_fail'] = 0;
$GLOBALS['nve_pass'] = 0;

function nve_ok( $cond, $msg ) {
	if ( $cond ) {
		$GLOBALS['nve_pass']++;
		WP_CLI::log( "ok   $msg" );
	} else {
		$GLOBALS['nve_fail']++;
		WP_CLI::log( "FAIL $msg" );
	}
}

function nve_section( $title ) {
	WP_CLI::log( "\n== $title" );
}

/* ------------------------------------------------------------------ *
 * Fakes
 * ------------------------------------------------------------------ */

/**
 * Fake Google. $GLOBALS['nve_fake'] holds queues of responses per endpoint
 * ('token', 'send', 'probe', 'revoke'): each item is [status, body] or a
 * callable( $args ) returning one. An empty queue gives the default answer.
 */
function nve_fake_reset() {
	$GLOBALS['nve_fake'] = array(
		'token'  => array(),
		'send'   => array(),
		'probe'  => array(),
		'revoke' => array(),
	);
	$GLOBALS['nve_http'] = array();
}

function nve_json( $status, $data ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => is_string( $data ) ? $data : wp_json_encode( $data ),
		'response' => array(
			'code'    => $status,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function nve_jwt( array $claims ) {
	$b = static function ( $d ) {
		return rtrim( strtr( base64_encode( wp_json_encode( $d ) ), '+/', '-_' ), '=' );
	};
	return $b( array( 'alg' => 'RS256' ) ) . '.' . $b( $claims ) . '.signature';
}

function nve_calls( $endpoint ) {
	return array_values(
		array_filter(
			$GLOBALS['nve_http'],
			static function ( $c ) use ( $endpoint ) {
				return $c['endpoint'] === $endpoint;
			}
		)
	);
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$map  = array(
			'oauth2.googleapis.com/token'                    => 'token',
			'oauth2.googleapis.com/revoke'                   => 'revoke',
			'gmail.googleapis.com/upload/gmail/v1/users/me/messages/send' => 'send',
			'gmail.googleapis.com/gmail/v1/users/me/messages/send'        => 'probe',
		);
		if ( ! isset( $map[ $host . $path ] ) ) {
			return $pre;
		}
		$endpoint                = $map[ $host . $path ];
		$GLOBALS['nve_http'][] = array(
			'endpoint' => $endpoint,
			'url'      => $url,
			'args'     => $args,
		);
		$next = array_shift( $GLOBALS['nve_fake'][ $endpoint ] );
		if ( is_callable( $next ) ) {
			$next = $next( $args );
		}
		if ( is_array( $next ) ) {
			return nve_json( $next[0], $next[1] );
		}
		switch ( $endpoint ) {
			case 'token':
				$body = is_array( $args['body'] ) ? $args['body'] : array();
				return nve_json(
					200,
					array(
						'access_token' => 'at-' . wp_generate_password( 8, false ),
						'expires_in'   => 3599,
						'scope'        => Google::SCOPE_SEND,
						'token_type'   => 'Bearer',
					) + ( 'authorization_code' === ( $body['grant_type'] ?? '' ) ? array( 'refresh_token' => 'rt-fresh' ) : array() )
				);
			case 'send':
				return nve_json( 200, array( 'id' => '18c' . substr( md5( (string) $args['body'] ), 0, 13 ), 'threadId' => 't1', 'labelIds' => array( 'SENT' ) ) );
			case 'probe':
				return nve_json( 400, array( 'error' => array( 'code' => 400, 'message' => "'raw' RFC822 payload message string or uploading message via /upload/* URL required", 'status' => 'INVALID_ARGUMENT' ) ) );
			default:
				return nve_json( 200, array() );
		}
	},
	PHP_INT_MAX,
	3
);

// Fake DNS: $GLOBALS['nve_dns']["TYPE name"] = records. Names not listed have none.
$GLOBALS['nve_dns'] = array();
add_filter(
	'nemvo_email_pre_dns_lookup',
	static function ( $records, $name, $type ) {
		if ( ! empty( $GLOBALS['nve_dns_live'] ) ) {
			return null;
		}
		return $GLOBALS['nve_dns'][ $type . ' ' . $name ] ?? array();
	},
	PHP_INT_MAX,
	3
);

/* ------------------------------------------------------------------ *
 * State
 * ------------------------------------------------------------------ */

$nve_options = array( Settings::OPTION, Google::OPTION, Dns::OPTION, 'wp_mail_smtp', 'active_plugins' );
$nve_backup  = array();
foreach ( $nve_options as $nve_option ) {
	$nve_backup[ $nve_option ] = get_option( $nve_option, '__missing__' );
}
global $wpdb;
// The tests get an empty log of their own; the dev site's log waits in a backup table.
$nve_backup_table = Log::table() . '_backup';
if ( $nve_backup_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $nve_backup_table ) ) ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Log::table() ); // An earlier run stopped half-way.
} else {
	$wpdb->query( 'RENAME TABLE ' . Log::table() . ' TO ' . $nve_backup_table );
}
NemvoEmail\Install::install();
wp_set_current_user( 1 );
// The dev site skips Google's consent screen; these tests look at the real URL.
remove_all_filters( 'nemvo_email_google_auth_url' );

function nve_reset() {
	delete_option( Settings::OPTION );
	delete_option( Google::OPTION );
	delete_option( Dns::OPTION );
	Settings::flush();
	Providers::reset();
	nve_fake_reset();
	$GLOBALS['nve_dns'] = array();
}

function nve_google() {
	return Providers::get( 'google' );
}

/** Connect through the real OAuth code path with the fake token endpoint. */
function nve_connect( $email = 'site@acme.test', $hd = 'acme.test', $scope = null ) {
	$google = nve_google();
	$google->save_credentials( '1234567890-testclient.apps.googleusercontent.com', 'GOCSPX-test-secret-0123456789' );
	parse_str( (string) wp_parse_url( $google->connect_url(), PHP_URL_QUERY ), $query );
	$GLOBALS['nve_fake']['token'][] = array(
		200,
		array(
			'access_token'  => 'at-initial',
			'refresh_token' => 'rt-' . $email,
			'expires_in'    => 3599,
			'scope'         => $scope ?? 'openid https://www.googleapis.com/auth/userinfo.email ' . Google::SCOPE_SEND,
			'token_type'    => 'Bearer',
			'id_token'      => nve_jwt(
				array(
					'iss'   => 'https://accounts.google.com',
					'aud'   => '1234567890-testclient.apps.googleusercontent.com',
					'email' => $email,
				) + ( $hd ? array( 'hd' => $hd ) : array() )
			),
		),
	);
	return $google->handle_callback(
		array(
			'state' => $query['state'],
			'code'  => '4/test-code',
		)
	);
}

function nve_last_log() {
	return Mailer::$last_log_id ? Log::get( Mailer::$last_log_id ) : null;
}

/** The MIME of the n-th upload (from the end). */
function nve_last_mime( $back = 1 ) {
	$sends = nve_calls( 'send' );
	return $sends ? (string) $sends[ count( $sends ) - $back ]['args']['body'] : '';
}

function nve_header( $mime, $name ) {
	list( $head ) = explode( "\r\n\r\n", $mime, 2 );
	$head         = preg_replace( "/\r\n[ \t]+/", ' ', $head );
	return preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.*)$/mi', $head, $m ) ? trim( $m[1] ) : null;
}

/* ================================================================== *
 * Crypto
 * ================================================================== */

nve_section( 'Crypto' );
$secret = Crypto::encrypt( 'refresh-token-ü' );
nve_ok( 0 === strpos( $secret, 'nve1:' ) && false === strpos( $secret, 'refresh' ), 'encrypted value is prefixed and opaque' );
nve_ok( 'refresh-token-ü' === Crypto::decrypt( $secret ), 'decrypts' );
nve_ok( Crypto::encrypt( 'x' ) !== Crypto::encrypt( 'x' ), 'random nonce per encryption' );
$tampered = substr( $secret, 0, -4 ) . ( 'AAAA' === substr( $secret, -4 ) ? 'BBBB' : 'AAAA' );
nve_ok( null === Crypto::decrypt( $tampered ), 'tampered value is refused' );
nve_ok( null === Crypto::decrypt( 'plain' ) && '' === Crypto::decrypt( '' ), 'unprefixed value is refused, empty stays empty' );
$signed = Crypto::sign( array( 'u' => 1, 'exp' => time() + 60 ) );
nve_ok( 1 === Crypto::verify( $signed )['u'], 'signed state verifies' );
nve_ok( null === Crypto::verify( $signed . 'x' ), 'changed signature is refused' );
nve_ok( null === Crypto::verify( Crypto::sign( array( 'exp' => time() - 1 ) ) ), 'expired state is refused' );

/* ================================================================== *
 * OAuth
 * ================================================================== */

nve_section( 'Google OAuth' );
nve_reset();
$google = nve_google();
nve_ok( 'unconfigured' === $google->state()['state'], 'starts unconfigured' );
nve_ok( is_wp_error( $google->save_credentials( 'not-a-client', 'GOCSPX-xxxxxxxxxxxxxxxxxxxx' ) ), 'client ID format is checked' );
nve_ok( is_wp_error( $google->save_credentials( '1234567890-abc.apps.googleusercontent.com', 'has spaces in it' ) ), 'client secret format is checked' );
nve_ok( is_wp_error( $google->save_credentials( '1234567890-abc.apps.googleusercontent.com', '' ) ), 'a first secret is required' );
nve_ok( true === $google->save_credentials( '1234567890-testclient.apps.googleusercontent.com', 'GOCSPX-test-secret-0123456789' ), 'valid client is saved' );
nve_ok( 'GOCSPX-test-secret-0123456789' === $google->client_secret() && false === strpos( wp_json_encode( get_option( Google::OPTION ) ), 'GOCSPX' ), 'secret is stored encrypted' );
nve_ok( true === $google->save_credentials( '1234567890-testclient.apps.googleusercontent.com', '' ), 'an empty secret keeps the stored one' );
nve_ok( 'unconnected' === $google->state()['state'], 'configured, not connected' );

$url = $google->connect_url();
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
nve_ok( 0 === strpos( $url, Google::AUTH_URL ), 'consent URL is Google\'s' );
nve_ok( 'openid email ' . Google::SCOPE_SEND === $q['scope'], 'asks only for sending and the address: ' . $q['scope'] );
nve_ok( 'offline' === $q['access_type'] && false !== strpos( $q['prompt'], 'consent' ), 'offline access with consent (refresh token every time)' );
nve_ok( 'S256' === $q['code_challenge_method'] && 43 === strlen( $q['code_challenge'] ), 'PKCE challenge' );
nve_ok( $google->redirect_uri() === $q['redirect_uri'] && false !== strpos( $q['redirect_uri'], 'admin-post.php?action=nemvo_email_oauth' ), 'redirect URI' );

$bad = $google->handle_callback( array( 'state' => 'forged.state', 'code' => 'x' ) );
nve_ok( is_wp_error( $bad ) && 'state' === $bad->get_error_code(), 'forged state is refused' );
$cancel = $google->handle_callback( array( 'state' => $q['state'], 'error' => 'access_denied' ) );
nve_ok( is_wp_error( $cancel ) && 'access_denied' === $cancel->get_error_code(), 'cancelled consent says so' );
$replay = $google->handle_callback( array( 'state' => $q['state'], 'code' => 'x' ) );
nve_ok( is_wp_error( $replay ) && 'state' === $replay->get_error_code(), 'a state can be used once' );

nve_fake_reset();
$result = nve_connect( 'site@acme.test' );
$token  = nve_calls( 'token' )[0]['args']['body'];
nve_ok( true === $result, 'callback connects' );
nve_ok( 'authorization_code' === $token['grant_type'] && ! empty( $token['code_verifier'] ) && '4/test-code' === $token['code'], 'code exchanged with the PKCE verifier' );
nve_ok( 'site@acme.test' === $google->account() && 'connected' === $google->state()['state'], 'account from the ID token' );
nve_ok( 2000 === $google->daily_limit() && ! $google->is_personal(), 'Workspace account: 2,000 a day' );
nve_ok( false === strpos( wp_json_encode( get_option( Google::OPTION ) ), 'rt-site' ), 'refresh token stored encrypted' );

nve_reset();
$personal = nve_connect( 'someone@gmail.com', '' );
nve_ok( true === $personal && nve_google()->is_personal() && 500 === nve_google()->daily_limit(), 'personal Gmail: 500 a day' );

nve_reset();
$noscope = nve_connect( 'site@acme.test', 'acme.test', 'openid email' );
nve_ok( is_wp_error( $noscope ) && 'scope' === $noscope->get_error_code() && ! nve_google()->is_connected(), 'unticked send permission is refused, not stored' );
nve_ok( 1 === count( nve_calls( 'revoke' ) ), '... and the half-granted token is revoked' );

nve_reset();
$google = nve_google();
$google->save_credentials( '1234567890-testclient.apps.googleusercontent.com', 'GOCSPX-test-secret-0123456789' );
parse_str( (string) wp_parse_url( $google->connect_url(), PHP_URL_QUERY ), $q );
$GLOBALS['nve_fake']['token'][] = array( 200, array( 'access_token' => 'a', 'refresh_token' => 'r', 'scope' => Google::SCOPE_SEND, 'id_token' => nve_jwt( array( 'aud' => 'someone-else', 'email' => 'x@acme.test' ) ) ) );
$aud = $google->handle_callback( array( 'state' => $q['state'], 'code' => 'c' ) );
nve_ok( is_wp_error( $aud ) && 'aud' === $aud->get_error_code(), 'ID token for another client is refused' );

/* ================================================================== *
 * Tokens and connection checks
 * ================================================================== */

nve_section( 'Tokens' );
nve_reset();
nve_connect();
$google = nve_google();
nve_fake_reset();
nve_ok( 'at-initial' === $google->access_token() && 0 === count( nve_calls( 'token' ) ), 'valid access token is reused' );
update_option( Google::OPTION, array_merge( get_option( Google::OPTION ), array( 'expires' => time() - 1 ) ) );
$google->flush();
$fresh = $google->access_token();
nve_ok( 0 === strpos( $fresh, 'at-' ) && 'at-initial' !== $fresh && 'refresh_token' === nve_calls( 'token' )[0]['args']['body']['grant_type'], 'expired token is refreshed' );
nve_ok( true === $google->check() && 1 === count( nve_calls( 'probe' ) ), 'check: Gmail answers the empty send with 400, so the API is on' );

nve_fake_reset();
$GLOBALS['nve_fake']['token'][] = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
$check = $google->check();
nve_ok( is_wp_error( $check ) && 'invalid_grant' === $check->get_error_code(), 'revoked authorization is reported' );
nve_ok( 'error' === $google->state()['state'] && false !== strpos( $google->state()['message'], 'Connect again' ), '... and remembered as an error that says what to do' );
nve_ok( true === $google->check() && 'connected' === $google->state()['state'], 'a later successful check clears it' );

nve_fake_reset();
$GLOBALS['nve_fake']['probe'][] = array(
	403,
	array(
		'error' => array(
			'code'    => 403,
			'message' => 'Gmail API has not been used in project 123 before or it is disabled.',
			'errors'  => array( array( 'reason' => 'accessNotConfigured' ) ),
			'details' => array( array( '@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'metadata' => array( 'activationUrl' => 'https://console.developers.google.com/apis/api/gmail.googleapis.com/overview?project=123' ) ) ),
		),
	),
);
$check = $google->check();
nve_ok( is_wp_error( $check ) && 'api_disabled' === $check->get_error_code() && false !== strpos( $check->get_error_message(), 'project=123' ), 'disabled Gmail API: says so, with the link to enable it' );
$google->check();

$data                  = get_option( Google::OPTION );
$data['refresh_token'] = 'nve1:' . base64_encode( str_repeat( 'x', 60 ) );
$data['expires']       = 0;
update_option( Google::OPTION, $data );
$google->flush();
$dec = $google->access_token();
nve_ok( is_wp_error( $dec ) && 'decrypt' === $dec->get_error_code() && 'error' === $google->state()['state'], 'unreadable token (changed salts) asks to connect again' );

nve_fake_reset();
$google->disconnect();
nve_ok( ! $google->is_connected() && 'unconnected' === $google->state()['state'], 'disconnect forgets the account' );

nve_reset();
nve_connect();
nve_fake_reset();
nve_google()->disconnect();
nve_ok( 1 === count( nve_calls( 'revoke' ) ) && 'rt-site@acme.test' === nve_calls( 'revoke' )[0]['args']['body']['token'], 'disconnect revokes the refresh token at Google' );

/* ================================================================== *
 * Sending through wp_mail()
 * ================================================================== */

nve_section( 'Sending' );
nve_reset();
nve_connect();
nve_fake_reset();
// The dev site's Mailpit hooks must not matter once connected.
remove_all_actions( 'phpmailer_init' );
remove_all_filters( 'wp_mail_from', 10 );

$ok    = wp_mail( 'anna@example.org', 'Hello ✓ Ü', "Line one\nLine two" );
$sends = nve_calls( 'send' );
$mime  = nve_last_mime();
$entry = nve_last_log();
nve_ok( true === $ok && 1 === count( $sends ), 'wp_mail() sends through the Gmail API' );
nve_ok( 'message/rfc822' === $sends[0]['args']['headers']['Content-Type'] && 0 === strpos( $sends[0]['args']['headers']['Authorization'], 'Bearer at-' ), 'media upload with the access token' );
nve_ok( false !== strpos( $sends[0]['url'], 'uploadType=media' ), 'upload endpoint' );
nve_ok( 'anna@example.org' === nve_header( $mime, 'To' ), 'To header in the message' );
nve_ok( preg_match( '/^=\?UTF-8\?[QB]\?/', (string) nve_header( $mime, 'Subject' ) ) && 'Hello ✓ Ü' === iconv_mime_decode( nve_header( $mime, 'Subject' ), 0, 'UTF-8' ), 'Subject header, encoded: ' . nve_header( $mime, 'Subject' ) );
nve_ok( preg_match( '/^Northwind Studio <site@acme\.test>$|<site@acme\.test>$/', (string) nve_header( $mime, 'From' ) ), 'From is the account: ' . nve_header( $mime, 'From' ) );
nve_ok( ! preg_match( "/(?<!\r)\n/", $mime ), 'CRLF line endings throughout' );
nve_ok( $entry && 'sent' === $entry['status'] && 'google' === $entry['provider'] && 0 === strpos( $entry['message_id'], '18c' ), 'logged as sent with Gmail\'s id' );
nve_ok( $entry && strlen( $mime ) === $entry['size'] && 'site@acme.test' === $entry['account'], 'log has the size and account' );

$file = tempnam( sys_get_temp_dir(), 'nve' );
file_put_contents( $file, 'invoice' );
$ok    = wp_mail(
	array( 'Anna <anna@example.org>', 'erik@example.net' ),
	'Order #1041 received',
	'<p>Thanks!</p>',
	array( 'Content-Type: text/html; charset=UTF-8', 'Cc: carl@example.org', 'Bcc: hidden@example.org', 'X-Order: 1041' ),
	array( 'invoice-1041.pdf' => $file )
);
$mime  = nve_last_mime();
$entry = nve_last_log();
nve_ok( $ok && 'hidden@example.org' === nve_header( $mime, 'Bcc' ), 'Bcc is in the uploaded message (Gmail reads and removes it)' );
nve_ok( 'carl@example.org' === nve_header( $mime, 'Cc' ) && '1041' === nve_header( $mime, 'X-Order' ), 'Cc and custom headers kept' );
nve_ok( false !== strpos( $mime, 'filename=invoice-1041.pdf' ) && false !== strpos( $mime, 'multipart/mixed' ), 'attachment with its name' );
nve_ok( 2 === count( $entry['recipients']['to'] ) && 'Anna' === $entry['recipients']['to'][0][1] && 1 === count( $entry['recipients']['bcc'] ) && 4 === $entry['recipient_count'], 'log: recipients by kind' );
nve_ok( 'invoice-1041.pdf' === $entry['attachments'][0]['name'] && array( 'X-Order', '1041' ) === $entry['headers'][0], 'log: attachments and headers' );
nve_ok( 'example.org' === $wpdb->get_var( $wpdb->prepare( 'SELECT to_domain FROM ' . Log::table() . ' WHERE id = %d', $entry['id'] ) ), 'log: recipient domain' );
nve_ok( 'Order # received' === $wpdb->get_var( $wpdb->prepare( 'SELECT subject_key FROM ' . Log::table() . ' WHERE id = %d', $entry['id'] ) ), 'log: subject grouped without the number' );
nve_ok( null === $entry['body'] && ! $entry['has_content'], 'content not kept by default' );
unlink( $file );

nve_section( 'Sender rules' );
update_option( 'blogname', 'Northwind Studio' );
nve_fake_reset();
wp_mail( 'a@example.org', 'Contact form', 'Hi', array( 'From: Visitor Name <visitor@gmail.com>' ) );
$mime = nve_last_mime();
nve_ok( 'Visitor Name <site@acme.test>' === nve_header( $mime, 'From' ), 'a foreign sender is replaced (name kept): ' . nve_header( $mime, 'From' ) );
nve_ok( 'Visitor Name <visitor@gmail.com>' === nve_header( $mime, 'Reply-To' ), '... and becomes the Reply-To' );

wp_mail( 'a@example.org', 'Contact form', 'Hi', array( 'From: Visitor <visitor@gmail.com>', 'Reply-To: desk@acme.test' ) );
nve_ok( 'desk@acme.test' === nve_header( nve_last_mime(), 'Reply-To' ), 'an existing Reply-To is kept' );

wp_mail( 'a@example.org', 'Own domain', 'Hi', array( 'From: Shop <shop@acme.test>' ) );
nve_ok( 'Shop <site@acme.test>' === nve_header( nve_last_mime(), 'From' ) && null === nve_header( nve_last_mime(), 'Reply-To' ), 'a sender of the site\'s own domain is replaced without a Reply-To' );
wp_mail( 'a@example.org', 'No reply', 'Hi', array( 'From: Shop <noreply@other.test>' ) );
nve_ok( null === nve_header( nve_last_mime(), 'Reply-To' ), 'a no-reply sender never becomes the Reply-To' );

wp_mail( 'a@example.org', 'Default sender', 'Hi' );
nve_ok( 'Northwind Studio <site@acme.test>' === nve_header( nve_last_mime(), 'From' ) && null === nve_header( nve_last_mime(), 'Reply-To' ), 'WordPress\'s default sender and name "WordPress" are replaced, no Reply-To' );

Settings::update( array( 'force_from_email' => 0 ) );
wp_mail( 'a@example.org', 'Shop', 'Hi', array( 'From: Shop <shop@acme.test>' ) );
nve_ok( 'Shop <shop@acme.test>' === nve_header( nve_last_mime(), 'From' ), 'with forcing off, a plugin\'s sender (alias) is kept' );

Settings::update( array( 'force_from_email' => 1, 'from_email' => 'hello@acme.test', 'from_name' => 'Northwind', 'force_from_name' => 1, 'reply_to_original' => 0 ) );
wp_mail( 'a@example.org', 'Shop', 'Hi', array( 'From: Shop <shop@other.test>' ) );
nve_ok( 'Northwind <hello@acme.test>' === nve_header( nve_last_mime(), 'From' ) && null === nve_header( nve_last_mime(), 'Reply-To' ), 'configured address and forced name; Reply-To off' );

add_action(
	'phpmailer_init',
	$nve_init = static function ( $m ) {
		$m->setFrom( 'late@else.test', 'Late' );
	}
);
wp_mail( 'a@example.org', 'Late change', 'Hi' );
nve_ok( 'Northwind <hello@acme.test>' === nve_header( nve_last_mime(), 'From' ), 'a sender set in phpmailer_init is replaced too' );
remove_action( 'phpmailer_init', $nve_init );
Settings::update( array( 'from_email' => '', 'from_name' => '', 'force_from_name' => 0, 'reply_to_original' => 1 ) );

nve_section( 'Errors and retries' );
nve_fake_reset();
$GLOBALS['nve_fake']['send'][] = array( 429, array( 'error' => array( 'code' => 429, 'message' => 'User-rate limit exceeded.', 'errors' => array( array( 'reason' => 'rateLimitExceeded' ) ) ) ) );
nve_ok( wp_mail( 'a@example.org', 'Busy', 'Hi' ) && 2 === count( nve_calls( 'send' ) ), 'a rate limit is retried once' );

nve_fake_reset();
$GLOBALS['nve_fake']['send'][] = array( 401, array( 'error' => array( 'code' => 401, 'message' => 'Invalid Credentials' ) ) );
nve_ok( wp_mail( 'a@example.org', 'Expired', 'Hi' ) && 2 === count( nve_calls( 'send' ) ) && 1 === count( nve_calls( 'token' ) ), 'a 401 refreshes the token and retries' );

nve_fake_reset();
$failed                        = null;
$nve_catch                     = static function ( $e ) use ( &$failed ) {
	$failed = $e;
};
add_action( 'wp_mail_failed', $nve_catch );
$GLOBALS['nve_fake']['send'][] = array( 400, array( 'error' => array( 'code' => 400, 'message' => 'Invalid To header', 'errors' => array( array( 'reason' => 'invalidArgument' ) ) ) ) );
$ok                            = wp_mail( 'a@example.org', 'Bad', 'Hi' );
$entry                         = nve_last_log();
nve_ok( false === $ok && 1 === count( nve_calls( 'send' ) ), 'a 400 fails without retrying' );
nve_ok( is_wp_error( $failed ) && false !== strpos( $failed->get_error_message(), 'Invalid To header' ), 'wp_mail_failed gets Gmail\'s reason' );
nve_ok( 'failed' === $entry['status'] && false !== strpos( $entry['error'], 'Invalid To header' ), 'logged as failed with the reason' );
nve_ok( 'connected' === nve_google()->state()['state'], 'a bad message doesn\'t mark the connection broken' );
nve_ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Log::table() . ' WHERE subject = %s', 'Bad' ) ), 'logged once (not again by wp_mail_failed)' );

nve_fake_reset();
$GLOBALS['nve_fake']['send'][] = array( 403, array( 'error' => array( 'code' => 403, 'message' => 'Request had insufficient authentication scopes.', 'errors' => array( array( 'reason' => 'insufficientPermissions' ) ) ) ) );
wp_mail( 'a@example.org', 'Scope', 'Hi' );
nve_ok( 'error' === nve_google()->state()['state'] && 'scope' === nve_google()->state()['code'], 'missing permission marks the connection broken' );
nve_fake_reset();
wp_mail( 'a@example.org', 'Works again', 'Hi' );
nve_ok( 'connected' === nve_google()->state()['state'], 'a successful send clears it' );
remove_action( 'wp_mail_failed', $nve_catch );

$big = nve_google()->send( str_repeat( 'a', 35 * 1024 * 1024 + 1 ) );
nve_ok( is_wp_error( $big ) && 'too_large' === $big->get_error_code(), 'messages over 35 MB are refused before uploading' );

nve_ok( false === wp_mail( array(), 'Nobody', 'Hi' ) && 'failed' === nve_last_log()['status'], 'no recipients: failed and logged' );

nve_section( 'Pausing' );
nve_fake_reset();
Settings::update( array( 'pause' => 1 ) );
$ok = wp_mail( 'a@example.org', 'Paused', 'Hi' );
nve_ok( true === $ok && 0 === count( nve_calls( 'send' ) ) && 'paused' === nve_last_log()['status'], 'paused: reported sent, logged, not uploaded' );
Settings::update( array( 'pause' => 0 ) );
putenv( 'NEMVO_EMAIL_PAUSE=1' );
wp_mail( 'a@example.org', 'Paused by env', 'Hi' );
nve_ok( Settings::paused() && Settings::pause_locked() && 'paused' === nve_last_log()['status'] && 0 === count( nve_calls( 'send' ) ), 'NEMVO_EMAIL_PAUSE pauses too' );
putenv( 'NEMVO_EMAIL_PAUSE' );

nve_section( 'Content, resend and hooks' );
nve_fake_reset();
Settings::update( array( 'log_content' => 1 ) );
add_action(
	'phpmailer_init',
	$nve_alt = static function ( $m ) {
		$m->AltBody = 'Plain version';
	}
);
wp_mail( array( 'Anna <anna@example.org>' ), 'Kept', '<b>Kept body</b>', array( 'Content-Type: text/html', 'Cc: carl@example.org' ) );
remove_action( 'phpmailer_init', $nve_alt );
$kept = nve_last_log();
nve_ok( '<b>Kept body</b>' === $kept['body'] && 'Plain version' === $kept['alt_body'] && $kept['has_content'], 'content kept when turned on' );
$resend = Mailer::resend( $kept );
$again  = nve_last_log();
$mime   = nve_last_mime();
nve_ok( $resend['ok'] && $again['id'] !== $kept['id'] && 'Kept' === $again['subject'], 'resend sends it again as a new entry' );
nve_ok( 'Anna <anna@example.org>' === nve_header( $mime, 'To' ) && 'carl@example.org' === nve_header( $mime, 'Cc' ) && false !== strpos( $mime, 'Plain version' ), 'resend keeps recipients and both bodies' );
nve_ok( $kept['source'] === $again['source'], 'resend keeps the original source' );
Settings::update( array( 'log_content' => 0 ) );
$no_content = Log::get( $entry['id'] );
nve_ok( ! Mailer::resend( $no_content )['ok'], 'resend of an entry without content is refused' );

$seen = array();
add_action(
	'nemvo_email_sent',
	$nve_sent = static function ( $mail, $extra, $provider ) use ( &$seen ) {
		$seen[] = array( $mail->Subject, $extra['message_id'], $provider );
	},
	10,
	3
);
add_filter( 'nemvo_email_log_entry', $nve_skip = static function ( $row ) {
	return 'Secret' === $row['subject'] ? false : $row;
} );
$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Log::table() );
wp_mail( 'a@example.org', 'Secret', 'Hi' );
nve_ok( $before === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Log::table() ), 'nemvo_email_log_entry can leave a message out of the log' );
nve_ok( $seen && 'Secret' === $seen[0][0] && 'google' === $seen[0][2] && '' !== $seen[0][1], 'nemvo_email_sent fires with the message id' );
remove_action( 'nemvo_email_sent', $nve_sent );
remove_filter( 'nemvo_email_log_entry', $nve_skip );

nve_section( 'Other mailers and short-circuits' );
class NVE_Test_Other_Mailer extends PHPMailer\PHPMailer\PHPMailer {
	public $sent = 0;
	public function send() {
		++$this->sent;
		return true;
	}
}
nve_fake_reset();
$GLOBALS['phpmailer'] = new NVE_Test_Other_Mailer( true );
wp_mail( 'a@example.org', 'Connected beats others', 'Hi' );
nve_ok( $GLOBALS['phpmailer'] instanceof NemvoEmail\Message && 1 === count( nve_calls( 'send' ) ), 'while connected, another plugin\'s PHPMailer is replaced' );

add_filter( 'pre_wp_mail', $nve_short = static function () {
	return true;
}, 5 );
nve_fake_reset();
Mailer::$last_log_id = 0;
nve_ok( true === wp_mail( 'a@example.org', 'Short-circuited', 'Hi' ) && 0 === count( nve_calls( 'send' ) ) && 0 === Mailer::$last_log_id, 'pre_wp_mail answered by someone else is respected' );
remove_filter( 'pre_wp_mail', $nve_short, 5 );

nve_google()->disconnect( false );
$other                = new NVE_Test_Other_Mailer( true );
$GLOBALS['phpmailer'] = $other;
Mailer::$last_log_id  = 0;
wp_mail( 'a@example.org', 'Not connected, other plugin', 'Hi', array( 'From: site@example.com' ) );
nve_ok( 1 === $other->sent && $GLOBALS['phpmailer'] === $other && 0 === Mailer::$last_log_id, 'not connected: another plugin\'s PHPMailer is left alone' );
unset( $GLOBALS['phpmailer'] );

nve_section( 'Without a connection' );
add_action(
	'phpmailer_init',
	$nve_smtp = static function ( $m ) {
		$m->isSMTP();
		$m->Host = 'mailpit';
		$m->Port = 1025;
	}
);
nve_fake_reset();
$ok    = wp_mail( 'a@example.org', 'Server mail', 'Hi', array( 'From: site@example.com' ) );
$entry = nve_last_log();
nve_ok( $ok && 0 === count( nve_calls( 'send' ) ) && 'smtp' === $entry['provider'] && 'sent' === $entry['status'], 'not connected: WordPress\'s own transport (SMTP set in phpmailer_init) is used and logged' );
nve_ok( 'site@example.com' === $entry['from_email'], '... without touching the sender' );
remove_action( 'phpmailer_init', $nve_smtp );

add_filter( 'wp_mail_from', $nve_badfrom = static function () {
	return 'not an address';
}, 20 );
Mailer::$last_log_id = 0;
$before              = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Log::table() );
nve_ok( false === wp_mail( 'a@example.org', 'Invalid sender', 'Hi' ), 'invalid sender fails in wp_mail()' );
$row = $wpdb->get_row( 'SELECT status, subject, error, to_email FROM ' . Log::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
nve_ok( 'Invalid sender' === $row['subject'] && 'failed' === $row['status'] && 'a@example.org' === $row['to_email'] && '' !== $row['error'], 'a failure before sending is logged from wp_mail_failed' );
remove_filter( 'wp_mail_from', $nve_badfrom, 20 );

nve_section( 'Where email comes from' );
$src = Mailer::source_of( WP_PLUGIN_DIR . '/nemvo-email/includes/class-mailer.php' );
nve_ok( 'plugin:nemvo-email' === $src['key'] && 'Nemvo Email' === $src['name'], 'plugin file: ' . $src['name'] );
nve_ok( 'plugin:some-gone-plugin' === Mailer::source_of( WP_PLUGIN_DIR . '/some-gone-plugin/x.php' )['key'] && 'Some Gone Plugin' === Mailer::source_of( WP_PLUGIN_DIR . '/some-gone-plugin/x.php' )['name'], 'plugin without a header: name from the folder' );
nve_ok( 'core' === Mailer::source_of( ABSPATH . 'wp-includes/pluggable.php' )['key'] && 'core' === Mailer::source_of( ABSPATH . 'wp-login.php' )['key'], 'WordPress core files' );
nve_ok( 'theme:twentytwentyfive' === Mailer::source_of( get_theme_root() . '/twentytwentyfive/functions.php' )['key'], 'theme file' );
nve_ok( 'mu-plugin:dev-mailpit.php' === Mailer::source_of( WPMU_PLUGIN_DIR . '/dev-mailpit.php' )['key'], 'mu-plugin file' );
nve_ok( '' === Mailer::source_of( WP_CONTENT_DIR . '/object-cache.php' )['key'], 'drop-ins are not core' );
nve_connect();
$test = Mailer::send_test( 'a@example.org' );
nve_ok( $test['ok'] && 'plugin:nemvo-email' === Log::get( $test['log_id'] )['source'], 'the test email is sent and attributed to the plugin' );
nve_ok( (bool) Settings::get( 'test_sent' ), 'a successful test is remembered for the setup guide' );

/* ================================================================== *
 * Log and statistics
 * ================================================================== */

nve_section( 'Log' );
nve_ok( 'Your order # is complete' === Log::subject_key( 'Your order #1041 is complete' ), 'subject key: order number' );
nve_ok( 'Booking on # at #' === Log::subject_key( 'Booking on 2026-11-12 at 14:30' ), 'subject key: date and time' );
nve_ok( 'Welcome … to the site' === Log::subject_key( 'Welcome  anna@example.org to the site' ), 'subject key: address and spaces' );

Log::clear();
$tz   = wp_timezone();
$day  = static function ( $back, $hour = 12 ) use ( $tz ) {
	return ( new DateTimeImmutable( 'today', $tz ) )->modify( "-$back days" )->setTime( $hour, 0 )->getTimestamp();
};
$rows = array(
	array( 0, 'sent', 'plugin:woocommerce', 'WooCommerce', 'Order #1 received', 'a@gmail.com' ),
	array( 0, 'sent', 'plugin:woocommerce', 'WooCommerce', 'Order #2 received', 'b@gmail.com' ),
	array( 0, 'failed', 'core', 'WordPress', 'Password Reset', 'c@outlook.com' ),
	array( 2, 'sent', 'core', 'WordPress', 'Password Reset', 'd@outlook.com' ),
	array( 2, 'paused', 'core', 'WordPress', 'Password Reset', 'e@acme.test' ),
	array( 8, 'sent', 'plugin:woocommerce', 'WooCommerce', 'Order #3 received', 'f@gmail.com' ),
	array( 40, 'sent', 'core', 'WordPress', 'Old', 'g@gmail.com' ),
);
foreach ( $rows as $r ) {
	Log::insert(
		array(
			'time'       => $day( $r[0], 0 === $r[0] ? 1 : 12 ),
			'status'     => $r[1],
			'provider'   => 'google',
			'source'     => array( 'key' => $r[2], 'name' => $r[3] ),
			'subject'    => $r[4],
			'recipients' => array( 'to' => array( array( $r[5], '' ) ) ),
			'error'      => 'failed' === $r[1] ? 'Gmail refused the message (400): Invalid To header' : '',
		)
	);
}
$today = current_datetime()->format( 'Y-m-d' );
$all   = Log::query( array( 'from' => wp_date( 'Y-m-d', $day( 10 ) ) ) );
nve_ok( 6 === $all['total'], 'query by date: ' . $all['total'] );
nve_ok( 1 === Log::query( array( 'status' => 'failed', 'from' => $today ) )['total'], 'query by status' );
nve_ok( 3 === Log::query( array( 'source' => 'plugin:woocommerce', 'from' => wp_date( 'Y-m-d', $day( 10 ) ) ) )['total'], 'query by source' );
nve_ok( 2 === Log::query( array( 'domain' => 'outlook.com' ) )['total'], 'query by recipient domain' );
nve_ok( 3 === Log::query( array( 'subject' => 'Order # received', 'from' => wp_date( 'Y-m-d', $day( 30 ) ) ) )['total'], 'query by email type' );
nve_ok( 1 === Log::query( array( 'search' => 'c@outlook', 'from' => $today ) )['total'] && 1 === Log::query( array( 'search' => 'Invalid To', 'from' => $today ) )['total'], 'search in recipients and errors' );
nve_ok( 2 === count( Log::query( array( 'per_page' => 2, 'from' => wp_date( 'Y-m-d', $day( 10 ) ) ) )['rows'] ) && 3 === Log::query( array( 'per_page' => 2, 'from' => wp_date( 'Y-m-d', $day( 10 ) ) ) )['pages'], 'paging' );
delete_transient( Log::FAILED_TRANSIENT );
nve_ok( Log::failed_recently() >= 1, 'failed in the last 24 hours (menu count)' );

nve_section( 'Statistics' );
$week = Stats::report( wp_date( 'Y-m-d', $day( 6 ) ), $today );
nve_ok( 3 === $week['totals']['sent'] && 1 === $week['totals']['failed'] && 1 === $week['totals']['paused'] && 3 === $week['totals']['recipients'], 'totals: ' . wp_json_encode( $week['totals'] ) );
nve_ok( 0.75 === $week['totals']['rate'], 'delivery rate = sent / (sent + failed)' );
nve_ok( 1 === $week['previous']['sent'] && wp_date( 'Y-m-d', $day( 13 ) ) === $week['previous']['from'], 'previous period' );
nve_ok( 'day' === $week['interval'] && 7 === count( $week['series'] ) && $today === end( $week['series'] )['key'], 'daily series with every day' );
$last = end( $week['series'] );
nve_ok( 2 === $last['sent'] && 1 === $last['failed'], 'today\'s bucket' );
nve_ok( 'core' === $week['sources'][0]['key'] && 3 === $week['sources'][0]['total'] && 1 === $week['sources'][0]['failed'] && 'WooCommerce' === $week['sources'][1]['name'] && 2 === $week['sources'][1]['sent'], 'sources ranked, with names and failures' );
nve_ok( 'Password Reset' === $week['subjects'][0]['key'] && 'Order # received' === $week['subjects'][1]['key'] && 2 === $week['subjects'][1]['total'], 'email types grouped' );
nve_ok( array( 'gmail.com', 'outlook.com', 'acme.test' ) === wp_list_pluck( $week['domains'], 'key' ), 'recipient domains, ties in name order' );
nve_ok( 1 === count( $week['errors'] ) && 1 === $week['errors'][0]['count'] && $today === substr( $week['errors'][0]['last'], 0, 10 ), 'errors with the last time (site time)' );
$hourly = Stats::report( $today, $today );
nve_ok( 'hour' === $hourly['interval'] && 24 === count( $hourly['series'] ) && 2 === $hourly['series'][1]['sent'], 'one day: hourly series' );
$year = Stats::report( wp_date( 'Y-m-d', $day( 364 ) ), $today );
nve_ok( 'week' === $year['interval'] && count( $year['series'] ) >= 52 && count( $year['series'] ) <= 54, 'a year: weekly series (' . count( $year['series'] ) . ')' );
nve_ok( 7 === array_sum( array_map( static function ( $s ) { return $s['sent'] + $s['failed'] + $s['paused']; }, $year['series'] ) ), 'weekly buckets add up' );

nve_section( 'Retention' );
Settings::update( array( 'log_retention' => 30 ) );
$deleted = Log::purge();
nve_ok( 1 === $deleted && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Log::table() . ' WHERE subject = %s', 'Old' ) ), 'entries older than the retention are purged' );
Settings::update( array( 'log_retention' => 90 ) );

/* ================================================================== *
 * REST API
 * ================================================================== */

nve_section( 'REST API' );
nve_reset();
nve_connect();
$req = new WP_REST_Request( 'GET', '/nemvo-email/v1/stats' );
$req->set_query_params( array( 'from' => wp_date( 'Y-m-d', $day( 6 ) ), 'to' => $today ) );
$res = rest_do_request( $req );
nve_ok( 200 === $res->get_status() && isset( $res->get_data()['totals'], $res->get_data()['quota'] ), 'GET /stats' );
$req->set_query_params( array( 'from' => $today, 'to' => wp_date( 'Y-m-d', $day( 3 ) ) ) );
nve_ok( 400 === rest_do_request( $req )->get_status(), 'GET /stats refuses a reversed period' );
$req = new WP_REST_Request( 'GET', '/nemvo-email/v1/log' );
$req->set_query_params( array( 'status' => 'failed' ) );
$res = rest_do_request( $req );
nve_ok( 200 === $res->get_status() && $res->get_data()['total'] >= 1 && isset( $res->get_data()['sources'] ), 'GET /log with filters and sources' );
$id  = $res->get_data()['rows'][0]['id'];
$res = rest_do_request( new WP_REST_Request( 'GET', '/nemvo-email/v1/log/' . $id ) );
nve_ok( 200 === $res->get_status() && array_key_exists( 'body', $res->get_data() ) && array_key_exists( 'message_url', $res->get_data() ), 'GET /log/<id> with details' );
nve_ok( 404 === rest_do_request( new WP_REST_Request( 'GET', '/nemvo-email/v1/log/999999999' ) )->get_status(), 'GET /log/<missing> is 404' );
nve_fake_reset();
$req = new WP_REST_Request( 'POST', '/nemvo-email/v1/test' );
$req->set_body_params( array( 'to' => 'tester@example.org' ) );
$res = rest_do_request( $req );
nve_ok( 200 === $res->get_status() && $res->get_data()['ok'] && 'sent' === $res->get_data()['entry']['status'] && 1 === count( nve_calls( 'send' ) ), 'POST /test sends and returns the log entry' );
$req->set_body_params( array( 'to' => 'nope' ) );
nve_ok( 400 === rest_do_request( $req )->get_status(), 'POST /test checks the address' );
$del = $res->get_data()['entry']['id'];
$res = rest_do_request( new WP_REST_Request( 'DELETE', '/nemvo-email/v1/log/' . $del ) );
nve_ok( 1 === $res->get_data()['deleted'] && null === Log::get( $del ), 'DELETE /log/<id>' );
$sub = wp_insert_user( array( 'user_login' => 'nve_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
nve_ok( 403 === rest_do_request( new WP_REST_Request( 'GET', '/nemvo-email/v1/log' ) )->get_status(), 'subscribers get 403' );
wp_set_current_user( 1 );
wp_delete_user( $sub );

/* ================================================================== *
 * DNS
 * ================================================================== */

nve_section( 'DNS' );
nve_reset();
nve_connect( 'site@acme.test' );
$google_spf = array(
	'TXT _spf.google.com'        => array( 'v=spf1 include:_netblocks.google.com include:_netblocks2.google.com include:_netblocks3.google.com ~all' ),
	'TXT _netblocks.google.com'  => array( 'v=spf1 ip4:35.190.247.0/24 ip4:64.233.160.0/19 ~all' ),
	'TXT _netblocks2.google.com' => array( 'v=spf1 ip6:2001:4860:4000::/36 ~all' ),
	'TXT _netblocks3.google.com' => array( 'v=spf1 ip4:172.217.0.0/19 ~all' ),
);
$key2048 = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAupZW3VtxrwVE28ZpcmLEuI91tUnmvHMUqRpSMgGXhWz1U1REuhwNlqulnZSRIFmmhuMNDSltpPUTxuOgWG9Sm/xJrx5mVG4xfU50dQUfNeRMaQMrSwd1NJWjsweqLbXrb7XyMiRo12CVEEamMMCB4iOVULkT0lmkOqo1k/jhQpPbQUXsuyFXwPS433sjPwJvxHay8y7kGYbalF0FnXy5cMC+cWYp8cIMretGBPyF1fT2ezI7DOUXt43mQaZDlZAmIp+By2h5C3xkpWePUBY53sUYXskCB1eKjFpjvKmlUekUBzZBRxS4MrFDPDTXKfkVQDrJ0K6b9OEBoLqpTafP9QIDAQAB';
$key1024 = 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC99K1uNTr9F/eOe4aSLLj5ImAW+EMpmm9W1mHdLRmlbksrWqsMlVilCtJdEP/K+qev4vBzJ7avGw7GNoS3xhgbIqZtrVNZvmfS/F5cbUWEw46yCYSZzYaaw41ligJI8DRbWNPZPd+E44mGMd6veg/Tax1SElMcMrLivhrw7GvZCQIDAQAB';
$check   = static function ( $zone, $domain = 'acme.test' ) use ( $google_spf ) {
	$GLOBALS['nve_dns'] = $zone + $google_spf;
	return Dns::check( $domain, nve_google(), false );
};

$r = $check( array() );
nve_ok( 'critical' === $r['checks']['spf']['state'] && 'v=spf1 include:_spf.google.com ~all' === $r['checks']['spf']['fix']['value'] && 'add' === $r['checks']['spf']['fix']['mode'], 'no SPF: add one' );
nve_ok( '@' === $r['checks']['spf']['fix']['relative'], 'record name relative to the zone: @' );
nve_ok( 'critical' === $r['checks']['dkim']['state'] && 4 === count( $r['checks']['dkim']['steps'] ) && 'google._domainkey.acme.test' === $r['checks']['dkim']['name'], 'no DKIM: four steps in the Admin console' );
nve_ok( 'warning' === $r['checks']['dmarc']['state'] && 'v=DMARC1; p=none; rua=mailto:site@acme.test' === $r['checks']['dmarc']['fix']['value'] && '_dmarc' === $r['checks']['dmarc']['fix']['relative'], 'no DMARC: a monitoring policy with reports to the account' );
nve_ok( 'warning' === $r['checks']['mx']['state'], 'no MX: replies would bounce' );
nve_ok( 'critical' === $r['state'], 'overall: the worst state' );

$r = $check( array( 'TXT acme.test' => array( 'v=spf1 include:spf.protection.outlook.com -all', 'google-site-verification=abc' ) ) );
nve_ok( 'critical' === $r['checks']['spf']['state'] && 'v=spf1 include:spf.protection.outlook.com include:_spf.google.com -all' === $r['checks']['spf']['fix']['value'] && 'replace' === $r['checks']['spf']['fix']['mode'], 'SPF without Google: include added before -all' );

$r = $check( array( 'TXT acme.test' => array( 'v=spf1 a mx ~all', 'v=spf1 include:_spf.google.com -all' ) ) );
nve_ok( 'critical' === $r['checks']['spf']['state'] && 'v=spf1 a mx include:_spf.google.com -all' === $r['checks']['spf']['fix']['value'], 'two SPF records: merged into one (' . $r['checks']['spf']['fix']['value'] . ')' );

$r = $check( array( 'TXT acme.test' => array( 'v=spf1 include:_spf.acme-mail.test ~all' ), 'TXT _spf.acme-mail.test' => array( 'v=spf1 include:_spf.google.com ~all' ) ) );
nve_ok( 'good' === $r['checks']['spf']['state'] && false !== strpos( $r['checks']['spf']['detail'], '5 DNS lookups' ), 'Google through a nested include, lookups counted (' . $r['checks']['spf']['detail'] . ')' );

$many = 'v=spf1 include:_spf.google.com a mx include:a.test include:b.test include:c.test include:d.test include:e.test ~all';
$r    = $check( array( 'TXT acme.test' => array( $many ) ) );
nve_ok( 'critical' === $r['checks']['spf']['state'] && false !== strpos( $r['checks']['spf']['title'], '11' ), 'over 10 lookups: ' . $r['checks']['spf']['title'] );

$r = $check( array( 'TXT acme.test' => array( 'v=spf1 include:_spf.google.com +all' ) ) );
nve_ok( 'warning' === $r['checks']['spf']['state'] && 'v=spf1 include:_spf.google.com ~all' === $r['checks']['spf']['fix']['value'], '+all: change to ~all' );

$r = $check( array( 'TXT acme.test' => array( 'v=spf1 include:_spf.google.com' ) ) );
nve_ok( 'warning' === $r['checks']['spf']['state'] && 'v=spf1 include:_spf.google.com ~all' === $r['checks']['spf']['fix']['value'], 'no ending: add ~all' );

$base = array(
	'TXT acme.test' => array( 'v=spf1 include:_spf.google.com ~all' ),
	'MX acme.test'  => array( array( 'host' => 'smtp.google.com', 'pri' => 1 ) ),
	'NS acme.test'  => array( 'ns1.desec.io', 'ns2.desec.org' ),
);
$r = $check( $base + array( 'TXT google._domainkey.acme.test' => array( 'v=DKIM1; k=rsa; p=' . $key2048 ), 'TXT _dmarc.acme.test' => array( 'v=DMARC1; p=reject; rua=mailto:dmarc@acme.test' ) ) );
nve_ok( 'good' === $r['state'] && 2048 === $r['checks']['dkim']['bits'], 'everything in order: SPF, 2048-bit DKIM, DMARC reject' );
nve_ok( 'good' === $r['checks']['mx']['state'] && 'deSEC' === $r['host']['name'], 'MX at Google; DNS hosted at deSEC' );
nve_ok( false !== strpos( $r['checks']['dmarc']['detail'], 'dmarc@acme.test' ), 'DMARC report address shown' );
nve_ok( false !== strpos( $r['checks']['dkim']['found'][0], '…' ), 'long DKIM key shortened for display' );

$r = $check( $base + array( 'TXT google._domainkey.acme.test' => array( 'v=DKIM1; k=rsa; p=' . $key1024 ) ) );
nve_ok( 'warning' === $r['checks']['dkim']['state'] && 1024 === $r['checks']['dkim']['bits'], '1024-bit DKIM: works, upgrade recommended' );
$r = $check( $base + array( 'TXT google._domainkey.acme.test' => array( 'v=DKIM1; p=' ) ) );
nve_ok( 'critical' === $r['checks']['dkim']['state'] && false !== strpos( $r['checks']['dkim']['title'], 'revoked' ), 'empty DKIM key: revoked' );
Settings::update( array( 'dkim_selector' => 'mail2026' ) );
$r = $check( $base + array( 'TXT mail2026._domainkey.acme.test' => array( 'v=DKIM1; k=rsa; p=' . $key2048 ) ) );
nve_ok( 'good' === $r['checks']['dkim']['state'] && 'mail2026' === $r['checks']['dkim']['selector'], 'custom DKIM selector' );
Settings::update( array( 'dkim_selector' => '' ) );

// Sending as an alias in another domain: SPF can't align, so DMARC needs DKIM.
Settings::update( array( 'from_email' => 'info@brand.test' ) );
$alias = array(
	'TXT brand.test'        => array( 'v=spf1 include:_spf.google.com ~all' ),
	'TXT _dmarc.brand.test' => array( 'v=DMARC1; p=reject' ),
	'MX brand.test'         => array( array( 'host' => 'smtp.google.com', 'pri' => 1 ) ),
);
$r     = $check( $alias, 'brand.test' );
nve_ok( 'critical' === $r['checks']['dmarc']['state'] && false !== strpos( $r['checks']['dmarc']['title'], 'reject' ), 'alias domain without DKIM and p=reject: email would be rejected' );
$alias['TXT _dmarc.brand.test'] = array( 'v=DMARC1; p=none' );
$r                              = $check( $alias, 'brand.test' );
nve_ok( 'warning' === $r['checks']['dmarc']['state'], '... with p=none: fails DMARC, nothing blocked yet' );
$alias['TXT google._domainkey.brand.test'] = array( 'v=DKIM1; k=rsa; p=' . $key2048 );
$alias['TXT _dmarc.brand.test']            = array( 'v=DMARC1; p=quarantine' );
$r                                         = $check( $alias, 'brand.test' );
nve_ok( 'good' === $r['checks']['dmarc']['state'], '... with DKIM it passes' );
nve_ok( 'info@brand.test' === Mailer::from_email() && 'brand.test' === Mailer::from_domain(), 'sender domain follows the From setting' );
Settings::update( array( 'from_email' => '' ) );

$r = $check( array( 'TXT mail.acme.test' => array( 'v=spf1 include:_spf.google.com ~all' ), 'TXT _dmarc.acme.test' => array( 'v=DMARC1; p=quarantine; sp=none' ), 'TXT google._domainkey.mail.acme.test' => array( 'v=DKIM1; p=' . $key2048 ) ), 'mail.acme.test' );
nve_ok( '_dmarc.acme.test' === $r['checks']['dmarc']['name'] && 'none' === $r['checks']['dmarc']['policy'], 'subdomain: the organisational DMARC record and its sp= policy' );
nve_ok( 'google._domainkey.mail' === Dns::relative( 'google._domainkey.mail.acme.test', 'acme.test' ), 'relative name for a subdomain' );

$r = $check( array(), 'gmail.com' );
nve_ok( $r['personal'] && array() === $r['checks'] && 'good' === $r['state'], 'gmail.com: nothing to publish' );

nve_ok( 'example.co.uk' === Dns::org_domain( 'mail.example.co.uk' ) && 'acme.test' === Dns::org_domain( 'a.b.acme.test' ), 'organisational domain' );
nve_ok( 'v=spf1 a include:x ~all' === Dns::spf_add( 'v=spf1 a ~all', 'x' ) && 'v=spf1 a include:x ~all' === Dns::spf_add( 'v=spf1 a', 'x' ) && 'v=spf1 include:x redirect=y' === Dns::spf_add( 'v=spf1 redirect=y', 'x' ), 'spf_add() places the include' );
nve_ok( array( 'v' => 'DMARC1', 'p' => 'none', 'rua' => 'mailto:a@b' ) === Dns::tags( 'v=DMARC1; p=none; rua=mailto:a@b' ), 'tag parser' );
$GLOBALS['nve_dns'] = array( 'NS cloud.test' => array( 'kate.ns.cloudflare.com' ) );
nve_ok( 'Cloudflare' === Dns::dns_host( 'cloud.test' )['name'] && '' === Dns::dns_host( 'nothing.test' )['name'], 'DNS host from name servers' );

$GLOBALS['nve_dns_live'] = true;
$live                    = Dns::lookup( 'google.com', 'TXT' );
if ( $live ) {
	$r = Dns::check( 'google.com', nve_google(), false );
	nve_ok( 'good' === $r['checks']['spf']['state'] && 'good' === $r['checks']['mx']['state'], 'live DNS: google.com passes SPF and MX through the server\'s resolver' );
} else {
	WP_CLI::log( 'skip live DNS (no network)' );
}
$GLOBALS['nve_dns_live'] = false;

/* ================================================================== *
 * Import from WP Mail SMTP
 * ================================================================== */

nve_section( 'Import from WP Mail SMTP' );
nve_reset();
delete_option( 'wp_mail_smtp' );
nve_ok( null === Import::wp_mail_smtp(), 'nothing to import without WP Mail SMTP' );
update_option( 'wp_mail_smtp', array( 'mail' => array( 'mailer' => 'mail', 'from_email' => 'admin@example.com' ) ) );
nve_ok( null === Import::wp_mail_smtp(), 'untouched defaults are not offered' );

if ( ! file_exists( WP_PLUGIN_DIR . '/wp-mail-smtp/wp_mail_smtp.php' ) ) {
	WP_CLI::runcommand( 'plugin install wp-mail-smtp --quiet' );
}
if ( file_exists( WP_PLUGIN_DIR . '/wp-mail-smtp/wp_mail_smtp.php' ) ) {
	// Let WP Mail SMTP write its own settings, the way its settings page does.
	activate_plugin( 'wp-mail-smtp/wp_mail_smtp.php' );
	require_once WP_PLUGIN_DIR . '/wp-mail-smtp/wp_mail_smtp.php';
	if ( function_exists( 'wp_mail_smtp' ) ) {
		WPMailSMTP\Options::init()->set(
			array(
				'mail'  => array(
					'mailer'           => 'gmail',
					'from_email'       => 'website@acme.test',
					'from_name'        => 'Acme Website',
					'from_email_force' => true,
					'from_name_force'  => true,
					'return_path'      => true,
				),
				'gmail' => array(
					'client_id'     => '1234567890-wpmailsmtp.apps.googleusercontent.com',
					'client_secret' => 'GOCSPX-from-wp-mail-smtp-012345',
				),
			),
			false,
			true
		);
		$all                                 = get_option( 'wp_mail_smtp' );
		$all['gmail']['refresh_token']       = '1//0g-wpms-refresh-token';
		$all['gmail']['access_token']        = array( 'access_token' => 'ya29.old', 'expires_in' => 3599, 'created' => time() - 7200 );
		$all['gmail']['user_details']        = array( 'email' => 'website@acme.test' );
		update_option( 'wp_mail_smtp', $all );
	}
	$found = Import::wp_mail_smtp();
	nve_ok( $found && 'gmail' === $found['mailer'] && $found['gmail']['connected'] && 'website@acme.test' === $found['gmail']['email'] && 'wp-mail-smtp/wp_mail_smtp.php' === $found['active'], 'WP Mail SMTP\'s Gmail mailer is found (as written by WP Mail SMTP ' . ( defined( 'WPMS_PLUGIN_VER' ) ? WPMS_PLUGIN_VER : '?' ) . ')' );

	nve_fake_reset();
	$result  = Import::run();
	$refresh = nve_calls( 'token' );
	nve_ok( ! is_wp_error( $result ) && true === $result['connection'], 'import connects: ' . ( is_wp_error( $result ) ? $result->get_error_message() : implode( ', ', $result['imported'] ) ) );
	nve_ok( $refresh && '1//0g-wpms-refresh-token' === $refresh[0]['args']['body']['refresh_token'] && '1234567890-wpmailsmtp.apps.googleusercontent.com' === $refresh[0]['args']['body']['client_id'] && 'GOCSPX-from-wp-mail-smtp-012345' === $refresh[0]['args']['body']['client_secret'], 'the imported token is refreshed with the imported client' );
	nve_ok( 'website@acme.test' === nve_google()->account() && 'wp-mail-smtp' === nve_google()->imported(), 'connected as the WP Mail SMTP account' );
	nve_ok( '' === Settings::get( 'from_email' ) && 'Acme Website' === Settings::get( 'from_name' ) && 1 === Settings::get( 'force_from_name' ), 'sender imported (address = account, so empty)' );
	nve_fake_reset();
	nve_ok( wp_mail( 'a@example.org', 'After import', 'Hi' ) && 'Acme Website <website@acme.test>' === nve_header( nve_last_mime(), 'From' ), 'email goes through the imported connection' );
	nve_ok( in_array( 'WP Mail SMTP', Health::other_mailers(), true ), 'WP Mail SMTP is reported as another email plugin' );

	nve_fake_reset();
	$provider = nve_google();
	nve_ok( 'wp-mail-smtp' === $provider->imported() && '' !== Import::active_plugin(), 'shared authorization while WP Mail SMTP is active (not revoked on disconnect)' );

	$all                                     = get_option( 'wp_mail_smtp' );
	$all['gmail']['one_click_setup_enabled'] = true;
	update_option( 'wp_mail_smtp', $all );
	nve_ok( ! Import::wp_mail_smtp()['gmail']['connected'] && Import::wp_mail_smtp()['gmail']['one_click'], 'One-Click Setup connections are not importable' );

	nve_ok( Import::deactivate() && '' === Import::active_plugin(), 'WP Mail SMTP can be deactivated from here' );
} else {
	WP_CLI::log( 'skip WP Mail SMTP plugin tests (could not install it)' );
}

nve_reset();
update_option(
	'wp_mail_smtp',
	array(
		'mail'    => array( 'mailer' => 'smtp', 'from_email' => 'Info@Acme.test', 'from_name' => 'Acme', 'from_email_force' => false ),
		'smtp'    => array( 'host' => 'smtp.gmail.com' ),
		'general' => array( 'do_not_send' => true ),
	)
);
$result = Import::run();
nve_ok( ! is_wp_error( $result ) && null === $result['connection'] && 'info@acme.test' === Settings::get( 'from_email' ) && 0 === Settings::get( 'force_from_email' ) && Settings::paused(), 'SMTP mailer: sender, forcing and "Do not send" imported, no connection' );

/* ================================================================== *
 * Health and privacy
 * ================================================================== */

nve_section( 'Health' );
nve_reset();
$ids = wp_list_pluck( Health::checks(), 'id' );
nve_ok( 'critical' === Health::checks()[0]['state'] && 'connection' === $ids[0], 'not connected: critical' );
nve_connect();
$GLOBALS['nve_dns'] = array();
$checks             = Health::checks();
nve_ok( 'good' === $checks[0]['state'] && in_array( 'dns', wp_list_pluck( $checks, 'id' ), true ) && in_array( 'test', wp_list_pluck( $checks, 'id' ), true ), 'connected: good, with "check DNS" and "send a test" reminders' );
Dns::check( 'acme.test', nve_google() );
$dns = array_values( array_filter( Health::checks(), static function ( $c ) { return 'dns' === $c['id']; } ) )[0];
nve_ok( 'critical' === $dns['state'] && false !== strpos( $dns['title'], 'SPF, DKIM, DMARC' ), 'DNS problems listed: ' . $dns['title'] );
add_filter( 'admin_url', $nve_http = static function ( $url ) {
	return str_replace( array( 'http://localhost:8060', 'https://localhost:8060' ), 'http://shop.example.org', $url );
} );
nve_google()->disconnect( false );
nve_ok( in_array( 'https', wp_list_pluck( Health::checks(), 'id' ), true ), 'an http:// site is told Google needs https://' );
remove_filter( 'admin_url', $nve_http );
$tests = Health::site_health( array( 'direct' => array(), 'async' => array() ) );
$first = reset( $tests['direct'] );
$out   = call_user_func( $first['test'] );
nve_ok( isset( $tests['direct']['nemvo_email_connection'] ) && 'critical' === $out['status'] && 'nemvo_email_connection' === $out['test'], 'Site Health tests' );
nve_ok( '' === Health::wp_mail_replaced(), 'wp_mail() is WordPress\'s own' );

nve_section( 'Privacy' );
nve_connect();
nve_fake_reset();
wp_mail( 'Private Person <private@example.org>', 'About your data', 'Hi' );
$export = Privacy::export( 'private@example.org' );
nve_ok( 1 === count( $export['data'] ) && 'About your data' === $export['data'][0]['data'][1]['value'], 'exporter finds log entries by recipient' );
$erase = Privacy::erase( 'private@example.org' );
nve_ok( 1 === $erase['items_removed'] && $erase['done'] && 0 === count( Privacy::export( 'private@example.org' )['data'] ), 'eraser deletes them' );

/* ------------------------------------------------------------------ *
 * Restore
 * ------------------------------------------------------------------ */

foreach ( $nve_backup as $nve_option => $nve_value ) {
	if ( '__missing__' === $nve_value ) {
		delete_option( $nve_option );
	} else {
		update_option( $nve_option, $nve_value );
	}
}
$wpdb->query( 'DROP TABLE ' . Log::table() );
$wpdb->query( 'RENAME TABLE ' . $nve_backup_table . ' TO ' . Log::table() );
delete_transient( Log::FAILED_TRANSIENT );

WP_CLI::log( sprintf( "\n%d passed, %d failed", $GLOBALS['nve_pass'], $GLOBALS['nve_fail'] ) );
if ( $GLOBALS['nve_fail'] ) {
	WP_CLI::halt( 1 );
}
