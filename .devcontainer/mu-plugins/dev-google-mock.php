<?php
/**
 * Plugin Name: Dev – fake Google (OAuth, Gmail API, DNS)
 * Description: Local development only. Answers Google's OAuth and Gmail API so Nemvo Email can be set up and used without Google credentials. Messages "sent" through the fake Gmail API are delivered to Mailpit over SMTP, exactly as uploaded (minus Bcc, like Gmail). Enabled by NEMVO_EMAIL_GOOGLE_MOCK=1.
 *
 * The consent screen is skipped: "Sign in with Google" connects hello@example.com (or the login hint).
 * Recipients trigger Gmail errors: ratelimit@example.com (429), bad-recipient@example.com (400).
 * A refresh token ending in "|revoked" behaves like a revoked authorization.
 * DNS for example.com is made up: SPF and DKIM are in place, DMARC is missing, DNS hosted at Cloudflare.
 */

if ( '1' !== getenv( 'NEMVO_EMAIL_GOOGLE_MOCK' ) ) {
	return;
}

// Skip Google's consent screen: straight back to the plugin's callback with a fake code.
add_filter(
	'nemvo_email_google_auth_url',
	static function ( $url, $params ) {
		$email = $params['login_hint'] ?? 'hello@example.com';
		return add_query_arg(
			array(
				'state' => rawurlencode( $params['state'] ),
				'code'  => rawurlencode( 'mock:' . $email ),
			),
			$params['redirect_uri']
		);
	},
	10,
	2
);

/**
 * A JSON HTTP response for pre_http_request.
 *
 * @param array $data Body.
 * @param int   $code Status.
 * @return array
 */
function nemvo_email_mock_json( $data, $code = 200 ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
		'body'     => wp_json_encode( $data ),
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Deliver a raw message to Mailpit like Gmail would: recipients from To, Cc and Bcc; Bcc removed.
 *
 * @param string $mime Message.
 * @param string $from Envelope sender.
 * @return array|WP_Error Recipients.
 */
function nemvo_email_mock_deliver( $mime, $from ) {
	list( $head, $body ) = array_pad( preg_split( "/\r?\n\r?\n/", $mime, 2 ), 2, '' );
	$head    = preg_replace( "/\r?\n[ \t]+/", ' ', $head );
	$lines   = preg_split( "/\r?\n/", $head );
	$keep    = array();
	$rcpt    = array();
	foreach ( $lines as $line ) {
		if ( preg_match( '/^(To|Cc|Bcc):\s*(.*)$/i', $line, $m ) ) {
			preg_match_all( '/[^\s<>,"]+@[^\s<>,"]+/', $m[2], $found );
			$rcpt = array_merge( $rcpt, $found[0] );
			if ( 0 === strcasecmp( $m[1], 'Bcc' ) ) {
				continue;
			}
		}
		$keep[] = $line;
	}
	if ( ! $rcpt ) {
		return new WP_Error( 'to', 'Recipient address required' );
	}
	$message = implode( "\r\n", $keep ) . "\r\n\r\n" . $body;

	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	$smtp = new PHPMailer\PHPMailer\SMTP();
	if ( ! $smtp->connect( 'mailpit', 1025, 10 ) || ! $smtp->hello( 'gmail.mock' ) || ! $smtp->mail( $from ) ) {
		return new WP_Error( 'smtp', 'Mailpit is not reachable' );
	}
	foreach ( $rcpt as $address ) {
		$smtp->recipient( $address );
	}
	$ok = $smtp->data( $message );
	$smtp->quit();
	$smtp->close();
	return $ok ? $rcpt : new WP_Error( 'smtp', 'Mailpit did not accept the message' );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! in_array( $host, array( 'oauth2.googleapis.com', 'gmail.googleapis.com' ), true ) ) {
			return $pre;
		}
		$body = $args['body'] ?? '';

		if ( '/token' === $path ) {
			$body  = is_array( $body ) ? $body : array();
			$grant = $body['grant_type'] ?? '';
			if ( 'refresh_token' === $grant && '|revoked' === substr( (string) ( $body['refresh_token'] ?? '' ), -8 ) ) {
				return nemvo_email_mock_json(
					array(
						'error'             => 'invalid_grant',
						'error_description' => 'Token has been expired or revoked.',
					),
					400
				);
			}
			$who = 'authorization_code' === $grant ? substr( (string) $body['code'], 5 ) : preg_replace( '/^mock-refresh:/', '', (string) ( $body['refresh_token'] ?? '' ) );
			if ( ! is_email( $who ) ) {
				// An imported WP Mail SMTP token or anything else: the default account.
				$who = 'hello@example.com';
			}
			$domain = substr( strrchr( $who, '@' ), 1 );
			$token  = array(
				'access_token' => 'mock-access:' . $who,
				'expires_in'   => 3599,
				'scope'        => 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/userinfo.email openid',
				'token_type'   => 'Bearer',
			);
			if ( 'authorization_code' === $grant ) {
				$claims                 = array(
					'iss'            => 'https://accounts.google.com',
					'aud'            => $body['client_id'],
					'sub'            => (string) crc32( $who ),
					'email'          => $who,
					'email_verified' => true,
				) + ( 'gmail.com' === $domain ? array() : array( 'hd' => $domain ) );
				$b64                    = static function ( $data ) {
					return rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore
				};
				$token['refresh_token'] = 'mock-refresh:' . $who;
				$token['id_token']      = $b64( array( 'alg' => 'none' ) ) . '.' . $b64( $claims ) . '.sig';
			}
			return nemvo_email_mock_json( $token );
		}

		if ( '/revoke' === $path ) {
			return nemvo_email_mock_json( array() );
		}

		$auth = $args['headers']['Authorization'] ?? '';
		if ( ! preg_match( '/^Bearer mock-access:(.+)$/', $auth, $m ) ) {
			return nemvo_email_mock_json(
				array(
					'error' => array(
						'code'    => 401,
						'message' => 'Request had invalid authentication credentials.',
						'status'  => 'UNAUTHENTICATED',
					),
				),
				401
			);
		}
		$account = $m[1];

		if ( '/gmail/v1/users/me/messages/send' === $path ) {
			return nemvo_email_mock_json(
				array(
					'error' => array(
						'code'    => 400,
						'message' => "'raw' RFC822 payload message string or uploading message via /upload/* URL required",
						'status'  => 'INVALID_ARGUMENT',
					),
				),
				400
			);
		}

		if ( '/upload/gmail/v1/users/me/messages/send' === $path ) {
			$mime = (string) $body;
			if ( false !== stripos( $mime, 'ratelimit@example.com' ) ) {
				return nemvo_email_mock_json(
					array(
						'error' => array(
							'code'    => 429,
							'message' => 'User-rate limit exceeded.  Retry after 2026-11-09T21:00:00.000Z',
							'errors'  => array(
								array(
									'reason' => 'rateLimitExceeded',
									'domain' => 'usageLimits',
								),
							),
							'status'  => 'RESOURCE_EXHAUSTED',
						),
					),
					429
				);
			}
			if ( false !== stripos( $mime, 'bad-recipient@example.com' ) ) {
				return nemvo_email_mock_json(
					array(
						'error' => array(
							'code'    => 400,
							'message' => 'Invalid To header',
							'errors'  => array( array( 'reason' => 'invalidArgument' ) ),
							'status'  => 'INVALID_ARGUMENT',
						),
					),
					400
				);
			}
			$sent = nemvo_email_mock_deliver( $mime, $account );
			if ( is_wp_error( $sent ) ) {
				return nemvo_email_mock_json(
					array(
						'error' => array(
							'code'    => 400,
							'message' => $sent->get_error_message(),
							'status'  => 'INVALID_ARGUMENT',
						),
					),
					400
				);
			}
			$id = dechex( (int) ( microtime( true ) * 1000 ) ) . substr( md5( $mime ), 0, 4 );
			return nemvo_email_mock_json(
				array(
					'id'       => $id,
					'threadId' => $id,
					'labelIds' => array( 'SENT' ),
				)
			);
		}
		return $pre;
	},
	10,
	3
);

