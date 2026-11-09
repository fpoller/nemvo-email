<?php
/**
 * REST API for the admin screens.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under nemvo-email/v1: statistics, the log, resending and test
 * messages. All need the plugin's capability (manage_options by default).
 */
class Rest {

	const NS = 'nemvo-email/v1';

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		$allowed = static function () {
			return current_user_can( Plugin::cap() );
		};
		$date    = array(
			'type'              => 'string',
			'validate_callback' => static function ( $value ) {
				return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value );
			},
		);

		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'stats' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'from' => $date + array( 'required' => true ),
					'to'   => $date + array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'log' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array( '', 'sent', 'failed', 'paused' ),
					),
					'search'   => array( 'type' => 'string' ),
					'source'   => array( 'type' => 'string' ),
					'domain'   => array( 'type' => 'string' ),
					'subject'  => array( 'type' => 'string' ),
					'from'     => $date,
					'to'       => $date,
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 200,
						'default' => 50,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/log/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'entry' ),
					'permission_callback' => $allowed,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => $allowed,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/log/(?P<id>\d+)/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'resend' ),
				'permission_callback' => $allowed,
			)
		);
		register_rest_route(
			self::NS,
			'/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test' ),
				'permission_callback' => $allowed,
				'args'                => array(
					'to' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /stats.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function stats( \WP_REST_Request $request ) {
		$from = (string) $request['from'];
		$to   = (string) $request['to'];
		if ( $to < $from ) {
			return new \WP_Error( 'range', __( 'The period ends before it starts.', 'nemvo-email' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Stats::report( $from, $to ) );
	}

	/**
	 * GET /log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function log( \WP_REST_Request $request ) {
		$result            = Log::query(
			array(
				'status'   => (string) $request['status'],
				'search'   => sanitize_text_field( (string) $request['search'] ),
				'source'   => null === $request['source'] ? null : sanitize_text_field( (string) $request['source'] ),
				'domain'   => sanitize_text_field( (string) $request['domain'] ),
				'subject'  => sanitize_text_field( (string) $request['subject'] ),
				'from'     => (string) $request['from'],
				'to'       => (string) $request['to'],
				'page'     => (int) $request['page'],
				'per_page' => (int) $request['per_page'],
			)
		);
		$result['sources'] = Log::sources();
		return rest_ensure_response( $result );
	}

	/**
	 * GET /log/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function entry( \WP_REST_Request $request ) {
		$row = Log::get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'This log entry no longer exists.', 'nemvo-email' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $row );
	}

	/**
	 * DELETE /log/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function delete( \WP_REST_Request $request ) {
		return rest_ensure_response( array( 'deleted' => Log::delete( array( (int) $request['id'] ) ) ) );
	}

	/**
	 * POST /log/<id>/resend.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resend( \WP_REST_Request $request ) {
		$row = Log::get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'This log entry no longer exists.', 'nemvo-email' ), array( 'status' => 404 ) );
		}
		$result          = Mailer::resend( $row );
		$result['entry'] = $result['log_id'] ? Log::get( $result['log_id'] ) : null;
		return rest_ensure_response( $result );
	}

	/**
	 * POST /test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function test( \WP_REST_Request $request ) {
		$to = sanitize_email( (string) $request['to'] );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'to', __( 'Enter a valid email address.', 'nemvo-email' ), array( 'status' => 400 ) );
		}
		$result          = Mailer::send_test( $to );
		$result['entry'] = $result['log_id'] ? Log::get( $result['log_id'] ) : null;
		return rest_ensure_response( $result );
	}
}
