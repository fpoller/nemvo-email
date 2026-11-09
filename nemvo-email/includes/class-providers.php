<?php
/**
 * Provider registry.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

use NemvoEmail\Providers\Google;
use NemvoEmail\Providers\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The available providers and the one in use.
 */
class Providers {

	/**
	 * Instances by id.
	 *
	 * @var Provider[]|null
	 */
	private static $all = null;

	/**
	 * All providers.
	 *
	 * @return Provider[]
	 */
	public static function all() {
		if ( null === self::$all ) {
			$google    = new Google();
			self::$all = array( $google->id() => $google );
		}
		return self::$all;
	}

	/**
	 * Forget the instances and their cached options (after switching sites).
	 */
	public static function reset() {
		self::$all = null;
	}

	/**
	 * One provider.
	 *
	 * @param string $id Id.
	 * @return Provider|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	/**
	 * The provider chosen in the settings (Google until there are others).
	 *
	 * @return Provider
	 */
	public static function current() {
		$provider = self::get( (string) Settings::get( 'provider' ) );
		if ( ! $provider ) {
			$all      = self::all();
			$provider = reset( $all );
		}
		return $provider;
	}

	/**
	 * Display name for a provider id stored in the log, including the
	 * transports WordPress uses without a provider.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function label( $id ) {
		$provider = self::get( $id );
		if ( $provider ) {
			return $provider->name();
		}
		$labels = array(
			'mail'     => __( 'This server (PHP mail)', 'nemvo-email' ),
			'sendmail' => __( 'This server (sendmail)', 'nemvo-email' ),
			'smtp'     => __( 'SMTP', 'nemvo-email' ),
			'paused'   => __( 'Not sent (paused)', 'nemvo-email' ),
		);
		return $labels[ $id ] ?? $id;
	}
}
