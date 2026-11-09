<?php
/**
 * Plugin bootstrap.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component into WordPress.
 */
final class Plugin {

	/**
	 * Daily housekeeping: log retention, connection check, DNS check.
	 */
	const CRON = 'nemvo_email_daily';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		Install::maybe_upgrade();
		Mailer::init();
		Health::init();
		Privacy::init();

		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
		add_action( self::CRON, array( __CLASS__, 'daily' ) );

		if ( is_admin() ) {
			Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nemvo-email', Cli::class );
		}
	}

	/**
	 * Capability needed for every screen and action of the plugin.
	 *
	 * @return string
	 */
	public static function cap() {
		/**
		 * Filters the capability needed to manage email settings and read the log.
		 *
		 * @param string $capability Default "manage_options".
		 */
		return (string) apply_filters( 'nemvo_email_capability', 'manage_options' );
	}

	/**
	 * Daily: drop old log entries, check that Google still accepts the
	 * connection, and look at the sender domain's DNS again.
	 */
	public static function daily() {
		Log::purge();
		$provider = Providers::current();
		if ( $provider->is_connected() ) {
			$provider->check();
		}
		$domain = Mailer::from_domain();
		if ( '' !== $domain ) {
			Dns::check( $domain, $provider, true );
		}
	}

	/**
	 * Activation: create the log table, schedule housekeeping.
	 */
	public static function activate() {
		Install::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		set_transient( 'nemvo_email_activated', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Deactivation: stop housekeeping. Settings and log are kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}
}
