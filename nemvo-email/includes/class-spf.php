<?php
/**
 * SPF evaluation.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a domain's SPF record for one sending address the way a
 * receiving server does (RFC 7208 check_host()): mechanisms, include: and
 * redirect=, a, mx and exists lookups, macros, and the 10-lookup limit.
 * That answers "does Google pass?" for records written any way at all:
 * include:_spf.google.com, Google's ranges listed directly, or a record
 * flattened and hosted by an SPF or DMARC service.
 */
class Spf {

	const MAX_LOOKUPS = 10;

	const RESULTS = array(
		'+' => 'pass',
		'-' => 'fail',
		'~' => 'softfail',
		'?' => 'neutral',
	);

	/**
	 * The sending address.
	 *
	 * @var string
	 */
	private $ip = '';

	/**
	 * The envelope sender.
	 *
	 * @var string
	 */
	private $sender = '';

	/**
	 * The sending server's HELO name.
	 *
	 * @var string
	 */
	private $helo = '';

	/**
	 * DNS lookups so far.
	 *
	 * @var int
	 */
	private $lookups = 0;

	/**
	 * Why the result is permerror.
	 *
	 * @var string
	 */
	private $error = '';

	/**
	 * The mechanism that decided the result.
	 *
	 * @var string
	 */
	private $match = '';

