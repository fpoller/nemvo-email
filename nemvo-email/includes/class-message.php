<?php
/**
 * The PHPMailer that wp_mail() uses while this plugin is active.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
if ( ! class_exists( 'WP_PHPMailer', false ) && is_readable( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
}
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property names.

// WordPress 6.8+ has WP_PHPMailer (translated error messages); older versions use PHPMailer itself.
if ( ! class_exists( __NAMESPACE__ . '\\Message_Base', false ) ) {
	class_alias( class_exists( 'WP_PHPMailer', false ) ? 'WP_PHPMailer' : 'PHPMailer\\PHPMailer\\PHPMailer', __NAMESPACE__ . '\\Message_Base' );
}

/**
 * A PHPMailer whose send() goes to Mailer::send(). Everything wp_mail() sets
 * up (recipients, headers, attachments, inline images, phpmailer_init
 * changes) stays as it is.
 */
class Message extends Message_Base {

	/**
	 * Send through the provider, or the way WordPress would.
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When sending fails and exceptions are on (as in wp_mail()).
	 */
	public function send() {
		return Mailer::send( $this );
	}

	/**
	 * WordPress's own way of sending (PHP mail(), or SMTP when another
	 * plugin set it up in phpmailer_init).
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception When sending fails and exceptions are on.
	 */
	public function send_default() {
		return parent::send();
	}

	/**
	 * Build the complete message for an API: CRLF line endings, and To, Cc,
	 * Bcc and Subject as headers (the API reads the recipients from them and
	 * drops Bcc from what others receive).
	 *
	 * @return string|false False when PHPMailer refuses (no recipients, …).
	 * @throws \PHPMailer\PHPMailer\Exception When building fails and exceptions are on.
	 */
	public function build_message() {
		// "smtp" makes PHPMailer use CRLF and write To and Subject into the headers. Nothing is sent over SMTP.
		$this->Mailer = 'smtp';
		if ( ! $this->preSend() ) {
			return false;
		}
		$bcc = $this->getBccAddresses();
		if ( $bcc ) {
			$this->MIMEHeader = $this->addrAppend( 'Bcc', $bcc ) . $this->MIMEHeader;
		}
		return $this->getSentMIMEMessage();
	}

	/**
	 * Whether errors are thrown (wp_mail() turns them on).
	 *
	 * @return bool
	 */
	public function throws() {
		return (bool) $this->exceptions;
	}

	/**
	 * Report a failure the way PHPMailer does.
	 *
	 * @param string $message Error.
	 * @return false
	 * @throws \PHPMailer\PHPMailer\Exception When exceptions are on.
	 */
	public function fail( $message ) {
		$this->setError( $message );
		if ( $this->exceptions ) {
			// Plain text for wp_mail() and wp_mail_failed listeners, which escape it when they print it.
			throw new \PHPMailer\PHPMailer\Exception( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return false;
	}
}
