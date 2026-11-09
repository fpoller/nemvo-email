<?php
/**
 * Setup checks for the screens and Site Health.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Whether email from this site will arrive: the connection, the sender
 * domain's DNS, other email plugins, and a replaced wp_mail().
 */
class Health {

	/**
	 * Other plugins that take over email (plugin file => name).
	 */
	const MAILERS = array(
		'wp-mail-smtp/wp_mail_smtp.php'                   => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php'               => 'WP Mail SMTP Pro',
		'post-smtp/postman-smtp.php'                      => 'Post SMTP',
		'fluent-smtp/fluent-smtp.php'                     => 'FluentSMTP',
		'easy-wp-smtp/easy-wp-smtp.php'                   => 'Easy WP SMTP',
		'wp-smtp/wp-smtp.php'                             => 'WP SMTP',
		'smtp-mailer/main.php'                            => 'SMTP Mailer',
		'gmail-smtp/main.php'                             => 'Gmail SMTP',
		'suremails/suremails.php'                         => 'SureMail',
		'site-mailer/site-mailer.php'                     => 'Site Mailer',
		'wp-ses/wp-ses.php'                               => 'WP Offload SES',
		'mailgun/mailgun.php'                             => 'Mailgun',
		'postmark-approved-wordpress-plugin/postmark.php' => 'Postmark',
		'sendgrid-email-delivery-simplified/wpsendgrid.php' => 'SendGrid',
		'smtp2go/smtp2go-wordpress-plugin.php'            => 'SMTP2GO',
		'gosmtp/gosmtp.php'                               => 'GoSMTP',
		'mail-bank/wp-mail-bank.php'                      => 'Mail Bank',
		'wp-mail-smtp-mailer/wp-mail-smtp-mailer.php'     => 'WP Mail SMTP Mailer',
		'microsoft-365-email/microsoft-365-email.php'     => 'Microsoft 365 Email',
		'kadence-starter-templates/wp-mail-smtp-mailer.php' => 'Kadence Mailer',
	);

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
	}

	/**
	 * Active plugins that also handle email.
	 *
	 * @return string[] Names.
	 */
	public static function other_mailers() {
		$active = (array) get_option( 'active_plugins', array() );
		$names  = array();
		foreach ( self::MAILERS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Whether another plugin defines its own wp_mail().
	 *
	 * @return string File that defines it, or ''.
	 */
	public static function wp_mail_replaced() {
		if ( ! function_exists( 'wp_mail' ) ) {
			return '';
		}
		$file = wp_normalize_path( (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() );
		return wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ) === $file ? '' : $file;
	}

	/**
	 * All checks.
	 *
	 * @return array[] Each: id, state (good, warning, critical), title, text, url, link.
	 */
	public static function checks() {
		$provider = Providers::current();
		$state    = $provider->state();
		$setup    = admin_url( 'admin.php?page=' . Admin::SLUG_SETUP );
		$settings = admin_url( 'admin.php?page=' . Admin::SLUG_SETTINGS );
		$checks   = array();

		switch ( $state['state'] ) {
			case 'connected':
				$checks[] = array(
					'id'    => 'connection',
					'state' => 'good',
					/* translators: 1: provider, 2: account */
					'title' => sprintf( __( 'Email is sent through %1$s as %2$s', 'nemvo-email' ), $provider->name(), $provider->account() ),
					'text'  => __( 'Every message from wp_mail() goes through the Gmail API and is logged.', 'nemvo-email' ),
					'url'   => $settings . '#connection',
					'link'  => __( 'Connection', 'nemvo-email' ),
				);
				break;
			case 'error':
				$checks[] = array(
					'id'    => 'connection',
					'state' => 'critical',
					/* translators: %s: provider */
					'title' => sprintf( __( '%s is not accepting email from this site', 'nemvo-email' ), $provider->name() ),
					'text'  => $state['message'],
					'url'   => $settings . '#connection',
					'link'  => __( 'Fix the connection', 'nemvo-email' ),
				);
				break;
			default:
				$checks[] = array(
					'id'    => 'connection',
					'state' => 'critical',
					'title' => __( 'Email is sent by the web server', 'nemvo-email' ),
					/* translators: %s: provider */
					'text'  => sprintf( __( 'Messages from a web server often land in spam or are rejected. Connect %s to send through it.', 'nemvo-email' ), $provider->name() ),
					'url'   => $setup,
					'link'  => __( 'Start setup', 'nemvo-email' ),
				);
		}

		if ( Settings::paused() ) {
			$checks[] = array(
				'id'    => 'paused',
				'state' => 'warning',
				'title' => __( 'Sending is paused', 'nemvo-email' ),
				'text'  => Settings::pause_locked()
					? __( 'NEMVO_EMAIL_PAUSE is set (in wp-config.php or the environment), so messages are logged but not sent.', 'nemvo-email' )
					: __( 'Messages are logged but not sent. That suits a staging copy of a site, not a live one.', 'nemvo-email' ),
				'url'   => $settings . '#sending',
				'link'  => __( 'Sending', 'nemvo-email' ),
			);
		}

		if ( $provider->is_connected() ) {
			$domain = Mailer::from_domain();
			$dns    = '' !== $domain ? Dns::stored( $domain ) : null;
			if ( $dns && ! $dns['personal'] ) {
				$labels = array();
				foreach ( array( 'spf', 'dkim', 'dmarc' ) as $key ) {
					if ( in_array( $dns['checks'][ $key ]['state'], array( 'warning', 'critical' ), true ) ) {
						$labels[] = $dns['checks'][ $key ]['label'];
					}
				}
				$checks[] = array(
					'id'    => 'dns',
					'state' => 'good' === $dns['state'] || 'neutral' === $dns['state'] ? 'good' : $dns['state'],
					'title' => $labels
						/* translators: 1: list like "DKIM, DMARC", 2: domain */
						? sprintf( _n( '%1$s for %2$s needs attention', '%1$s for %2$s need attention', count( $labels ), 'nemvo-email' ), implode( ', ', $labels ), $domain )
						/* translators: %s: domain */
						: sprintf( __( 'SPF, DKIM and DMARC are in order for %s', 'nemvo-email' ), $domain ),
					'text'  => $labels
						? __( 'Receivers use these DNS records to tell your email from forgeries. The domain check shows exactly what to publish.', 'nemvo-email' )
						/* translators: %s: date and time */
						: sprintf( __( 'Checked %s.', 'nemvo-email' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $dns['time'] ) ),
					'url'   => $settings . '#domain',
					'link'  => __( 'Domain check', 'nemvo-email' ),
				);
			} elseif ( ! $dns && '' !== $domain ) {
				$checks[] = array(
					'id'    => 'dns',
					'state' => 'warning',
					/* translators: %s: domain */
					'title' => sprintf( __( 'The DNS of %s hasn\'t been checked yet', 'nemvo-email' ), $domain ),
					'text'  => __( 'SPF, DKIM and DMARC decide whether your email lands in the inbox. The check takes a second.', 'nemvo-email' ),
					'url'   => $settings . '#domain',
					'link'  => __( 'Check the domain', 'nemvo-email' ),
				);
			}
		}

		$others = self::other_mailers();
		if ( $others ) {
			$checks[] = array(
				'id'    => 'conflicts',
				'state' => 'warning',
				/* translators: %s: plugin names */
				'title' => sprintf( __( 'Another email plugin is active: %s', 'nemvo-email' ), implode( ', ', $others ) ),
				'text'  => $provider->is_connected()
					? __( 'Nemvo Email sends every message, so the other plugin\'s settings have no effect, but two plugins hooking into email can still clash. Deactivate it.', 'nemvo-email' )
					: __( 'Import its settings if it is WP Mail SMTP, connect Google, then deactivate it.', 'nemvo-email' ),
				'url'   => admin_url( 'plugins.php' ),
				'link'  => __( 'Plugins', 'nemvo-email' ),
			);
		}

		$replaced = self::wp_mail_replaced();
		if ( '' !== $replaced ) {
			$checks[] = array(
				'id'    => 'wp_mail',
				'state' => 'critical',
				'title' => __( 'Another plugin replaces wp_mail()', 'nemvo-email' ),
				/* translators: %s: file */
				'text'  => sprintf( __( 'wp_mail() is defined in %s, so WordPress email doesn\'t pass through Nemvo Email. Deactivate the plugin that does this.', 'nemvo-email' ), $replaced ),
				'url'   => admin_url( 'plugins.php' ),
				'link'  => __( 'Plugins', 'nemvo-email' ),
			);
		}

		if ( ! $provider->is_connected() ) {
			$uri  = $provider->redirect_uri();
			$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
			if ( 0 !== strpos( $uri, 'https://' ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
				$checks[] = array(
					'id'    => 'https',
					'state' => 'critical',
					'title' => __( 'Google needs this site on https://', 'nemvo-email' ),
					'text'  => __( 'Google only sends sign-ins back to https:// addresses (or localhost). Turn on SSL for the site and set its address to https:// under Settings › General first.', 'nemvo-email' ),
					'url'   => admin_url( 'options-general.php' ),
					'link'  => __( 'General settings', 'nemvo-email' ),
				);
			}
		}

		if ( 'connected' === $state['state'] && ! Settings::get( 'test_sent' ) && ! Settings::paused() ) {
			$checks[] = array(
				'id'    => 'test',
				'state' => 'warning',
				'title' => __( 'No test email sent yet', 'nemvo-email' ),
				'text'  => __( 'Send one to see a message arrive, and to check it isn\'t marked as spam.', 'nemvo-email' ),
				'url'   => $settings . '#test',
				'link'  => __( 'Send a test email', 'nemvo-email' ),
			);
		}

		return $checks;
	}

	/**
	 * Tests under Tools › Site Health.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public static function site_health( $tests ) {
		foreach ( self::checks() as $check ) {
			$tests['direct'][ 'nemvo_email_' . $check['id'] ] = array(
				'label' => __( 'Email delivery', 'nemvo-email' ),
				'test'  => static function () use ( $check ) {
					return self::site_health_result( $check );
				},
			);
		}
		return $tests;
	}

	/**
	 * One Site Health result.
	 *
	 * @param array $check Check from checks().
	 * @return array
	 */
	public static function site_health_result( array $check ) {
		$status = array(
			'good'     => 'good',
			'warning'  => 'recommended',
			'critical' => 'critical',
		);
		return array(
			'label'       => $check['title'],
			'status'      => $status[ $check['state'] ],
			'badge'       => array(
				'label' => __( 'Email', 'nemvo-email' ),
				'color' => 'good' === $check['state'] ? 'blue' : ( 'critical' === $check['state'] ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $check['text'] ) . '</p>',
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( $check['url'] ), esc_html( $check['link'] ) ),
			'test'        => 'nemvo_email_' . $check['id'],
		);
	}
}