// Made-up DNS for example.com, so the domain check has something realistic to show.
add_filter(
	'nemvo_email_pre_dns_lookup',
	static function ( $records, $name, $type ) {
		if ( 'example.com' !== $name && '.example.com' !== substr( $name, -12 ) ) {
			return $records;
		}
		$zone = array(
			'TXT example.com'                   => array( 'v=spf1 include:_spf.google.com ~all', 'google-site-verification=3XsfJ0dQe1lmJp3sTkPA0w1v4a2q7oYb' ),
			'TXT google._domainkey.example.com' => array( 'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAupZW3VtxrwVE28ZpcmLEuI91tUnmvHMUqRpSMgGXhWz1U1REuhwNlqulnZSRIFmmhuMNDSltpPUTxuOgWG9Sm/xJrx5mVG4xfU50dQUfNeRMaQMrSwd1NJWjsweqLbXrb7XyMiRo12CVEEamMMCB4iOVULkT0lmkOqo1k/jhQpPbQUXsuyFXwPS433sjPwJvxHay8y7kGYbalF0FnXy5cMC+cWYp8cIMretGBPyF1fT2ezI7DOUXt43mQaZDlZAmIp+By2h5C3xkpWePUBY53sUYXskCB1eKjFpjvKmlUekUBzZBRxS4MrFDPDTXKfkVQDrJ0K6b9OEBoLqpTafP9QIDAQAB' ),
			'MX example.com'                    => array(
				array(
					'host' => 'smtp.google.com',
					'pri'  => 1,
				),
			),
			'NS example.com'                    => array( 'kate.ns.cloudflare.com', 'rob.ns.cloudflare.com' ),
		);
		return $zone[ $type . ' ' . $name ] ?? array();
	},
	10,
	3
);
