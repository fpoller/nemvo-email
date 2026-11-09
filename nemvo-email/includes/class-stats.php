<?php
/**
 * Statistics from the log.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reports on the plugin's own log table.

/**
 * Totals, a time series and breakdowns for a period of local dates.
 */
class Stats {

	/**
	 * Everything the overview shows, for a period and the one before it.
	 *
	 * @param string $from First day, Y-m-d (site time).
	 * @param string $to   Last day, Y-m-d.
	 * @return array
	 */
	public static function report( $from, $to ) {
		$tz       = wp_timezone();
		$start    = new \DateTimeImmutable( $from, $tz );
		$end      = new \DateTimeImmutable( $to, $tz );
		$days     = (int) $start->diff( $end )->days + 1;
		$interval = 1 === $days ? 'hour' : ( $days <= 92 ? 'day' : 'week' );
		$prev_to  = $start->modify( '-1 day' );
		$prev     = $prev_to->modify( '-' . ( $days - 1 ) . ' days' );

		$provider = Providers::current();
		return array(
			'from'     => $from,
			'to'       => $to,
			'interval' => $interval,
			'totals'   => self::totals( $from, $to ),
			'previous' => array_merge(
				self::totals( $prev->format( 'Y-m-d' ), $prev_to->format( 'Y-m-d' ) ),
				array(
					'from' => $prev->format( 'Y-m-d' ),
					'to'   => $prev_to->format( 'Y-m-d' ),
				)
			),
			'series'   => self::series( $from, $to, $interval ),
			'sources'  => self::breakdown( 'source', $from, $to ),
			'subjects' => self::breakdown( 'subject_key', $from, $to ),
			'domains'  => self::breakdown( 'to_domain', $from, $to ),
			'errors'   => self::errors( $from, $to ),
			'quota'    => array(
				'sent'  => self::sent_last_day( $provider->id() ),
				'limit' => $provider->is_connected() ? $provider->daily_limit() : 0,
			),
		);
	}

	/**
	 * Messages by status, and recipients of sent ones.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array
	 */
	public static function totals( $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS messages, SUM(recipient_count) AS recipients FROM %i WHERE created_local BETWEEN %s AND %s GROUP BY status',
				Log::table(),
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		$out  = array(
			'sent'       => 0,
			'failed'     => 0,
			'paused'     => 0,
			'recipients' => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['messages'];
			}
			if ( 'sent' === $row['status'] ) {
				$out['recipients'] = (int) $row['recipients'];
			}
		}
		$attempts    = $out['sent'] + $out['failed'];
		$out['rate'] = $attempts ? round( $out['sent'] / $attempts, 4 ) : null;
		return $out;
	}

	/**
	 * Sent, failed and paused per hour, day or week.
	 *
	 * @param string $from     First day.
	 * @param string $to       Last day.
	 * @param string $interval hour | day | week.
	 * @return array[] { key, sent, failed, paused }
	 */
	public static function series( $from, $to, $interval ) {
		global $wpdb;
		$tz      = wp_timezone();
		$buckets = array();
		$empty   = array(
			'sent'   => 0,
			'failed' => 0,
			'paused' => 0,
		);

		if ( 'hour' === $interval ) {
			for ( $h = 0; $h < 24; $h++ ) {
				$buckets[ sprintf( '%s %02d:00', $from, $h ) ] = $empty;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT HOUR(created_local) AS k, status, COUNT(*) AS n FROM %i WHERE created_local BETWEEN %s AND %s GROUP BY k, status',
					Log::table(),
					$from . ' 00:00:00',
					$from . ' 23:59:59'
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$key = sprintf( '%s %02d:00', $from, (int) $row['k'] );
				if ( isset( $buckets[ $key ][ $row['status'] ] ) ) {
					$buckets[ $key ][ $row['status'] ] += (int) $row['n'];
				}
			}
		} else {
			$week_start = (int) get_option( 'start_of_week', 1 );
			$bucket_of  = static function ( \DateTimeImmutable $day ) use ( $interval, $week_start ) {
				if ( 'week' === $interval ) {
					$back = ( (int) $day->format( 'w' ) - $week_start + 7 ) % 7;
					$day  = $day->modify( '-' . $back . ' days' );
				}
				return $day->format( 'Y-m-d' );
			};
			$end        = new \DateTimeImmutable( $to, $tz );
			for ( $day = new \DateTimeImmutable( $from, $tz ); $day <= $end; $day = $day->modify( '+1 day' ) ) {
				$buckets[ $bucket_of( $day ) ] = $empty;
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DATE(created_local) AS k, status, COUNT(*) AS n FROM %i WHERE created_local BETWEEN %s AND %s GROUP BY k, status',
					Log::table(),
					$from . ' 00:00:00',
					$to . ' 23:59:59'
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$key = $bucket_of( new \DateTimeImmutable( $row['k'], $tz ) );
				if ( isset( $buckets[ $key ][ $row['status'] ] ) ) {
					$buckets[ $key ][ $row['status'] ] += (int) $row['n'];
				}
			}
		}

		$out = array();
		foreach ( $buckets as $key => $counts ) {
			$out[] = array( 'key' => $key ) + $counts;
		}
		return $out;
	}

	/**
	 * Top values of a column, with sent and failed counts.
	 *
	 * @param string $column source | subject_key | to_domain.
	 * @param string $from   First day.
	 * @param string $to     Last day.
	 * @param int    $limit  Rows.
	 * @return array[] { key, name, sent, failed, total }
	 */
	public static function breakdown( $column, $from, $to, $limit = 10 ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'source', 'subject_key', 'to_domain' ), true ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i AS k, MAX(source_name) AS name, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, COUNT(*) AS total FROM %i WHERE created_local BETWEEN %s AND %s GROUP BY k ORDER BY total DESC, k ASC LIMIT %d",
				$column,
				Log::table(),
				$from . ' 00:00:00',
				$to . ' 23:59:59',
				(int) $limit
			),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) use ( $column ) {
				return array(
					'key'    => (string) $row['k'],
					'name'   => 'source' === $column ? (string) $row['name'] : (string) $row['k'],
					'sent'   => (int) $row['sent'],
					'failed' => (int) $row['failed'],
					'total'  => (int) $row['total'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * The most common errors.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array[] { error, count, last (site time, Y-m-d H:i:s) }
	 */
	public static function errors( $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT error, COUNT(*) AS n, MAX(created_local) AS last FROM %i WHERE status = %s AND created_local BETWEEN %s AND %s GROUP BY error ORDER BY n DESC LIMIT 5',
				Log::table(),
				'failed',
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) {
				return array(
					'error' => (string) $row['error'],
					'count' => (int) $row['n'],
					'last'  => (string) $row['last'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Messages sent through a provider in the last 24 hours (Google's
	 * limits are per rolling day).
	 *
	 * @param string $provider Provider id.
	 * @return int
	 */
	public static function sent_last_day( $provider ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND provider = %s AND created_at >= %s',
				Log::table(),
				'sent',
				$provider,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}
}
