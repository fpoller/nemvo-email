<?php
/**
 * Database table.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the log table with dbDelta.
 */
class Install {

	/**
	 * Raise when the schema changes; dbDelta adds new columns and keys on the next load.
	 */
	const DB_VERSION = 1;

	const OPTION = 'nemvo_email_db_version';

	/**
	 * Install or upgrade when the stored schema version is older.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::OPTION ) < self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Log::table();
		$charset = $wpdb->get_charset_collate();

		// created_at is UTC; created_local is the site's time, which the statistics group by.
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				created_local datetime NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'sent',
				provider varchar(20) NOT NULL DEFAULT '',
				account varchar(191) NOT NULL DEFAULT '',
				from_email varchar(191) NOT NULL DEFAULT '',
				from_name varchar(191) NOT NULL DEFAULT '',
				to_email varchar(191) NOT NULL DEFAULT '',
				to_domain varchar(191) NOT NULL DEFAULT '',
				recipients text NOT NULL,
				recipient_count smallint(5) unsigned NOT NULL DEFAULT 0,
				subject varchar(255) NOT NULL DEFAULT '',
				subject_key varchar(191) NOT NULL DEFAULT '',
				source varchar(100) NOT NULL DEFAULT '',
				source_name varchar(191) NOT NULL DEFAULT '',
				content_type varchar(40) NOT NULL DEFAULT '',
				attachments text NULL,
				headers text NULL,
				size int(10) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				message_id varchar(191) NOT NULL DEFAULT '',
				error text NULL,
				body longtext NULL,
				alt_body longtext NULL,
				PRIMARY KEY  (id),
				KEY created_local (created_local),
				KEY status_created (status,created_local)
			) {$charset};"
		);

		update_option( self::OPTION, self::DB_VERSION, true );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = Log::table();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table, on uninstall.
		delete_option( self::OPTION );
	}
}
