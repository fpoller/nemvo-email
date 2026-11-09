<?php
/**
 * Plugin Name:       Nemvo Email
 * Plugin URI:        https://github.com/fpoller/nemvo-email
 * Description:       Sends all WordPress email through Google Workspace or Gmail with OAuth, instead of the web server. Guided setup, a delivery log with statistics, SPF, DKIM and DMARC checks, and import from WP Mail SMTP.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nemvo
 * Author URI:        https://nemvo.eu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       nemvo-email
 * Domain Path:       /languages
 *
 * @package NemvoEmail
 */

defined( 'ABSPATH' ) || exit;

define( 'NEMVO_EMAIL_VERSION', '1.0.0' );
define( 'NEMVO_EMAIL_FILE', __FILE__ );
define( 'NEMVO_EMAIL_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEMVO_EMAIL_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: NemvoEmail\Rest => includes/class-rest.php,
 * NemvoEmail\Providers\Google => includes/providers/class-google.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'NemvoEmail\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
		$path  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = NEMVO_EMAIL_DIR . 'includes/' . $path . 'class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'NemvoEmail\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NemvoEmail\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'NemvoEmail\\Plugin', 'instance' ) );