	/**
	 * Evaluate SPF.
	 *
	 * @param string $ip     Sending address (IPv4 or IPv6).
	 * @param string $domain Domain to check.
	 * @param string $sender Envelope sender address.
	 * @param string $helo   HELO name of the sending server.
	 * @return array { result: pass|fail|softfail|neutral|none|permerror, lookups, match, error }
	 */
	public static function evaluate( $ip, $domain, $sender, $helo = '' ) {
		$spf         = new self();
		$spf->ip     = (string) $ip;
		$spf->sender = (string) $sender;
		$spf->helo   = (string) $helo;
		$result      = false === @inet_pton( $spf->ip ) ? 'permerror' : $spf->check( strtolower( rtrim( (string) $domain, '.' ) ), 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid address is an answer here.
		return array(
			'result'  => $result,
			'lookups' => $spf->lookups,
			'match'   => $spf->match,
			'error'   => $spf->error,
		);
	}

	/**
	 * Expand macros for a sending address and sender (see expand()).
	 *
	 * @param string $spec   Domain-spec.
	 * @param string $domain Current domain.
	 * @param string $ip     Sending address.
	 * @param string $sender Envelope sender.
	 * @param string $helo   HELO name.
	 * @return string
	 */
	public static function expand_macros( $spec, $domain, $ip, $sender, $helo = '' ) {
		$spf         = new self();
		$spf->ip     = (string) $ip;
		$spf->sender = (string) $sender;
		$spf->helo   = (string) $helo;
		return $spf->expand( $spec, $domain );
	}

	/**
	 * Whether an address is IPv6.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	public static function is_ipv6( $ip ) {
		return false !== strpos( (string) $ip, ':' );
	}

	/**
	 * RFC 7208's check_host() for one domain.
	 *
	 * @param string $domain Domain.
	 * @param int    $depth  Include/redirect depth.
	 * @return string Result.
	 */
	private function check( $domain, $depth ) {
		if ( $depth > self::MAX_LOOKUPS ) {
			return $this->fail_with( __( 'includes or redirects loop', 'nemvo-email' ) );
		}
		$records = array_values(
			array_filter(
				array_map( 'trim', Dns::lookup( $domain, 'TXT' ) ),
				static function ( $txt ) {
					return (bool) preg_match( '/^v=spf1(\s|$)/i', $txt );
				}
			)
		);
		if ( ! $records ) {
			return 'none';
		}
		if ( count( $records ) > 1 ) {
			/* translators: %s: domain */
			return $this->fail_with( sprintf( __( '%s has more than one SPF record', 'nemvo-email' ), $domain ) );
		}

		$terms = preg_split( '/\s+/', $records[0] );
		array_shift( $terms );
		$redirect = null;
		foreach ( $terms as $term ) {
			if ( '' === $term ) {
				continue;
			}
			if ( preg_match( '/^([a-z][a-z0-9_.-]*)=(.*)$/i', $term, $m ) ) {
				// Modifiers: redirect= applies when nothing matched; exp= and unknown ones are ignored.
				if ( 'redirect' === strtolower( $m[1] ) ) {
					$redirect = $m[2];
				}
				continue;
			}
			$qualifier = '+';
			if ( in_array( $term[0], array( '+', '-', '~', '?' ), true ) ) {
				$qualifier = $term[0];
				$term      = substr( $term, 1 );
			}
			$matched = $this->mechanism( $term, $domain, $depth );
			if ( is_string( $matched ) ) {
				return $matched;
			}
			if ( $matched ) {
				$this->match = $this->match ? $this->match : $qualifier . $term . ( $depth ? ' (' . $domain . ')' : '' );
				return self::RESULTS[ $qualifier ];
			}
		}

		if ( null !== $redirect ) {
			if ( ! $this->count() ) {
				return 'permerror';
			}
			$target = $this->expand( $redirect, $domain );
			$result = $this->check( $target, $depth + 1 );
			/* translators: %s: domain */
			return 'none' === $result ? $this->fail_with( sprintf( __( 'redirect=%s has no SPF record', 'nemvo-email' ), $target ) ) : $result;
		}
		return 'neutral';
	}

	/**
	 * Whether a mechanism matches.
	 *
	 * @param string $term   Mechanism without its qualifier.
	 * @param string $domain Current domain.
	 * @param int    $depth  Depth.
	 * @return bool|string True or false, or "permerror".
	 */
	private function mechanism( $term, $domain, $depth ) {
		if ( ! preg_match( '/^(all|include|a|mx|ptr|ip4|ip6|exists)([:\/].*)?$/i', $term, $m ) ) {
			/* translators: %s: SPF term */
			return $this->fail_with( sprintf( __( 'unknown term "%s"', 'nemvo-email' ), $term ) );
		}
		$name = strtolower( $m[1] );
		$rest = $m[2] ?? '';
		$arg  = 0 === strpos( $rest, ':' ) ? substr( $rest, 1 ) : $rest;

		switch ( $name ) {
			case 'all':
				return true;

			case 'include':
				if ( '' === $arg || 0 !== strpos( $rest, ':' ) ) {
					return $this->fail_with( __( 'include: without a domain', 'nemvo-email' ) );
				}
				if ( ! $this->count() ) {
					return 'permerror';
				}
				$target = $this->expand( $arg, $domain );
				$result = $this->check( $target, $depth + 1 );
				if ( 'pass' === $result ) {
					$this->match = $this->match ? $this->match : 'include:' . $target;
					return true;
				}
				if ( in_array( $result, array( 'fail', 'softfail', 'neutral' ), true ) ) {
					return false;
				}
				/* translators: %s: domain */
				return 'none' === $result ? $this->fail_with( sprintf( __( 'include:%s has no SPF record', 'nemvo-email' ), $target ) ) : $result;

			case 'a':
			case 'mx':
				if ( ! $this->count() ) {
					return 'permerror';
				}
				// a, a:host, a/24, a//64, a:host/24//64.
				if ( 0 === strpos( $rest, ':' ) ) {
					$slash = strpos( $arg, '/' );
					$host  = false === $slash ? $arg : substr( $arg, 0, $slash );
					$cidrs = false === $slash ? '' : substr( $arg, $slash );
				} else {
					$host  = '';
					$cidrs = $rest;
				}
				$pieces = explode( '//', $cidrs, 2 );
				$cidr4  = '' !== trim( $pieces[0], '/' ) ? (int) trim( $pieces[0], '/' ) : 32;
				$cidr6  = isset( $pieces[1] ) && '' !== $pieces[1] ? (int) $pieces[1] : 128;
				$target = '' === $host ? $domain : $this->expand( $host, $domain );
				$hosts  = 'mx' === $name ? array_slice( array_filter( wp_list_pluck( Dns::lookup( $target, 'MX' ), 'host' ) ), 0, 10 ) : array( $target );
				foreach ( $hosts as $one ) {
					foreach ( Dns::lookup( $one, self::is_ipv6( $this->ip ) ? 'AAAA' : 'A' ) as $address ) {
						if ( self::in_network( $this->ip, $address, self::is_ipv6( $this->ip ) ? $cidr6 : $cidr4 ) ) {
							return true;
						}
					}
				}
				return false;

			case 'ptr':
				// Discouraged by RFC 7208 and not checkable from here; counts as a lookup, never matches.
				return $this->count() ? false : 'permerror';

			case 'ip4':
			case 'ip6':
				$network = explode( '/', $arg, 2 );
				$length  = isset( $network[1] ) ? (int) $network[1] : ( 'ip6' === $name ? 128 : 32 );
				if ( ( 'ip6' === $name ) !== self::is_ipv6( $this->ip ) ) {
					return false;
				}
				return self::in_network( $this->ip, $network[0], $length );

			case 'exists':
				if ( '' === $arg ) {
					return $this->fail_with( __( 'exists: without a domain', 'nemvo-email' ) );
				}
				if ( ! $this->count() ) {
					return 'permerror';
				}
				return (bool) Dns::lookup( $this->expand( $arg, $domain ), 'A' );
		}
		return false;
	}

	/**
	 * Count a DNS lookup against the limit.
	 *
	 * @return bool False once the limit is exceeded.
	 */
	private function count() {
		++$this->lookups;
		if ( $this->lookups > self::MAX_LOOKUPS ) {
			$this->fail_with( __( 'more than 10 DNS lookups', 'nemvo-email' ) );
			return false;
		}
		return true;
	}

	/**
	 * Record why the result is permerror.
	 *
	 * @param string $reason Reason.
	 * @return string "permerror".
	 */
	private function fail_with( $reason ) {
		if ( '' === $this->error ) {
			$this->error = $reason;
		}
		return 'permerror';
	}

	/**
	 * Whether an address is inside a network.
	 *
	 * @param string $ip      Address.
	 * @param string $network Network address.
	 * @param int    $length  Prefix length.
	 * @return bool
	 */
	public static function in_network( $ip, $network, $length ) {
		$a = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input simply doesn't match.
		$b = @inet_pton( (string) $network ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$length = max( 0, min( strlen( $a ) * 8, (int) $length ) );
		$bytes  = intdiv( $length, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$bits = $length % 8;
		if ( 0 === $bits ) {
			return true;
		}
		$mask = 0xff << ( 8 - $bits ) & 0xff;
		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}

	/**
	 * Expand macros in a domain-spec (RFC 7208 section 7).
	 *
	 * @param string $spec   Domain-spec.
	 * @param string $domain Current domain (%{d}).
	 * @return string
	 */
	private function expand( $spec, $domain ) {
		$sender = '' !== $this->sender ? $this->sender : 'postmaster@' . $domain;
		$at     = strrpos( $sender, '@' );
		$local  = false === $at ? 'postmaster' : substr( $sender, 0, $at );
		$sdom   = false === $at ? $sender : substr( $sender, $at + 1 );
		$six    = self::is_ipv6( $this->ip );
		$ip     = $six ? implode( '.', str_split( bin2hex( (string) inet_pton( $this->ip ) ) ) ) : $this->ip;
		$values = array(
			's' => $sender,
			'l' => '' !== $local ? $local : 'postmaster',
			'o' => $sdom,
			'd' => $domain,
			'i' => $ip,
			'c' => $this->ip,
			'p' => 'unknown',
			'v' => $six ? 'ip6' : 'in-addr',
			'h' => '' !== $this->helo ? $this->helo : $domain,
			'r' => 'unknown',
			't' => (string) time(),
		);

		$out = preg_replace_callback(
			'/%(?:\{([a-zA-Z])(\d*)(r?)([.\-+,\/_=]*)\}|(%)|(_)|(-))/',
			static function ( $m ) use ( $values ) {
				if ( ! empty( $m[5] ) ) {
					return '%';
				}
				if ( ! empty( $m[6] ) ) {
					return ' ';
				}
				if ( ! empty( $m[7] ) ) {
					return '%20';
				}
				$value      = $values[ strtolower( $m[1] ) ] ?? '';
				$delimiters = '' === $m[4] ? '.' : $m[4];
				$parts      = preg_split( '/[' . preg_quote( $delimiters, '/' ) . ']/', $value );
				if ( '' !== $m[3] ) {
					$parts = array_reverse( $parts );
				}
				if ( '' !== $m[2] && (int) $m[2] > 0 ) {
					$parts = array_slice( $parts, - (int) $m[2] );
				}
				$value = implode( '.', $parts );
				// An upper-case macro letter means URL-encode the value.
				return strtoupper( $m[1] ) === $m[1] ? rawurlencode( $value ) : $value;
			},
			(string) $spec
		);
		// Names over 253 characters lose labels from the left.
		$length = strlen( $out );
		while ( $length > 253 && false !== strpos( $out, '.' ) ) {
			$out    = substr( $out, strpos( $out, '.' ) + 1 );
			$length = strlen( $out );
		}
		return strtolower( rtrim( $out, '.' ) );
	}
}
