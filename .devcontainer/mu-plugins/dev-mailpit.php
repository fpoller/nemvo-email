<?php
/**
 * Plugin Name: Dev – server mail to Mailpit
 * Description: Local development only. Until Google is connected, WordPress sends "from the web server": this delivers that mail to Mailpit (http://localhost:8065) instead of PHP mail(), which the container doesn't have. Once connected, Nemvo Email sends through the (fake) Gmail API and ignores this.
 */

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host     = 'mailpit';
		$phpmailer->Port     = 1025;
		$phpmailer->SMTPAuth = false;
	}
);

// The site runs at http://localhost:8060, so WordPress's default sender is wordpress@localhost, which isn't valid.
add_filter(
	'wp_mail_from',
	static function ( $from ) {
		return is_email( $from ) ? $from : 'wordpress@example.com';
	}
);
