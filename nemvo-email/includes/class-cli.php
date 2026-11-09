<?php
/**
 * WP-CLI commands.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Send, check and inspect email delivery.
 */
class Cli {

	/**
	 * Connection, sender, domain check and warnings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$provider = Providers::current();
		$state    = $provider->state();
		$domain   = Mailer::from_domain();
		$dns      = '' !== $domain ? Dns::stored( $domain ) : null;
		$totals   = Stats::totals( current_datetime()->format( 'Y-m-d' ), current_datetime()->format( 'Y-m-d' ) );

		$lines = array(
			'Provider'   => $provider->name(),
			'Connection' => $state['state'] . ( $provider->account() ? ' (' . $provider->account() . ')' : '' ) . ( $state['message'] ? ': ' . $state['message'] : '' ),
			'From'       => $provider->is_connected() ? Mailer::from_name() . ' <' . Mailer::from_email( $provider ) . '>' : '-',
			'Paused'     => Settings::paused() ? 'yes' : 'no',
			'Domain'     => $dns ? self::dns_summary( $dns ) : ( '' !== $domain ? $domain . ' (not checked)' : '-' ),
			'Today'      => sprintf( '%d sent, %d failed, %d paused', $totals['sent'], $totals['failed'], $totals['paused'] ),
			'Last 24 h'  => $provider->is_connected() ? sprintf( '%d of %d', Stats::sent_last_day( $provider->id() ), $provider->daily_limit() ) : '-',
		);
		foreach ( $lines as $label => $value ) {
			WP_CLI::log( str_pad( $label . ':', 12 ) . $value );
		}
		foreach ( Health::checks() as $check ) {
			if ( 'good' !== $check['state'] ) {
				WP_CLI::warning( $check['title'] . ' ' . $check['text'] );
			}
		}
	}

	/**
	 * Send a test email.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email test you@example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function test( $args, $assoc_args ) {
		unset( $assoc_args );
		if ( ! is_email( $args[0] ) ) {
			WP_CLI::error( 'Not an email address: ' . $args[0] );
		}
		$result = Mailer::send_test( $args[0] );
		$entry  = $result['log_id'] ? Log::get( $result['log_id'] ) : null;
		if ( $result['ok'] ) {
			WP_CLI::success( sprintf( 'Sent via %s%s (log #%d).', $entry ? $entry['provider_label'] : '?', $entry && $entry['message_id'] ? ', message id ' . $entry['message_id'] : '', $result['log_id'] ) );
		} else {
			WP_CLI::error( 'Not sent: ' . $result['error'] );
		}
	}

	/**
	 * Check SPF, DKIM, DMARC and MX for a domain.
	 *
	 * ## OPTIONS
	 *
	 * [<domain>]
	 * : Domain. Default: the sender's.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email dns
	 *     wp nemvo-email dns example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function dns( $args, $assoc_args ) {
		unset( $assoc_args );
		$domain = $args[0] ?? Mailer::from_domain();
		if ( '' === $domain ) {
			WP_CLI::error( 'No sender domain yet; give one: wp nemvo-email dns example.com' );
		}
		$result = Dns::check( $domain, Providers::current(), ! isset( $args[0] ) || Mailer::from_domain() === $domain );
		if ( $result['personal'] ) {
			WP_CLI::success( $domain . ' is a Gmail domain; Google handles its DNS.' );
			return;
		}
		$rows = array();
		foreach ( $result['checks'] as $check ) {
			$rows[] = array(
				'check' => $check['label'],
				'state' => $check['state'],
				'title' => $check['title'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'state', 'title' ) );
		foreach ( $result['checks'] as $check ) {
			if ( ! empty( $check['fix'] ) ) {
				WP_CLI::log( sprintf( '%s: %s %s "%s"', $check['label'], 'add' === $check['fix']['mode'] ? 'add' : 'change to', $check['fix']['type'] . ' ' . $check['fix']['name'], $check['fix']['value'] ) );
			}
		}
		if ( '' !== $result['host']['name'] ) {
			WP_CLI::log( 'DNS hosted at ' . $result['host']['name'] . '.' );
		}
	}

	/**
	 * Recent log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : sent, failed or paused.
	 *
	 * [--search=<text>]
	 * : Recipient, subject or error.
	 *
	 * [--limit=<number>]
	 * : Entries.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email log --status=failed
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function log( $args, $assoc_args ) {
		unset( $args );
		$result = Log::query(
			array(
				'status'   => $assoc_args['status'] ?? '',
				'search'   => $assoc_args['search'] ?? '',
				'per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
			)
		);
		$rows   = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = array(
				'id'      => $row['id'],
				'time'    => $row['local'],
				'status'  => $row['status'],
				'to'      => $row['to_email'] . ( $row['recipient_count'] > 1 ? ' +' . ( $row['recipient_count'] - 1 ) : '' ),
				'subject' => $row['subject'],
				'source'  => '' !== $row['source_name'] ? $row['source_name'] : $row['source'],
				'via'     => $row['provider_label'],
				'error'   => $row['error'],
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'time', 'status', 'to', 'subject', 'source', 'via', 'error' ) );
	}

	/**
	 * Import WP Mail SMTP's settings and Gmail connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email import
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$result = Import::run();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::log( 'Imported: ' . ( $result['imported'] ? implode( ', ', $result['imported'] ) : 'nothing' ) );
		if ( true === $result['connection'] ) {
			WP_CLI::success( 'The Gmail connection works; sending as ' . Providers::current()->account() . '.' );
		} elseif ( is_wp_error( $result['connection'] ) ) {
			WP_CLI::warning( 'The imported Gmail connection doesn\'t work: ' . $result['connection']->get_error_message() );
		}
	}

	/**
	 * Delete old log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Keep this many days. Default: the retention setting.
	 *
	 * [--all]
	 * : Delete everything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email purge --days=30
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ) {
		unset( $args );
		if ( ! empty( $assoc_args['all'] ) ) {
			Log::clear();
			WP_CLI::success( 'The log is empty.' );
			return;
		}
		$before  = isset( $assoc_args['days'] ) ? gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) $assoc_args['days'] ) * DAY_IN_SECONDS ) : null;
		$deleted = Log::purge( $before );
		WP_CLI::success( sprintf( 'Deleted %d entries.', $deleted ) );
	}

	/**
	 * Fill the log with made-up history, for trying the overview. Test sites only.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Days of history.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--reset]
	 * : Empty the log first.
	 *
	 * [--force]
	 * : Run even if WP_ENVIRONMENT_TYPE is "production".
	 *
	 * ## EXAMPLES
	 *
	 *     wp nemvo-email seed --days=90 --reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function seed( $args, $assoc_args ) {
		unset( $args );
		if ( 'production' === wp_get_environment_type() && empty( $assoc_args['force'] ) ) {
			WP_CLI::error( 'This looks like a live site (WP_ENVIRONMENT_TYPE is production). Use --force to seed anyway.' );
		}
		if ( ! empty( $assoc_args['reset'] ) ) {
			Log::clear();
		}
		$count = Seeder::run( max( 1, (int) ( $assoc_args['days'] ?? 90 ) ) );
		WP_CLI::success( sprintf( 'Added %d log entries.', $count ) );
	}

	/**
	 * One-line DNS summary.
	 *
	 * @param array $dns Result.
	 * @return string
	 */
	private static function dns_summary( array $dns ) {
		if ( $dns['personal'] ) {
			return $dns['domain'] . ' (Gmail)';
		}
		$parts = array();
		foreach ( array( 'spf', 'dkim', 'dmarc' ) as $key ) {
			$parts[] = $dns['checks'][ $key ]['label'] . ' ' . $dns['checks'][ $key ]['state'];
		}
		return $dns['domain'] . ': ' . implode( ', ', $parts ) . ' (checked ' . human_time_diff( $dns['time'] ) . ' ago)';
	}
}
