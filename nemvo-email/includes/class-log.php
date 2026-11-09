<?php
/**
 * The email log.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, always read fresh.

/**
 * One row per message: who it went to, what it was, where it came from and
 * what happened. The content is kept only when "Keep the content" is on.
 */
class Log {

	const FAILED_TRANSIENT = 'nemvo_email_failed_24h';

	/**
	 * Largest body kept in the log (bytes).
	 */
	const MAX_BODY = 1048576;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nemvo_email_log';
	}

	/**
	 * Add an entry.
	 *
	 * @param array $row Entry; see Mailer::log(). "time" (Unix) may set the date.
	 * @return int Id.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$time   = isset( $row['time'] ) ? (int) $row['time'] : time();
		$people = self::people( $row['recipients'] ?? array() );
		$first  = $people['to'][0][0] ?? ( $people['cc'][0][0] ?? ( $people['bcc'][0][0] ?? '' ) );
		$source = $row['source'] ?? array();
		if ( ! is_array( $source ) ) {
			$source = array(
				'key'  => (string) $source,
				'name' => (string) ( $row['source_name'] ?? '' ),
			);
		}
		$subject = self::clip( (string) ( $row['subject'] ?? '' ), 255 );

		$data = array(
			'created_at'      => gmdate( 'Y-m-d H:i:s', $time ),
			'created_local'   => wp_date( 'Y-m-d H:i:s', $time ),
			'status'          => (string) ( $row['status'] ?? 'sent' ),
			'provider'        => (string) ( $row['provider'] ?? '' ),
			'account'         => self::clip( (string) ( $row['account'] ?? '' ), 191 ),
			'from_email'      => self::clip( (string) ( $row['from_email'] ?? '' ), 191 ),
			'from_name'       => self::clip( (string) ( $row['from_name'] ?? '' ), 191 ),
			'to_email'        => self::clip( $first, 191 ),
			'to_domain'       => self::clip( strtolower( (string) substr( (string) strrchr( $first, '@' ), 1 ) ), 191 ),
			'recipients'      => (string) wp_json_encode( $people ),
			'recipient_count' => min( 65535, count( $people['to'] ) + count( $people['cc'] ) + count( $people['bcc'] ) ),
			'subject'         => $subject,
			'subject_key'     => self::subject_key( $subject ),
			'source'          => self::clip( (string) ( $source['key'] ?? '' ), 100 ),
			'source_name'     => self::clip( (string) ( $source['name'] ?? '' ), 191 ),
			'content_type'    => self::clip( (string) ( $row['content_type'] ?? '' ), 40 ),
			'attachments'     => empty( $row['attachments'] ) ? null : (string) wp_json_encode( array_values( (array) $row['attachments'] ) ),
			'headers'         => empty( $row['headers'] ) ? null : (string) wp_json_encode( array_values( (array) $row['headers'] ) ),
			'size'            => max( 0, (int) ( $row['size'] ?? 0 ) ),
			'duration_ms'     => max( 0, (int) ( $row['duration_ms'] ?? 0 ) ),
			'message_id'      => self::clip( (string) ( $row['message_id'] ?? '' ), 191 ),
			'error'           => '' === (string) ( $row['error'] ?? '' ) ? null : (string) $row['error'],
			'body'            => isset( $row['body'] ) ? self::cut( (string) $row['body'] ) : null,
			'alt_body'        => isset( $row['alt_body'] ) ? self::cut( (string) $row['alt_body'] ) : null,
		);

		if ( ! $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		if ( 'failed' === $data['status'] ) {
			delete_transient( self::FAILED_TRANSIENT );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Normalise recipients to { to, cc, bcc, reply_to } lists of [address, name].
	 *
	 * @param array $people Recipients.
	 * @return array
	 */
	private static function people( $people ) {
		$out = array();
		foreach ( array( 'to', 'cc', 'bcc', 'reply_to' ) as $key ) {
			$out[ $key ] = array();
			foreach ( (array) ( $people[ $key ] ?? array() ) as $pair ) {
				$pair    = (array) $pair;
				$address = trim( (string) ( $pair[0] ?? '' ) );
				if ( '' !== $address ) {
					$out[ $key ][] = array( $address, trim( (string) ( $pair[1] ?? '' ) ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Group similar subjects: numbers, dates and addresses become placeholders,
	 * so "Order #1041 received" and "Order #1042 received" count together.
	 *
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function subject_key( $subject ) {
		$key = preg_replace( '/[^\s<>()"]+@[^\s<>()"]+/u', '…', (string) $subject );
		$key = preg_replace( '/#?\d[\d.,:\/-]*/u', '#', (string) $key );
		$key = preg_replace( '/\s+/u', ' ', trim( (string) $key ) );
		return self::clip( (string) $key, 191 );
	}

	/**
	 * Shorten to a number of characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters.
	 * @return string
	 */
	private static function clip( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}

	/**
	 * Limit a kept body to MAX_BODY bytes.
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private static function cut( $body ) {
		if ( strlen( $body ) <= self::MAX_BODY ) {
			return $body;
		}
		return ( function_exists( 'mb_strcut' ) ? mb_strcut( $body, 0, self::MAX_BODY ) : substr( $body, 0, self::MAX_BODY ) );
	}

	/**
	 * Entries matching filters, newest first.
	 *
	 * @param array $args status, search, source, domain, subject, from, to (local Y-m-d), page, per_page.
	 * @return array { rows, total, page, pages }
	 */
	public static function query( array $args ) {
		global $wpdb;
		$args = array_merge(
			array(
				'status'   => '',
				'search'   => '',
				'source'   => null,
				'domain'   => '',
				'subject'  => '',
				'from'     => '',
				'to'       => '',
				'page'     => 1,
				'per_page' => 50,
			),
			$args
		);

		$where  = array( '1=1' );
		$params = array();
		if ( in_array( $args['status'], array( 'sent', 'failed', 'paused' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( null !== $args['source'] && '' !== (string) $args['source'] ) {
			$where[]  = 'source = %s';
			$params[] = '-' === $args['source'] ? '' : (string) $args['source'];
		}
		if ( '' !== $args['domain'] ) {
			$where[]  = 'to_domain = %s';
			$params[] = (string) $args['domain'];
		}
		if ( '' !== $args['subject'] ) {
			$where[]  = 'subject_key = %s';
			$params[] = (string) $args['subject'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(subject LIKE %s OR recipients LIKE %s OR from_email LIKE %s OR error LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where[]  = 'created_local >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where[]  = 'created_local <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$sql      = implode( ' AND ', $where );
		$columns  = 'id, created_at, created_local, status, provider, account, from_email, from_name, to_email, recipients, recipient_count, subject, source, source_name, attachments, size, message_id, error, (body IS NOT NULL) AS has_content';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql and $columns are fixed SQL with placeholders built above; every value is in $params.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql}", array_merge( array( self::table() ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM %i WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( array( self::table() ), $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'rows'  => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * One entry, with content and headers.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Decode a database row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function format( array $row ) {
		$decode = static function ( $json ) {
			$value = json_decode( (string) $json, true );
			return is_array( $value ) ? $value : array();
		};
		$out    = array(
			'id'              => (int) $row['id'],
			'time'            => strtotime( $row['created_at'] . ' UTC' ),
			'local'           => (string) $row['created_local'],
			'status'          => (string) $row['status'],
			'provider'        => (string) $row['provider'],
			'provider_label'  => '' === $row['provider'] ? '' : Providers::label( $row['provider'] ),
			'account'         => (string) $row['account'],
			'from_email'      => (string) $row['from_email'],
			'from_name'       => (string) $row['from_name'],
			'to_email'        => (string) $row['to_email'],
			'recipients'      => array_merge(
				array(
					'to'       => array(),
					'cc'       => array(),
					'bcc'      => array(),
					'reply_to' => array(),
				),
				$decode( $row['recipients'] )
			),
			'recipient_count' => (int) $row['recipient_count'],
			'subject'         => (string) $row['subject'],
			'source'          => (string) $row['source'],
			'source_name'     => (string) $row['source_name'],
			'attachments'     => $decode( $row['attachments'] ?? '' ),
			'size'            => (int) $row['size'],
			'message_id'      => (string) $row['message_id'],
			'error'           => (string) $row['error'],
			'has_content'     => isset( $row['body'] ) ? true : ! empty( $row['has_content'] ),
		);
		if ( array_key_exists( 'body', $row ) ) {
			$provider               = Providers::get( $out['provider'] );
			$out['headers']         = $decode( $row['headers'] );
			$out['content_type']    = (string) $row['content_type'];
			$out['body']            = $row['body'];
			$out['alt_body']        = $row['alt_body'];
			$out['duration_ms']     = (int) $row['duration_ms'];
			$out['subject_key']     = (string) $row['subject_key'];
			$out['message_url']     = $provider && $out['account'] === $provider->account() ? $provider->message_url( $out['message_id'] ) : '';
			$out['has_content']     = null !== $row['body'];
			$out['has_attachments'] = (bool) array_filter(
				$out['attachments'],
				static function ( $a ) {
					return empty( $a['inline'] );
				}
			);
		}
		return $out;
	}

	/**
	 * Components that sent email, for the log filter.
	 *
	 * @return array[] { key, name, count }
	 */
	public static function sources() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source AS `key`, MAX(source_name) AS name, COUNT(*) AS count FROM %i GROUP BY source ORDER BY count DESC LIMIT 100', self::table() ), ARRAY_A );
		return array_map(
			static function ( $row ) {
				return array(
					'key'   => (string) $row['key'],
					'name'  => (string) $row['name'],
					'count' => (int) $row['count'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Delete entries.
	 *
	 * @param int[] $ids Ids.
	 * @return int Deleted.
	 */
	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a list of %d; the ids are the values.
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( self::table() ), $ids ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		delete_transient( self::FAILED_TRANSIENT );
		return $deleted;
	}

	/**
	 * Delete everything.
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
		delete_transient( self::FAILED_TRANSIENT );
	}

	/**
	 * Delete entries older than the retention period, or a given date.
	 *
	 * @param string|null $before UTC "Y-m-d H:i:s"; default from the setting.
	 * @return int Deleted.
	 */
	public static function purge( $before = null ) {
		global $wpdb;
		if ( null === $before ) {
			$before = gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'log_retention' ) * DAY_IN_SECONDS );
		}
		$total = 0;
		do {
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT 5000', self::table(), $before ) );
			$total  += $deleted;
		} while ( 5000 === $deleted );
		if ( $total ) {
			delete_transient( self::FAILED_TRANSIENT );
		}
		return $total;
	}

	/**
	 * Failed messages in the last 24 hours (cached for five minutes).
	 *
	 * @return int
	 */
	public static function failed_recently() {
		$count = get_transient( self::FAILED_TRANSIENT );
		if ( false === $count ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at >= %s', self::table(), 'failed', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
			set_transient( self::FAILED_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Entries sent to or from an address (personal data export and erasure).
	 *
	 * @param string $email Address.
	 * @param int    $limit Max entries.
	 * @return array[] Database rows.
	 */
	public static function for_address( $email, $limit = 500 ) {
		global $wpdb;
		$quoted = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, status, from_email, recipients, subject FROM %i WHERE recipients LIKE %s OR from_email = %s ORDER BY id LIMIT %d', self::table(), $quoted, $email, (int) $limit ), ARRAY_A );
	}

	/**
	 * Whether the table exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;
		return self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
	}
}
