<?php
/**
 * Uninstall: revoke the Google authorization and remove settings, the log
 * table and scheduled events.
 *
 * @package NemvoEmail
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/nemvo-email.php';

/**
 * Remove the plugin's data from the current site.
 */
function nemvo_email_uninstall_site() {
	NemvoEmail\Providers::reset();
	NemvoEmail\Settings::flush();
	$provider = NemvoEmail\Providers::current();
	if ( $provider->is_connected() ) {
		// An authorization imported from a still active WP Mail SMTP is shared with it.
		$shared = 'wp-mail-smtp' === $provider->imported() && '' !== NemvoEmail\Import::active_plugin();
		$provider->disconnect( ! $shared );
	}
	NemvoEmail\Install::drop();
	foreach ( array( 'nemvo_email_settings', 'nemvo_email_google', 'nemvo_email_dns', 'nemvo_email_db_version' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'nemvo_email_activated' );
	delete_transient( NemvoEmail\Log::FAILED_TRANSIENT );
	wp_clear_scheduled_hook( NemvoEmail\Plugin::CRON );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $nemvo_email_site ) {
		switch_to_blog( $nemvo_email_site );
		nemvo_email_uninstall_site();
		restore_current_blog();
	}
} else {
	nemvo_email_uninstall_site();
}
