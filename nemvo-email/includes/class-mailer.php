<?php
/**
 * Takes over wp_mail().
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

use NemvoEmail\Providers\Provider;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property names (From, Body, ...).

/**
 * Hands every wp_mail() message to the provider.
 *
 * WordPress's wp_mail() keeps doing everything it does (headers, filters,
 * attachments, phpmailer_init); just before it starts, the global $phpmailer is swapped
 * for this plugin's PHPMailer, whose send() goes to the connected provider.
 * Every message is logged, whether it was sent through the provider, by the
 * server (before setup), or not at all (paused, or failed).
 */
class Mailer {

	/**
	 * The current wp_mail() call: source and options for the log.
	 *
	 * @var array
	 */
	private static $context = array();

	/**
	 * A failure was logged by send(), so wp_mail_failed needn't log it again.
	 *
	 * @var bool
	 */
	private static $failure_logged = false;

	/**
	 * Id of the last log entry written.
	 *
	 * @var int
	 */
	public static $last_log_id = 0;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'take_over' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( __CLASS__, 'failed_before_send' ) );
	}

	/**
	 * The provider to send with, when an account is connected.
	 *
	 * @return Provider|null
	 */
	public static function active_provider() {
		$provider = Providers::current();
		return $provider->is_connected() ? $provider : null;
	}

	/**
	 * Install this plugin's PHPMailer right before wp_mail() uses the global.
	 * Another plugin's own PHPMailer (WP Mail SMTP's, say) is only replaced
	 * once an account is connected here.
	 *
	 * @param null|bool $short_circuit Another plugin's answer, if any.
	 * @param array     $atts          wp_mail() arguments.
	 * @return null|bool
	 */
	public static function take_over( $short_circuit, $atts ) {
		unset( $atts );
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		self::$failure_logged = false;
		global $phpmailer;
		if ( ! ( $phpmailer instanceof Message ) ) {
			$plain = ! is_object( $phpmailer ) || in_array( get_class( $phpmailer ), array( 'PHPMailer\\PHPMailer\\PHPMailer', 'WP_PHPMailer' ), true );
			if ( ! $plain && ! self::active_provider() ) {
				self::$context = array();
				return null;
			}
			$phpmailer             = new Message( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- this is how a mailer replaces WordPress's transport.
			$phpmailer::$validator = static function ( $email ) {
				return (bool) is_email( $email );
			};
		}
		if ( empty( self::$context['source'] ) ) {
			self::$context['source'] = self::caller();
		}
		return null;
	}

	/*
	 * Sender
	 * ------------------------------------------------------------------
	 */

	/**
	 * The address WordPress uses when nobody sets one (wordpress@example.com).
	 *
	 * @return string
	 */
	public static function wp_default_from() {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return 'wordpress@' . strtolower( $host );
	}

	/**
	 * The sender address: the setting, or the connected account.
	 *
	 * @param Provider|null $provider Provider (default: the current one).
	 * @return string
	 */
	public static function from_email( $provider = null ) {
		$provider = $provider ? $provider : Providers::current();
		$email    = (string) Settings::get( 'from_email' );
		return '' !== $email ? $email : $provider->account();
	}

	/**
	 * The sender name: the setting, or the site title.
	 *
	 * @return string
	 */
	public static function from_name() {
		$name = (string) Settings::get( 'from_name' );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		}
		return '' !== $name ? $name : 'WordPress';
	}

	/**
	 * Domain of the sender address.
	 *
	 * @return string
	 */
	public static function from_domain() {
		$email = self::from_email();
		return '' === $email ? '' : strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
	}

	/**
	 * While connected, replace WordPress's default sender (which may not even
	 * be a valid address, like wordpress@localhost). Addresses set by plugins
	 * are handled in send(), where a replaced one can become the Reply-To.
	 *
	 * @param string $email Sender.
	 * @return string
	 */
	public static function filter_from( $email ) {
		$provider = self::active_provider();
		if ( ! $provider ) {
			return $email;
		}
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || self::wp_default_from() === $email || ! is_email( $email ) ) {
			return self::from_email( $provider );
		}
		return $email;
	}

	/**
	 * While connected, replace the default sender name "WordPress".
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function filter_from_name( $name ) {
		if ( self::active_provider() && ( '' === trim( (string) $name ) || 'WordPress' === $name || Settings::get( 'force_from_name' ) ) ) {
			return self::from_name();
		}
		return $name;
	}

	/**
	 * Final sender rules, after phpmailer_init.
	 *
	 * @param Message  $mail     Message.
	 * @param Provider $provider Provider.
	 * @throws \PHPMailer\PHPMailer\Exception From addReplyTo() on an invalid address (not reached: checked first).
	 */
	public static function apply_sender( Message $mail, Provider $provider ) {
		$from     = self::from_email( $provider );
		$original = strtolower( trim( (string) $mail->From ) );
		$default  = '' === $original || self::wp_default_from() === $original || ! is_email( $original );
		if ( $original !== $from && ( $default || Settings::get( 'force_from_email' ) ) ) {
			if ( ! $default && Settings::get( 'reply_to_original' ) && ! $mail->getReplyToAddresses() && self::is_outside( $original, $from ) ) {
				$mail->addReplyTo( $original, (string) $mail->FromName );
			}
			$mail->From   = $from;
			$mail->Sender = '';
		}
		if ( '' === trim( (string) $mail->FromName ) || 'WordPress' === $mail->FromName || Settings::get( 'force_from_name' ) ) {
			$mail->FromName = self::from_name();
		}
	}

	/**
	 * Whether a replaced sender is someone outside the site (a contact form
	 * visitor) worth replying to, rather than another address of the site's
	 * own domain or a no-reply address.
	 *
	 * @param string $address Replaced sender.
	 * @param string $from    The sender used instead.
	 * @return bool
	 */
	private static function is_outside( $address, $from ) {
		if ( preg_match( '/^(no-?reply|do-?not-?reply)@/i', $address ) ) {
			return false;
		}
		$domain = static function ( $email ) {
			return Dns::org_domain( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
		};
		return $domain( $address ) !== $domain( $from );
	}

	/*
	 * Sending
	 * ------------------------------------------------------------------
	 */

	/**
	 * Send a message prepared by wp_mail().
	 *
	 * @param Message $mail Message.
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception On failure, when exceptions are on (as in wp_mail()).
	 */
	public static function send( Message $mail ) {
		$started  = microtime( true );
		$provider = self::active_provider();
		$logged   = false;
		$context  = self::$context;

		self::$context        = array();
		self::$failure_logged = false;

		try {
			if ( $provider ) {
				self::apply_sender( $mail, $provider );
			}

			if ( Settings::paused() ) {
				$mime = $mail->build_message();
				if ( false === $mime ) {
					$logged = true;
					self::log( $mail, 'failed', 'paused', $context, array( 'error' => $mail->ErrorInfo ) );
					return false;
				}
				$logged = true;
				self::log(
					$mail,
					'paused',
					'paused',
					$context,
					array(
						'size'    => strlen( $mime ),
						'account' => $provider ? $provider->account() : '',
					)
				);
				return true;
			}

			if ( ! $provider ) {
				$sent   = $mail->send_default();
				$logged = true;
				self::log(
					$mail,
					$sent ? 'sent' : 'failed',
					self::transport( $mail ),
					$context,
					array(
						'error'       => $sent ? '' : $mail->ErrorInfo,
						'size'        => strlen( $mail->getSentMIMEMessage() ),
						'duration_ms' => self::ms( $started ),
					)
				);
				return $sent;
			}

			$mime = $mail->build_message();
			if ( false === $mime ) {
				$logged = true;
				self::log( $mail, 'failed', $provider->id(), $context, array( 'error' => $mail->ErrorInfo ) );
				return false;
			}
			$result = $provider->send( $mime );
			$logged = true;
			if ( is_wp_error( $result ) ) {
				self::log(
					$mail,
					'failed',
					$provider->id(),
					$context,
					array(
						'error'       => $result->get_error_message(),
						'size'        => strlen( $mime ),
						'duration_ms' => self::ms( $started ),
						'account'     => $provider->account(),
					)
				);
				self::$failure_logged = true;
				return $mail->fail( $result->get_error_message() );
			}
			self::log(
				$mail,
				'sent',
				$provider->id(),
				$context,
				array(
					'message_id'  => $result['id'],
					'size'        => strlen( $mime ),
					'duration_ms' => self::ms( $started ),
					'account'     => $provider->account(),
				)
			);
			return true;
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			if ( ! $logged ) {
				self::log(
					$mail,
					'failed',
					$provider ? $provider->id() : self::transport( $mail ),
					$context,
					array(
						'error'       => $e->getMessage(),
						'duration_ms' => self::ms( $started ),
					)
				);
			}
			self::$failure_logged = true;
			throw $e;
		}
	}

	/**
	 * The transport WordPress used without a provider.
	 *
	 * @param Message $mail Message.
	 * @return string
	 */
	private static function transport( Message $mail ) {
		return in_array( $mail->Mailer, array( 'smtp', 'sendmail', 'qmail' ), true ) ? ( 'qmail' === $mail->Mailer ? 'sendmail' : $mail->Mailer ) : 'mail';
	}

	/**
	 * Milliseconds since a microtime().
	 *
	 * @param float $started Start.
	 * @return int
	 */
	private static function ms( $started ) {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Log wp_mail() failures that happened before send() (WordPress refuses
	 * an invalid sender in setFrom(), for example).
	 *
	 * @param \WP_Error $error Error from wp_mail().
	 */
	public static function failed_before_send( $error ) {
		global $phpmailer;
		if ( self::$failure_logged ) {
			self::$failure_logged = false;
			return;
		}
		if ( ! ( $phpmailer instanceof Message ) || ! is_wp_error( $error ) ) {
			return;
		}
		$data    = (array) $error->get_error_data();
		$to      = $data['to'] ?? array();
		$to      = is_array( $to ) ? $to : explode( ',', (string) $to );
		$context = self::$context;

		self::$context = array();
		Log::insert(
			array(
				'status'     => 'failed',
				'provider'   => '',
				'recipients' => array(
					'to'       => array_map(
						static function ( $address ) {
							return array( trim( (string) $address ), '' );
						},
						array_filter( $to )
					),
					'cc'       => array(),
					'bcc'      => array(),
					'reply_to' => array(),
				),
				'subject'    => (string) ( $data['subject'] ?? '' ),
				'source'     => $context['source'] ?? self::caller(),
				'error'      => $error->get_error_message(),
			)
		);
	}

	/**
	 * Write a log entry for a message.
	 *
	 * @param Message $mail     Message.
	 * @param string  $status   sent | failed | paused.
	 * @param string  $provider Provider id or transport.
	 * @param array   $context  wp_mail() context (source).
	 * @param array   $extra    message_id, error, size, duration_ms, account.
	 */
	private static function log( Message $mail, $status, $provider, array $context, array $extra = array() ) {
		$pairs = static function ( array $items ) {
			return array_map(
				static function ( $item ) {
					return array( (string) $item[0], (string) ( $item[1] ?? '' ) );
				},
				$items
			);
		};

		$attachments = array();
		foreach ( $mail->getAttachments() as $attachment ) {
			$attachments[] = array(
				'name'   => (string) $attachment[2],
				'inline' => 'inline' === ( $attachment[6] ?? '' ),
			);
		}
		$headers = array();
		foreach ( $mail->getCustomHeaders() as $header ) {
			$headers[] = array( (string) $header[0], (string) $header[1] );
		}

		$keep = (bool) Settings::get( 'log_content' );
		$row  = array(
			'status'       => $status,
			'provider'     => $provider,
			'account'      => (string) ( $extra['account'] ?? '' ),
			'from_email'   => (string) $mail->From,
			'from_name'    => (string) $mail->FromName,
			'recipients'   => array(
				'to'       => $pairs( $mail->getToAddresses() ),
				'cc'       => $pairs( $mail->getCcAddresses() ),
				'bcc'      => $pairs( $mail->getBccAddresses() ),
				'reply_to' => $pairs( array_values( $mail->getReplyToAddresses() ) ),
			),
			'subject'      => (string) $mail->Subject,
			'source'       => $context['source'] ?? array(),
			'content_type' => (string) $mail->ContentType,
			'attachments'  => $attachments,
			'headers'      => $headers,
			'size'         => (int) ( $extra['size'] ?? 0 ),
			'duration_ms'  => (int) ( $extra['duration_ms'] ?? 0 ),
			'message_id'   => (string) ( $extra['message_id'] ?? '' ),
			'error'        => (string) ( $extra['error'] ?? '' ),
			'body'         => $keep ? (string) $mail->Body : null,
			'alt_body'     => $keep ? (string) $mail->AltBody : null,
		);

		/**
		 * Filters a log entry before it is written. Return false to skip it.
		 *
		 * @param array|false $row  Entry.
		 * @param Message   $mail The message.
		 */
		$row = apply_filters( 'nemvo_email_log_entry', $row, $mail );
		if ( is_array( $row ) ) {
			self::$last_log_id = Log::insert( $row );
		}

		/**
		 * Fires after a message was sent, failed or held back.
		 *
		 * @param string    $status   sent | failed | paused.
		 * @param Message $mail     The message.
		 * @param array     $extra    message_id, error, size, duration_ms, account.
		 * @param string    $provider Provider id or transport.
		 */
		do_action( 'nemvo_email_' . $status, $mail, $extra, $provider );
	}

	/*
	 * Where a message comes from
	 * ------------------------------------------------------------------
	 */

	/**
	 * The plugin, theme or core file that called wp_mail().
	 *
	 * @return array { key, name }
	 */
	public static function caller() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the plugin that sent each logged email.
		$count  = count( $frames );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( 'wp_mail' !== ( $frames[ $i ]['function'] ?? '' ) || isset( $frames[ $i ]['class'] ) ) {
				continue;
			}
			for ( $j = $i; $j < $count; $j++ ) {
				if ( ! empty( $frames[ $j ]['file'] ) ) {
					return self::source_of( $frames[ $j ]['file'] );
				}
			}
		}
		return array(
			'key'  => '',
			'name' => '',
		);
	}

	/**
	 * Name the component a file belongs to.
	 *
	 * @param string $file File path.
	 * @return array { key, name }
	 */
	public static function source_of( $file ) {
		$file    = wp_normalize_path( (string) $file );
		$plugins = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$mu      = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$themes  = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$root    = trailingslashit( wp_normalize_path( ABSPATH ) );

		if ( 0 === strpos( $file, $plugins ) ) {
			$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
			return array(
				'key'  => 'plugin:' . $slug,
				'name' => self::plugin_name( $plugins . $slug ),
			);
		}
		if ( 0 === strpos( $file, $mu ) ) {
			$slug = strtok( substr( $file, strlen( $mu ) ), '/' );
			return array(
				'key'  => 'mu-plugin:' . $slug,
				'name' => self::plugin_name( $mu . $slug ),
			);
		}
		if ( 0 === strpos( $file, $themes ) ) {
			$slug  = strtok( substr( $file, strlen( $themes ) ), '/' );
			$theme = wp_get_theme( $slug );
			return array(
				'key'  => 'theme:' . $slug,
				'name' => $theme->exists() ? (string) $theme->get( 'Name' ) : $slug,
			);
		}
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( 0 === strpos( $file, $root ) && 0 !== strpos( $file, $content ) && preg_match( '#^(wp-includes/|wp-admin/|wp-[a-z-]+\.php$)#', substr( $file, strlen( $root ) ) ) ) {
			return array(
				'key'  => 'core',
				'name' => 'WordPress',
			);
		}
		return array(
			'key'  => '',
			'name' => '',
		);
	}

	/**
	 * Name of the plugin in a folder or file.
	 *
	 * @param string $path Plugin folder or single file.
	 * @return string
	 */
	private static function plugin_name( $path ) {
		static $names = array();
		if ( isset( $names[ $path ] ) ) {
			return $names[ $path ];
		}
		$files = is_dir( $path ) ? (array) glob( $path . '/*.php' ) : ( is_file( $path ) ? array( $path ) : array() );
		$name  = '';
		foreach ( $files as $candidate ) {
			$header = get_file_data( $candidate, array( 'name' => 'Plugin Name' ) );
			if ( '' !== $header['name'] ) {
				$name = $header['name'];
				break;
			}
		}
		if ( '' === $name ) {
			$name = ucwords( str_replace( array( '-', '_' ), ' ', basename( $path, '.php' ) ) );
		}
		$names[ $path ] = $name;
		return $name;
	}

	/**
	 * Treat the next wp_mail() call as coming from this source (resending).
	 *
	 * @param array $source { key, name }.
	 */
	public static function set_source( array $source ) {
		self::$context['source'] = $source;
	}

	/*
	 * Test message and resending
	 * ------------------------------------------------------------------
	 */

	/**
	 * Send a test message through wp_mail().
	 *
	 * @param string $to Recipient.
	 * @return array { ok, error, log_id }
	 */
	public static function send_test( $to ) {
		$provider = self::active_provider();
		$site     = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		/* translators: %s: site title */
		$subject = sprintf( __( 'Test email from %s', 'nemvo-email' ), $site );
		$lines   = array(
			$provider
				/* translators: 1: provider name, 2: account */
				? sprintf( __( 'This message was sent by WordPress through %1$s, as %2$s.', 'nemvo-email' ), $provider->name(), $provider->account() )
				: __( 'This message was sent by WordPress from the web server.', 'nemvo-email' ),
			__( 'If it arrived in your inbox, email from this site works.', 'nemvo-email' ),
			/* translators: %s: date and time */
			sprintf( __( 'Sent: %s', 'nemvo-email' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
			home_url( '/' ),
		);
		$html    = '<!doctype html><html><body style="margin:0;padding:24px;background:#f6f6f4;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#1d1d1b">'
			. '<div style="max-width:520px;margin:0 auto;padding:24px 28px;background:#fff;border:1px solid #e3e2dc;border-radius:12px">'
			. '<p style="margin:0 0 4px;font-size:18px;font-weight:600">' . esc_html( $subject ) . '</p>';
		foreach ( $lines as $line ) {
			$html .= '<p style="margin:12px 0 0;font-size:14px;line-height:1.5">' . esc_html( $line ) . '</p>';
		}
		$html .= '</div></body></html>';

		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		add_action( 'wp_mail_failed', $catch );
		self::$last_log_id = 0;
		$ok                = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $catch );

		if ( $ok && $provider && ! Settings::paused() ) {
			Settings::update( array( 'test_sent' => time() ) );
		}
		return array(
			'ok'     => (bool) $ok,
			'error'  => $error,
			'log_id' => self::$last_log_id,
		);
	}

	/**
	 * Send a logged message again (needs the content in the log).
	 *
	 * @param array $row Log entry.
	 * @return array { ok, error, log_id }
	 */
	public static function resend( array $row ) {
		if ( null === $row['body'] ) {
			return array(
				'ok'     => false,
				'error'  => __( 'The content of this email was not kept, so it can\'t be sent again.', 'nemvo-email' ),
				'log_id' => 0,
			);
		}
		$format  = static function ( array $pair ) {
			return '' !== $pair[1] ? sprintf( '%s <%s>', $pair[1], $pair[0] ) : $pair[0];
		};
		$people  = $row['recipients'];
		$headers = array( 'Content-Type: ' . ( $row['content_type'] ? $row['content_type'] : 'text/plain' ) . '; charset=UTF-8' );
		if ( '' !== $row['from_email'] ) {
			$headers[] = 'From: ' . $format( array( $row['from_email'], $row['from_name'] ) );
		}
		foreach ( array(
			'cc'       => 'Cc',
			'bcc'      => 'Bcc',
			'reply_to' => 'Reply-To',
		) as $key => $name ) {
			foreach ( $people[ $key ] as $pair ) {
				$headers[] = $name . ': ' . $format( $pair );
			}
		}

		$alt   = (string) $row['alt_body'];
		$set   = static function ( $mailer ) use ( $alt ) {
			$mailer->AltBody = $alt;
		};
		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};
		if ( '' !== $alt ) {
			add_action( 'phpmailer_init', $set );
		}
		add_action( 'wp_mail_failed', $catch );
		self::set_source(
			array(
				'key'  => $row['source'],
				'name' => $row['source_name'],
			)
		);
		self::$last_log_id = 0;
		$ok                = wp_mail( array_map( $format, $people['to'] ), $row['subject'], (string) $row['body'], $headers );
		remove_action( 'phpmailer_init', $set );
		remove_action( 'wp_mail_failed', $catch );
		return array(
			'ok'     => (bool) $ok,
			'error'  => $error,
			'log_id' => self::$last_log_id,
		);
	}
}
