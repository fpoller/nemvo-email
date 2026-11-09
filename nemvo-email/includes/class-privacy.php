<?php
/**
 * Personal data: export, erasure and the privacy policy text.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * The log holds email addresses and subjects (and, when kept, content).
 * Tools › Export/Erase Personal Data covers it.
 */
class Privacy {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['nemvo-email'] = array(
			'exporter_friendly_name' => __( 'Email log', 'nemvo-email' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['nemvo-email'] = array(
			'eraser_friendly_name' => __( 'Email log', 'nemvo-email' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export log entries sent to or from an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page (all entries fit on one).
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		unset( $page );
		$items = array();
		foreach ( Log::for_address( $email ) as $row ) {
			$items[] = array(
				'group_id'    => 'nemvo-email',
				'group_label' => __( 'Emails sent by the site', 'nemvo-email' ),
				'item_id'     => 'nemvo-email-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date (UTC)', 'nemvo-email' ),
						'value' => $row['created_at'],
					),
					array(
						'name'  => __( 'Subject', 'nemvo-email' ),
						'value' => $row['subject'],
					),
					array(
						'name'  => __( 'Status', 'nemvo-email' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'From', 'nemvo-email' ),
						'value' => $row['from_email'],
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Delete log entries sent to or from an address.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$rows    = Log::for_address( $email );
		$removed = Log::delete( wp_list_pluck( $rows, 'id' ) );
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < 500,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = (int) Settings::get( 'log_retention' );
		$text = '<p class="privacy-policy-tutorial">' . esc_html__( 'Nemvo Email sends the site\'s email through Google and keeps a log. Adjust this text to your settings.', 'nemvo-email' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nemvo-email' ) . ' </strong>'
			. esc_html__( 'Email from this website, such as order confirmations, password resets and replies to forms, is sent through Google Workspace (Google LLC). Google processes the messages, including your email address and their content, to deliver them.', 'nemvo-email' ) . ' '
			/* translators: %d: days */
			. esc_html( sprintf( _n( 'We keep a log of the email we send (recipient addresses, subject and delivery status) for %d day, to make sure email arrives.', 'We keep a log of the email we send (recipient addresses, subject and delivery status) for %d days, to make sure email arrives.', $days, 'nemvo-email' ), $days ) )
			. '</p>';
		wp_add_privacy_policy_content( 'Nemvo Email', wp_kses_post( $text ) );
	}
}
