<?php
/**
 * SPF, DKIM, DMARC and MX checks for the sender domain.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

use NemvoEmail\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the sender domain's DNS through the server's resolver and says,
 * for each record, whether it is right for the provider and exactly what to
 * publish when it isn't.
 */
class Dns {

	const OPTION = 'nemvo_email_dns';

	/**
	 * SPF allows at most this many DNS lookups.
	 */
	const SPF_LOOKUP_LIMIT = 10;

	/**
	 * Lookups of the current check.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Look up records.
	 *
	 * @param string $name Name.
	 * @param string $type TXT | MX | NS.
	 * @return array TXT: strings. MX: { host, pri }. NS: host names.
	 */
	public static function lookup( $name, $type ) {
		$name = strtolower( rtrim( trim( (string) $name ), '.' ) );
		$key  = $type . ' ' . $name;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		/**
		 * Answers a DNS lookup instead of the server's resolver (for tests).
		 *
		 * @param array|null $records Null to look it up; otherwise the records, as lookup() returns them.
		 * @param string     $name    Name.
		 * @param string     $type    TXT, MX or NS.
		 */
		$records = apply_filters( 'nemvo_email_pre_dns_lookup', null, $name, $type );
		if ( null !== $records ) {
			self::$cache[ $key ] = array_values( (array) $records );
			return self::$cache[ $key ];
		}

		$types = array(
			'TXT' => DNS_TXT,
			'MX'  => DNS_MX,
			'NS'  => DNS_NS,
		);
		$out   = array();
		if ( '' !== $name && isset( $types[ $type ] ) && function_exists( 'dns_get_record' ) ) {
			// A missing name makes some PHP versions warn; that is an answer (no records), not an error.
			$answer = @dns_get_record( $name, $types[ $type ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $answer as $record ) {
				if ( ( $record['type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( 'TXT' === $type ) {
					$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
				} elseif ( 'MX' === $type ) {
					$out[] = array(
						'host' => strtolower( rtrim( (string) $record['target'], '.' ) ),
						'pri'  => (int) $record['pri'],
					);
				} else {
					$out[] = strtolower( rtrim( (string) $record['target'], '.' ) );
				}
			}
		}
		self::$cache[ $key ] = $out;
		return $out;
	}

	/**
	 * The last stored check, if it is for this domain.
	 *
	 * @param string $domain Domain.
	 * @return array|null
	 */
	public static function stored( $domain ) {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) && ( $stored['domain'] ?? '' ) === strtolower( (string) $domain ) ? $stored : null;
	}

	/**
	 * Check a domain for a provider.
	 *
	 * @param string   $domain   Sender domain.
	 * @param Provider $provider Provider.
	 * @param bool     $store    Remember the result (for screens and Site Health).
	 * @return array
	 */
	public static function check( $domain, Provider $provider, $store = true ) {
		self::$cache = array();
		$domain      = strtolower( trim( (string) $domain ) );
		$profile     = $provider->dns_profile();
		$account     = $provider->account();
		$from        = Mailer::from_email( $provider );
		$selector    = (string) Settings::get( 'dkim_selector' );
		$selector    = '' !== $selector ? $selector : $profile['dkim_selector'];
		$personal    = in_array( $domain, $profile['personal_domains'], true );

		$result = array(
			'domain'   => $domain,
			'zone'     => self::org_domain( $domain ),
			'time'     => time(),
			'provider' => $provider->id(),
			'personal' => $personal,
			'host'     => $personal ? array(
				'name' => '',
				'ns'   => '',
			) : self::dns_host( $domain ),
			'state'    => 'good',
			'checks'   => array(),
		);

		if ( ! $personal ) {
			$account_domain = strtolower( (string) substr( (string) strrchr( $account, '@' ), 1 ) );
			$contact        = '' !== $account && self::org_domain( $account_domain ) === self::org_domain( $domain ) ? $account : $from;
			$spf            = self::spf( $domain, $profile['spf_include'] );
			$dkim           = self::dkim( $domain, $selector, $profile, $provider );
			$dmarc          = self::dmarc( $domain, $spf, $dkim, $account_domain, $contact );
			$mx             = self::mx( $domain, $profile['mx'] );

			$result['checks'] = array(
				'spf'   => $spf,
				'dkim'  => $dkim,
				'dmarc' => $dmarc,
				'mx'    => $mx,
			);
			$rank             = array(
				'good'     => 0,
				'neutral'  => 0,
				'warning'  => 1,
				'critical' => 2,
			);
			foreach ( $result['checks'] as $check ) {
				if ( $rank[ $check['state'] ] > $rank[ $result['state'] ] ) {
					$result['state'] = $check['state'];
				}
			}
			foreach ( $result['checks'] as $name => $check ) {
				if ( ! empty( $check['fix'] ) ) {
					$result['checks'][ $name ]['fix']['relative'] = self::relative( $check['fix']['name'], $result['zone'] );
				}
			}
		}

		if ( $store ) {
			update_option( self::OPTION, $result, false );
		}
		return $result;
	}

	/*
	 * SPF
	 * ------------------------------------------------------------------
	 */

	/**
	 * SPF records of a name.
	 *
	 * @param string $name Name.
	 * @return string[]
	 */
	private static function spf_records( $name ) {
		return array_values(
			array_filter(
				array_map( 'trim', self::lookup( $name, 'TXT' ) ),
				static function ( $txt ) {
					return (bool) preg_match( '/^v=spf1(\s|$)/i', $txt );
				}
			)
		);
	}

	/**
	 * Check SPF.
	 *
	 * @param string $domain  Domain.
	 * @param string $spf_include The provider's include.
	 * @return array
	 */
	private static function spf( $domain, $spf_include ) {
		$records = self::spf_records( $domain );
		$base    = array(
			'label' => 'SPF',
			'found' => $records,
		);

		if ( ! $records ) {
			return $base + array(
				'state'  => 'critical',
				/* translators: %s: domain */
				'title'  => sprintf( __( '%s has no SPF record', 'nemvo-email' ), $domain ),
				'detail' => __( 'SPF lists the servers allowed to send email for your domain. Without it, receivers can\'t tell your email from forgeries. Add this record:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => $domain,
					'value' => 'v=spf1 include:' . $spf_include . ' ~all',
					'mode'  => 'add',
				),
			);
		}

		if ( count( $records ) > 1 ) {
			$merged = self::spf_merge( $records, $spf_include );
			return $base + array(
				'state'  => 'critical',
				/* translators: 1: domain, 2: number of records */
				'title'  => sprintf( __( '%1$s has %2$d SPF records', 'nemvo-email' ), $domain, count( $records ) ),
				'detail' => __( 'A domain may have only one SPF record; with several, receivers treat SPF as broken. Replace them with this single record:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => $domain,
					'value' => $merged,
					'mode'  => 'replace',
				),
			);
		}

		$record       = $records[0];
		$lookups      = 0;
		$found        = self::spf_walk( $record, $spf_include, $lookups, 0 );
		$all          = preg_match( '/(?:^|\s)([+?~-]?)all(?:\s|$)/i', $record, $m ) ? ( '' === $m[1] ? '+' : $m[1] ) : '';
		$has_redirect = (bool) preg_match( '/(?:^|\s)redirect=/i', $record );

		if ( ! $found ) {
			return $base + array(
				'state'  => 'critical',
				/* translators: %s: domain */
				'title'  => sprintf( __( 'SPF for %s doesn\'t include Google', 'nemvo-email' ), $domain ),
				'detail' => __( 'Your SPF record lists other servers but not Google\'s, so email sent through Google fails SPF. Change the record to:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => $domain,
					'value' => self::spf_add( $record, $spf_include ),
					'mode'  => 'replace',
				),
			);
		}
		if ( $lookups > self::SPF_LOOKUP_LIMIT ) {
			return $base + array(
				'state'  => 'critical',
				/* translators: %d: number of lookups */
				'title'  => sprintf( __( 'SPF needs %d DNS lookups; the limit is 10', 'nemvo-email' ), $lookups ),
				'detail' => __( 'Receivers stop after 10 lookups (each include, a, mx and redirect counts, including the ones inside includes) and treat SPF as broken. Remove includes for services you no longer send with.', 'nemvo-email' ),
			);
		}
		if ( '+' === $all || '?' === $all ) {
			return $base + array(
				'state'  => 'warning',
				/* translators: %s: the all mechanism, e.g. +all */
				'title'  => sprintf( __( 'SPF ends with %s', 'nemvo-email' ), $all . 'all' ),
				'detail' => '+' === $all
					? __( '"+all" allows every server in the world to send as your domain, which makes SPF useless. End the record with ~all instead:', 'nemvo-email' )
					: __( '"?all" says nothing about servers that aren\'t listed. End the record with ~all instead:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => $domain,
					'value' => trim( preg_replace( '/(^|\s)[+?]?all(\s|$)/i', '$1~all$2', $record ) ),
					'mode'  => 'replace',
				),
			);
		}
		if ( '' === $all && ! $has_redirect ) {
			return $base + array(
				'state'  => 'warning',
				'title'  => __( 'SPF has no ending', 'nemvo-email' ),
				'detail' => __( 'Without "~all" (or "-all") at the end, servers that aren\'t listed are neither allowed nor refused. Change the record to:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => $domain,
					'value' => trim( $record ) . ' ~all',
					'mode'  => 'replace',
				),
			);
		}
		return $base + array(
			'state'  => 'good',
			/* translators: %s: domain */
			'title'  => sprintf( __( 'SPF allows Google to send for %s', 'nemvo-email' ), $domain ),
			/* translators: %d: number of lookups */
			'detail' => sprintf( _n( 'The record needs %d DNS lookup of the 10 allowed.', 'The record needs %d DNS lookups of the 10 allowed.', $lookups, 'nemvo-email' ), $lookups ),
		);
	}

	/**
	 * Walk an SPF record and its includes: count DNS lookups and find the
	 * provider's include (or Google's netblock records directly).
	 *
	 * @param string $record  SPF record.
	 * @param string $spf_include Include to find.
	 * @param int    $lookups Lookups so far.
	 * @param int    $depth   Recursion depth.
	 * @return bool Whether the include was found.
	 */
	private static function spf_walk( $record, $spf_include, &$lookups, $depth ) {
		$found = false;
		foreach ( preg_split( '/\s+/', trim( $record ) ) as $term ) {
			$term = strtolower( ltrim( $term, '+-~?' ) );
			if ( 0 === strpos( $term, 'include:' ) || 0 === strpos( $term, 'redirect=' ) ) {
				++$lookups;
				$target = substr( $term, strpos( $term, ( 0 === strpos( $term, 'include:' ) ) ? ':' : '=' ) + 1 );
				if ( $spf_include === $target || ( '_spf.google.com' === $spf_include && preg_match( '/^_netblocks\d*\.google\.com$/', $target ) ) ) {
					$found = true;
				}
				if ( $depth < 5 ) {
					$sub = self::spf_records( $target );
					if ( $sub && self::spf_walk( $sub[0], $spf_include, $lookups, $depth + 1 ) ) {
						$found = true;
					}
				}
			} elseif ( preg_match( '/^(a|mx|ptr|exists)([:\/]|$)/', $term ) ) {
				++$lookups;
			}
		}
		return $found;
	}

	/**
	 * Add an include before the record's "all" (or redirect).
	 *
	 * @param string $record  SPF record.
	 * @param string $spf_include Include.
	 * @return string
	 */
	public static function spf_add( $record, $spf_include ) {
		$terms = preg_split( '/\s+/', trim( $record ) );
		$add   = 'include:' . $spf_include;
		foreach ( $terms as $i => $term ) {
			if ( preg_match( '/^([+?~-]?all|redirect=.*)$/i', $term ) ) {
				array_splice( $terms, $i, 0, $add );
				return implode( ' ', $terms );
			}
		}
		$terms[] = $add;
		$terms[] = '~all';
		return implode( ' ', $terms );
	}

	/**
	 * Merge several SPF records into one that includes the provider.
	 *
	 * @param string[] $records Records.
	 * @param string   $spf_include Include.
	 * @return string
	 */
	public static function spf_merge( array $records, $spf_include ) {
		$terms  = array();
		$strict = false;
		foreach ( $records as $record ) {
			foreach ( preg_split( '/\s+/', trim( $record ) ) as $term ) {
				if ( preg_match( '/^v=spf1$/i', $term ) ) {
					continue;
				}
				if ( preg_match( '/^[+?~-]?all$/i', $term ) ) {
					// Keep "-all" if any record had it; otherwise end with "~all".
					$strict = $strict || '-all' === strtolower( $term );
					continue;
				}
				$terms[ strtolower( $term ) ] = $term;
			}
		}
		$merged  = trim( 'v=spf1 ' . implode( ' ', $terms ) ) . ( $strict ? ' -all' : ' ~all' );
		$lookups = 0;
		return self::spf_walk( $merged, $spf_include, $lookups, 0 ) ? $merged : self::spf_add( $merged, $spf_include );
	}

	/*
	 * DKIM
	 * ------------------------------------------------------------------
	 */

	/**
	 * Check the DKIM key.
	 *
	 * @param string   $domain   Domain.
	 * @param string   $selector Selector.
	 * @param array    $profile  Provider DNS profile.
	 * @param Provider $provider Provider.
	 * @return array
	 */
	private static function dkim( $domain, $selector, array $profile, Provider $provider ) {
		$name             = $selector . '._domainkey.' . $domain;
		$records          = array_values(
			array_filter(
				array_map( 'trim', self::lookup( $name, 'TXT' ) ),
				static function ( $txt ) {
					return (bool) preg_match( '/(^|;)\s*p=/i', $txt );
				}
			)
		);
		$base             = array(
			'label'    => 'DKIM',
			'selector' => $selector,
			'name'     => $name,
			'found'    => array_map( array( __CLASS__, 'shorten_key' ), $records ),
		);
		$personal_account = $provider->is_personal();

		if ( ! $records ) {
			$steps = array(
				/* translators: %s: link to the Google Admin console */
				sprintf( __( 'Open %s in the Google Admin console (Apps › Google Workspace › Gmail › Authenticate email).', 'nemvo-email' ), '<a href="' . esc_url( $profile['dkim_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Authenticate email', 'nemvo-email' ) . '</a>' ),
				/* translators: 1: domain, 2: selector */
				sprintf( __( 'Choose %1$s, then Generate new record with a 2048-bit key and the prefix selector "%2$s".', 'nemvo-email' ), '<strong>' . esc_html( $domain ) . '</strong>', esc_html( $selector ) ),
				/* translators: %s: DNS name */
				sprintf( __( 'Publish the TXT record Google shows, at %s, with your DNS provider.', 'nemvo-email' ), '<code>' . esc_html( $name ) . '</code>' ),
				__( 'Back in the Admin console, click Start authentication once the record is visible (usually within an hour), then check again here.', 'nemvo-email' ),
			);
			return $base + array(
				'state'  => 'critical',
				/* translators: %s: domain */
				'title'  => sprintf( __( 'Google doesn\'t sign email for %s with DKIM', 'nemvo-email' ), $domain ),
				'detail' => $personal_account
					? __( 'A personal Gmail account can\'t sign email for your own domain. Receivers can\'t verify it, and DMARC fails unless SPF aligns. Use a Google Workspace account for this domain.', 'nemvo-email' )
					/* translators: %s: selector */
					: sprintf( __( 'No DKIM key was found for the selector "%s". Without your own DKIM signature, Google signs with a google.com domain, which doesn\'t count for DMARC. Set it up in four steps:', 'nemvo-email' ), $selector ),
				'steps'  => $personal_account ? array() : $steps,
			);
		}

		$tags = self::tags( $records[0] );
		if ( '' === ( $tags['p'] ?? '' ) ) {
			return $base + array(
				'state'  => 'critical',
				/* translators: %s: DNS name */
				'title'  => sprintf( __( 'The DKIM key at %s is revoked', 'nemvo-email' ), $name ),
				'detail' => __( 'The record has an empty key (p=), which means "no longer valid". Generate a new record in the Google Admin console and publish it.', 'nemvo-email' ),
			);
		}
		$bits = self::key_bits( $tags['p'] );
		if ( $bits && $bits < 2048 ) {
			return $base + array(
				'state'  => 'warning',
				'bits'   => $bits,
				/* translators: %d: key size */
				'title'  => sprintf( __( 'DKIM works, with a %d-bit key', 'nemvo-email' ), $bits ),
				'detail' => __( '2048 bits is the current recommendation. In the Google Admin console, generate a new 2048-bit record, publish it, and click Start authentication again.', 'nemvo-email' ),
			);
		}
		return $base + array(
			'state'  => 'good',
			'bits'   => $bits,
			'title'  => $bits
				/* translators: 1: domain, 2: key size */
				? sprintf( __( 'DKIM key published for %1$s (%2$d-bit)', 'nemvo-email' ), $domain, $bits )
				/* translators: %s: domain */
				: sprintf( __( 'DKIM key published for %s', 'nemvo-email' ), $domain ),
			'detail' => __( 'Make sure Start authentication is on in the Google Admin console (Gmail › Authenticate email): the record alone doesn\'t turn signing on.', 'nemvo-email' ),
		);
	}

	/**
	 * Size of an RSA public key from a DKIM record.
	 *
	 * @param string $p Base64 key.
	 * @return int Bits, 0 when unknown.
	 */
	public static function key_bits( $p ) {
		if ( ! function_exists( 'openssl_pkey_get_public' ) ) {
			return 0;
		}
		$body = chunk_split( preg_replace( '/\s+/', '', $p ), 64, "\n" );
		foreach ( array( 'PUBLIC KEY', 'RSA PUBLIC KEY' ) as $label ) {
			$key = openssl_pkey_get_public( "-----BEGIN {$label}-----\n{$body}-----END {$label}-----\n" );
			if ( $key ) {
				$details = openssl_pkey_get_details( $key );
				return (int) ( $details['bits'] ?? 0 );
			}
		}
		return 0;
	}

	/**
	 * Show a DKIM record with its key shortened.
	 *
	 * @param string $record Record.
	 * @return string
	 */
	public static function shorten_key( $record ) {
		return (string) preg_replace_callback(
			'/(p=)([A-Za-z0-9+\/=]{40,})/',
			static function ( $m ) {
				return $m[1] . substr( $m[2], 0, 24 ) . '…' . substr( $m[2], -8 );
			},
			$record
		);
	}

	/*
	 * DMARC
	 * ------------------------------------------------------------------
	 */

	/**
	 * Check DMARC, and whether email would pass it.
	 *
	 * @param string $domain         Domain.
	 * @param array  $spf            SPF result.
	 * @param array  $dkim           DKIM result.
	 * @param string $account_domain Domain of the sending account.
	 * @param string $contact        Address for reports in a suggested record.
	 * @return array
	 */
	private static function dmarc( $domain, array $spf, array $dkim, $account_domain, $contact ) {
		$at      = $domain;
		$records = self::dmarc_records( $domain );
		if ( ! $records && self::org_domain( $domain ) !== $domain ) {
			$at      = self::org_domain( $domain );
			$records = self::dmarc_records( $at );
		}
		$base = array(
			'label' => 'DMARC',
			'name'  => '_dmarc.' . $at,
			'found' => $records,
		);

		// DMARC passes when SPF or DKIM passes for a domain aligned with the sender's.
		$spf_aligned  = 'critical' !== $spf['state'] && '' !== $account_domain && self::org_domain( $account_domain ) === self::org_domain( $domain );
		$dkim_aligned = 'critical' !== $dkim['state'];
		$passes       = $spf_aligned || $dkim_aligned;
		$contact      = '' !== $contact ? $contact : 'postmaster@' . $domain;

		if ( ! $records ) {
			return $base + array(
				'state'  => 'warning',
				/* translators: %s: domain */
				'title'  => sprintf( __( '%s has no DMARC policy', 'nemvo-email' ), $domain ),
				'detail' => __( 'DMARC tells receivers what to do with email that fails SPF and DKIM, and sends you reports. Gmail, Yahoo and Microsoft require it from bulk senders and trust domains that have one. Start with a policy that only monitors:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => '_dmarc.' . $domain,
					'value' => 'v=DMARC1; p=none; rua=mailto:' . $contact,
					'mode'  => 'add',
				),
			);
		}
		if ( count( $records ) > 1 ) {
			return $base + array(
				'state'  => 'critical',
				/* translators: %s: DNS name */
				'title'  => sprintf( __( '%s has more than one DMARC record', 'nemvo-email' ), '_dmarc.' . $at ),
				'detail' => __( 'Receivers ignore DMARC when there are several records. Delete all but one.', 'nemvo-email' ),
			);
		}

		$tags   = self::tags( $records[0] );
		$policy = strtolower( $at !== $domain && ! empty( $tags['sp'] ) ? $tags['sp'] : ( $tags['p'] ?? '' ) );
		if ( ! in_array( $policy, array( 'none', 'quarantine', 'reject' ), true ) ) {
			return $base + array(
				'state'  => 'critical',
				'title'  => __( 'The DMARC record has no valid policy', 'nemvo-email' ),
				'detail' => __( 'A DMARC record needs p=none, p=quarantine or p=reject. Replace it, for example with:', 'nemvo-email' ),
				'fix'    => array(
					'type'  => 'TXT',
					'name'  => '_dmarc.' . $at,
					'value' => 'v=DMARC1; p=none; rua=mailto:' . $contact,
					'mode'  => 'replace',
				),
			);
		}

		$reports = '';
		if ( ! empty( $tags['rua'] ) ) {
			/* translators: %s: report addresses */
			$reports = ' ' . sprintf( __( 'Reports go to %s.', 'nemvo-email' ), str_replace( 'mailto:', '', $tags['rua'] ) );
		}
		$base['policy'] = $policy;

		if ( ! $passes ) {
			return $base + array(
				'state'  => 'none' === $policy ? 'warning' : 'critical',
				'title'  => 'none' === $policy
					/* translators: %s: domain */
					? sprintf( __( 'Email from %s fails DMARC', 'nemvo-email' ), $domain )
					/* translators: 1: domain, 2: policy (quarantine or reject) */
					: sprintf( __( 'DMARC tells receivers to %2$s email from %1$s', 'nemvo-email' ), $domain, $policy ),
				'detail' => __( 'Email sent through Google passes neither DKIM for your domain nor SPF for the same domain, so it fails DMARC. Set up DKIM above; that fixes it.', 'nemvo-email' ) . $reports,
			);
		}
		if ( 'none' === $policy ) {
			return $base + array(
				'state'  => 'good',
				/* translators: %s: domain */
				'title'  => sprintf( __( 'DMARC monitors %s (p=none)', 'nemvo-email' ), $domain ),
				'detail' => __( 'Receivers report on your email but don\'t act on failures. Once the reports show only your own services, consider p=quarantine.', 'nemvo-email' ) . $reports,
			);
		}
		return $base + array(
			'state'  => 'good',
			/* translators: 1: domain, 2: policy */
			'title'  => sprintf( __( 'DMARC protects %1$s (p=%2$s)', 'nemvo-email' ), $domain, $policy ),
			'detail' => __( 'Email that fails SPF and DKIM is held back by receivers, and email through Google passes.', 'nemvo-email' ) . $reports,
		);
	}

	/**
	 * DMARC records at _dmarc.domain.
	 *
	 * @param string $domain Domain.
	 * @return string[]
	 */
	private static function dmarc_records( $domain ) {
		return array_values(
			array_filter(
				array_map( 'trim', self::lookup( '_dmarc.' . $domain, 'TXT' ) ),
				static function ( $txt ) {
					return (bool) preg_match( '/^v=DMARC1\s*(;|$)/i', $txt );
				}
			)
		);
	}

	/*
	 * MX
	 * ------------------------------------------------------------------
	 */

	/**
	 * Where the domain receives email (for replies).
	 *
	 * @param string   $domain   Domain.
	 * @param string[] $suffixes The provider's mail host suffixes.
	 * @return array
	 */
	private static function mx( $domain, array $suffixes ) {
		$records = self::lookup( $domain, 'MX' );
		usort(
			$records,
			static function ( $a, $b ) {
				return $a['pri'] <=> $b['pri'];
			}
		);
		$hosts = array_values( array_filter( wp_list_pluck( $records, 'host' ) ) );
		$base  = array(
			'label' => 'MX',
			'found' => $hosts,
		);
		if ( ! $hosts ) {
			return $base + array(
				'state'  => 'warning',
				/* translators: %s: domain */
				'title'  => sprintf( __( '%s doesn\'t receive email', 'nemvo-email' ), $domain ),
				'detail' => __( 'There are no MX records, so replies to your messages bounce, and some receivers distrust senders that can\'t receive. Send from an address that receives email.', 'nemvo-email' ),
			);
		}
		$at_provider = true;
		foreach ( $hosts as $host ) {
			$match = false;
			foreach ( $suffixes as $suffix ) {
				if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
					$match = true;
				}
			}
			$at_provider = $at_provider && $match;
		}
		return $base + array(
			'state'  => $at_provider ? 'good' : 'neutral',
			'title'  => $at_provider
				/* translators: %s: domain */
				? sprintf( __( 'Email to %s is received by Google', 'nemvo-email' ), $domain )
				/* translators: 1: domain, 2: mail server */
				: sprintf( __( 'Email to %1$s is received by %2$s', 'nemvo-email' ), $domain, $hosts[0] ),
			'detail' => $at_provider
				? __( 'Replies to your messages arrive in Google Workspace.', 'nemvo-email' )
				: __( 'That\'s fine: where you receive email doesn\'t affect sending through Google. Replies go there.', 'nemvo-email' ),
		);
	}

	/*
	 * Helpers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Tags of a DKIM or DMARC record ("v=DMARC1; p=none").
	 *
	 * @param string $record Record.
	 * @return array
	 */
	public static function tags( $record ) {
		$tags = array();
		foreach ( explode( ';', (string) $record ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 === count( $pair ) ) {
				$tags[ strtolower( trim( $pair[0] ) ) ] = trim( preg_replace( '/\s+/', '', $pair[1] ) );
			}
		}
		return $tags;
	}

	/**
	 * The registrable domain, approximately (mail.example.co.uk => example.co.uk).
	 *
	 * @param string $domain Domain.
	 * @return string
	 */
	public static function org_domain( $domain ) {
		$labels = explode( '.', strtolower( trim( (string) $domain, '.' ) ) );
		$count  = count( $labels );
		if ( $count <= 2 ) {
			return implode( '.', $labels );
		}
		$keep = 2;
		if ( 2 === strlen( $labels[ $count - 1 ] ) && in_array( $labels[ $count - 2 ], array( 'co', 'com', 'net', 'org', 'gov', 'ac', 'edu', 'ltd', 'plc', 'or', 'ne' ), true ) ) {
			$keep = 3;
		}
		return implode( '.', array_slice( $labels, -$keep ) );
	}

	/**
	 * A record name relative to its zone, as most DNS panels want it.
	 *
	 * @param string $name Full name.
	 * @param string $zone Zone.
	 * @return string "@" for the zone itself.
	 */
	public static function relative( $name, $zone ) {
		if ( $name === $zone ) {
			return '@';
		}
		$suffix = '.' . $zone;
		return substr( $name, -strlen( $suffix ) ) === $suffix ? substr( $name, 0, -strlen( $suffix ) ) : $name;
	}

	/**
	 * Who hosts the domain's DNS, from its name servers.
	 *
	 * @param string $domain Domain.
	 * @return array { name, ns }
	 */
	public static function dns_host( $domain ) {
		$ns = self::lookup( $domain, 'NS' );
		if ( ! $ns && self::org_domain( $domain ) !== $domain ) {
			$ns = self::lookup( self::org_domain( $domain ), 'NS' );
		}
		$names = array(
			'ns.cloudflare'         => 'Cloudflare',
			'awsdns'                => 'Amazon Route 53',
			'googledomains.com'     => 'Google Cloud DNS',
			'ns-cloud-'             => 'Google Cloud DNS',
			'azure-dns'             => 'Azure DNS',
			'domaincontrol.com'     => 'GoDaddy',
			'registrar-servers.com' => 'Namecheap',
			'digitalocean.com'      => 'DigitalOcean',
			'linode.com'            => 'Akamai (Linode)',
			'hetzner'               => 'Hetzner',
			'ovh.net'               => 'OVHcloud',
			'gandi.net'             => 'Gandi',
			'one.com'               => 'one.com',
			'loopia.'               => 'Loopia',
			'desec.'                => 'deSEC',
			'transip.'              => 'TransIP',
			'strato.de'             => 'Strato',
			'ui-dns.'               => 'IONOS',
			'squarespacedns'        => 'Squarespace',
			'wixdns.net'            => 'Wix',
			'dns-parking.com'       => 'Hostinger',
			'hostinger'             => 'Hostinger',
			'bluehost.com'          => 'Bluehost',
			'siteground.net'        => 'SiteGround',
			'dnsimple.com'          => 'DNSimple',
			'nsone.net'             => 'NS1',
			'porkbun.com'           => 'Porkbun',
			'name-services.com'     => 'Enom',
			'hover.com'             => 'Hover',
			'vercel-dns.com'        => 'Vercel',
			'nsone'                 => 'NS1',
			'dnsmadeeasy.com'       => 'DNS Made Easy',
			'ultradns'              => 'UltraDNS',
			'hostnet.nl'            => 'Hostnet',
			'antagonist.nl'         => 'Antagonist',
			'mijndomein.nl'         => 'Mijndomein',
			'binero.se'             => 'Binero',
			'inleed.net'            => 'Inleed',
			'glesys.se'             => 'GleSYS',
			'websupport.'           => 'Websupport',
			'infomaniak.ch'         => 'Infomaniak',
			'netlify.com'           => 'Netlify',
		);
		$first = $ns ? (string) $ns[0] : '';
		foreach ( $names as $needle => $name ) {
			foreach ( $ns as $server ) {
				if ( false !== strpos( (string) $server, $needle ) ) {
					return array(
						'name' => $name,
						'ns'   => $first,
					);
				}
			}
		}
		return array(
			'name' => '',
			'ns'   => $first,
		);
	}
}
