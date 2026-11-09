<?php
/**
 * Made-up log history for test sites (wp nemvo-email seed).
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand -- seeded, so the demo data is the same every time.

/**
 * Writes a plausible history straight into the log: a shop, a contact form,
 * WordPress itself and a booking plugin, with a weekly rhythm, slow growth,
 * and two incidents (a revoked connection and the daily limit).
 */
class Seeder {

	/**
	 * Add entries for the past days. Deterministic for a given day count.
	 *
	 * @param int $days Days.
	 * @return int Entries added.
	 */
	public static function run( $days ) {
		mt_srand( 20261109 + $days ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- repeatable demo data.

		$provider = Providers::current();
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = in_array( $host, array( 'localhost', '127.0.0.1', '' ), true ) || false === strpos( $host, '.' ) ? 'northwind-studio.com' : preg_replace( '/^www\./', '', $host );
		$account  = $provider->account() ? $provider->account() : 'hello@' . $host;
		$from     = $provider->is_connected() ? Mailer::from_email( $provider ) : $account;
		$site     = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$admin    = (string) get_option( 'admin_email' );
		$now      = time();
		$count    = 0;

		$first   = array( 'anna', 'erik', 'maria', 'johan', 'sofia', 'lars', 'emma', 'oskar', 'lina', 'nils', 'sara', 'jonas', 'ella', 'victor', 'maja', 'david', 'noor', 'liam', 'eva', 'tomas' );
		$last    = array( 'berg', 'lind', 'holm', 'nyberg', 'dahl', 'sandberg', 'ek', 'wallin', 'falk', 'strand', 'bjork', 'lund', 'moberg', 'hagen', 'kaya', 'novak', 'jansen', 'smit' );
		$domains = array(
			'gmail.com'          => 34,
			'outlook.com'        => 12,
			'hotmail.com'        => 7,
			'icloud.com'         => 8,
			'yahoo.com'          => 5,
			'proton.me'          => 3,
			'brightwave.io'      => 4,
			'nordlight-tech.com' => 3,
			'harbor-legal.eu'    => 3,
			'kestrel-design.nl'  => 2,
			'fjell-logistics.no' => 2,
		);
		$person  = static function () use ( $first, $last, $domains ) {
			$pick = mt_rand( 1, array_sum( $domains ) );
			foreach ( $domains as $domain => $weight ) {
				$pick -= $weight;
				if ( $pick <= 0 ) {
					break;
				}
			}
			$f = $first[ mt_rand( 0, count( $first ) - 1 ) ];
			$l = $last[ mt_rand( 0, count( $last ) - 1 ) ];
			return array( $f . '.' . $l . '@' . $domain, ucfirst( $f ) . ' ' . ucfirst( $l ) );
		};

		$order = 1040;
		// key, name, weight, subject (callable), to admin?, size range KB, attachment.
		$kinds        = array(
			array(
				'plugin:woocommerce',
				'WooCommerce',
				16,
				static function () use ( $site ) {
						return sprintf( 'Your %s order has been received!', $site );
				},
				false,
				array( 18, 34 ),
				'',
			),
			array(
				'plugin:woocommerce',
				'WooCommerce',
				9,
				static function () use ( $site, &$order ) {
						return sprintf( '[%s]: New order #%d', $site, ++$order );
				},
				true,
				array( 16, 28 ),
				'',
			),
			array(
				'plugin:woocommerce',
				'WooCommerce',
				11,
				static function () use ( $site ) {
						return sprintf( 'Your %s order is now complete', $site );
				},
				false,
				array( 160, 260 ),
				'invoice',
			),
			array(
				'plugin:woocommerce',
				'WooCommerce',
				1,
				static function () use ( $site ) {
						return sprintf( 'Your %s order #%d has been refunded', $site, mt_rand( 980, 1040 ) );
				},
				false,
				array( 14, 22 ),
				'',
			),
			array(
				'plugin:contact-form-7',
				'Contact Form 7',
				9,
				static function () use ( $site ) {
						return sprintf( '%s: New message from the contact form', $site );
				},
				true,
				array( 3, 6 ),
				'',
			),
			array(
				'core',
				'WordPress',
				6,
				static function () use ( $site ) {
						return sprintf( '[%s] Password Reset', $site );
				},
				false,
				array( 2, 3 ),
				'',
			),
			array(
				'core',
				'WordPress',
				2,
				static function () use ( $site ) {
						return sprintf( '[%s] New User Registration', $site );
				},
				true,
				array( 2, 3 ),
				'',
			),
			array(
				'core',
				'WordPress',
				2,
				static function () use ( $site ) {
						return sprintf( '[%s] Please moderate: "Choosing the right linen"', $site );
				},
				true,
				array( 3, 4 ),
				'',
			),
			array(
				'plugin:nemvo-booking',
				'Nemvo Booking',
				5,
				static function () {
						return 'Your booking is confirmed';
				},
				false,
				array( 9, 14 ),
				'',
			),
		);
		$total_weight = array_sum( array_column( $kinds, 2 ) );

		$midnight = new \DateTimeImmutable( 'today', wp_timezone() );
		for ( $d = $days - 1; $d >= 0; $d-- ) {
			// Midnight in the site's time zone, so the busy hours are local hours.
			$day     = $midnight->modify( '-' . $d . ' days' )->getTimestamp();
			$rows    = array();
			$weekday = (int) wp_date( 'N', $day );
			$growth  = 1 + 0.35 * ( 1 - $d / max( 1, $days ) );
			$base    = ( $weekday >= 6 ? 16 : ( 1 === $weekday ? 34 : 27 ) ) * $growth;
			$n       = max( 3, (int) round( $base + mt_rand( -5, 5 ) ) );
			if ( 0 === $d ) {
				$n = (int) round( $n * min( 1, ( $now - $day ) / DAY_IN_SECONDS ) );
			}

			for ( $i = 0; $i < $n; $i++ ) {
				$hour = self::hour();
				$time = $day + $hour * HOUR_IN_SECONDS + mt_rand( 0, 3599 );
				if ( $time > $now ) {
					continue;
				}
				$pick = mt_rand( 1, $total_weight );
				foreach ( $kinds as $kind ) {
					$pick -= $kind[2];
					if ( $pick <= 0 ) {
						break;
					}
				}
				$to      = $kind[4] ? array( $admin, '' ) : $person();
				$subject = call_user_func( $kind[3] );
				$status  = 'sent';
				$error   = '';

				if ( 41 === $d && $hour >= 9 && $hour < 13 ) {
					$status = 'failed';
					$error  = 'Google no longer accepts the connection. It was removed from the Google account, the password changed, or the OAuth app is still in "Testing", where access ends after 7 days. Connect again.';
				} elseif ( 17 === $d && $hour >= 20 ) {
					$status = 'failed';
					$error  = 'Gmail\'s sending limit was reached: Daily user sending limit exceeded.';
				} elseif ( mt_rand( 1, 400 ) === 1 ) {
					$status = 'failed';
					$error  = 'Gmail refused the message (400): Invalid To header';
				}

				$attachments = 'invoice' === $kind[6] ? array(
					array(
						'name'   => 'invoice-' . mt_rand( 1000, 1999 ) . '.pdf',
						'inline' => false,
					),
				) : array();
				$cc          = 'core' !== $kind[0] && ! $kind[4] && mt_rand( 1, 25 ) === 1 ? array( $person() ) : array();

				$rows[] = array(
					'time'         => $time,
					'status'       => $status,
					'provider'     => 'google',
					'account'      => $account,
					'from_email'   => $from,
					'from_name'    => 'plugin:woocommerce' === $kind[0] ? $site : Mailer::from_name(),
					'recipients'   => array(
						'to'       => array( $to ),
						'cc'       => $cc,
						'bcc'      => array(),
						'reply_to' => 'plugin:contact-form-7' === $kind[0] ? array( $person() ) : array(),
					),
					'subject'      => $subject,
					'source'       => array(
						'key'  => $kind[0],
						'name' => $kind[1],
					),
					'content_type' => 'core' === $kind[0] ? 'text/plain' : 'text/html',
					'attachments'  => $attachments,
					'size'         => mt_rand( $kind[5][0], $kind[5][1] ) * 1024 + mt_rand( 0, 1023 ),
					'duration_ms'  => 'sent' === $status ? mt_rand( 280, 900 ) : mt_rand( 120, 400 ),
					'message_id'   => 'sent' === $status ? self::message_id( $time ) : '',
					'error'        => $error,
				);
			}
			// Insert in time order, as real messages arrive.
			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['time'] <=> $b['time'];
				}
			);
			foreach ( $rows as $row ) {
				Log::insert( $row );
				++$count;
			}
		}
		delete_transient( Log::FAILED_TRANSIENT );
		return $count;
	}

	/**
	 * An hour of the day, weighted towards office hours and evenings.
	 *
	 * @return int
	 */
	private static function hour() {
		$weights = array( 1, 0, 0, 0, 0, 1, 2, 4, 7, 9, 10, 10, 9, 9, 10, 9, 8, 7, 7, 8, 8, 6, 4, 2 );
		$pick    = mt_rand( 1, array_sum( $weights ) );
		foreach ( $weights as $hour => $weight ) {
			$pick -= $weight;
			if ( $pick <= 0 ) {
				return $hour;
			}
		}
		return 12;
	}

	/**
	 * A Gmail-like message id (hex, time-based).
	 *
	 * @param int $time Time.
	 * @return string
	 */
	private static function message_id( $time ) {
		return dechex( $time * 1000 + mt_rand( 0, 999 ) ) . sprintf( '%03x', mt_rand( 0, 4095 ) );
	}
}
