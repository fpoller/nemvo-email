<?php
/**
 * Admin screens and form handlers.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Menu, assets, notices and the admin-post actions of the setup guide and
 * settings.
 */
class Admin {

	const SLUG          = 'nemvo-email';
	const SLUG_LOG      = 'nemvo-email-log';
	const SLUG_SETTINGS = 'nemvo-email-settings';
	const SLUG_SETUP    = 'nemvo-email-setup';

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var string[]
	 */
	private static $hooks = array();

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_setup' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEMVO_EMAIL_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'oauth', 'connect', 'credentials', 'disconnect', 'check', 'sender', 'general', 'dns', 'import', 'deactivate_other', 'finish', 'clear_log' ) as $action ) {
			add_action( 'admin_post_nemvo_email_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/*
	 * Menu and screens
	 * ------------------------------------------------------------------
	 */

	/**
	 * Admin menu. While setup isn't finished, "Set up" comes first, so the
	 * Email menu opens the guide.
	 */
	public static function menu() {
		$cap    = Plugin::cap();
		$failed = current_user_can( $cap ) ? Log::failed_recently() : 0;
		$bubble = $failed ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>', $failed, esc_html( sprintf( /* translators: %d: number of messages */ _n( '%d failed email in the last 24 hours', '%d failed emails in the last 24 hours', $failed, 'nemvo-email' ), $failed ) ) ) : '';

		self::$hooks[] = add_menu_page( __( 'Email', 'nemvo-email' ), __( 'Email', 'nemvo-email' ) . $bubble, $cap, self::SLUG, array( __CLASS__, 'render_overview' ), 'dashicons-email-alt', 81 );
		// Overview first: a first submenu with another slug makes WordPress add a duplicate "Email" link.
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Email overview', 'nemvo-email' ), __( 'Overview', 'nemvo-email' ), $cap, self::SLUG, array( __CLASS__, 'render_overview' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Set up email', 'nemvo-email' ), __( 'Set up', 'nemvo-email' ), $cap, self::SLUG_SETUP, array( __CLASS__, 'render_setup' ), Settings::get( 'setup_done' ) ? null : 0 );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Email log', 'nemvo-email' ), __( 'Log', 'nemvo-email' ) . $bubble, $cap, self::SLUG_LOG, array( __CLASS__, 'render_log' ) );
		self::$hooks[] = add_submenu_page( self::SLUG, __( 'Email settings', 'nemvo-email' ), __( 'Settings', 'nemvo-email' ), $cap, self::SLUG_SETTINGS, array( __CLASS__, 'render_settings' ) );
	}

	/**
	 * Once setup is finished, take the guide out of the menu. This runs after
	 * WordPress has routed the request and checked access, so the guide stays
	 * reachable from Settings (removing it in admin_menu would make it 403).
	 */
	public static function hide_setup() {
		if ( Settings::get( 'setup_done' ) ) {
			remove_submenu_page( self::SLUG, self::SLUG_SETUP );
		}
	}

	/**
	 * Highlight Settings while the guide is open after setup.
	 *
	 * @param string|null $file Submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $file ) {
		return Settings::get( 'setup_done' ) && self::SLUG_SETUP === self::page() ? self::SLUG_SETTINGS : $file;
	}

	/**
	 * Links on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$slug = Settings::get( 'setup_done' ) ? self::SLUG_SETTINGS : self::SLUG_SETUP;
		$text = Settings::get( 'setup_done' ) ? __( 'Settings', 'nemvo-email' ) : __( 'Set up', 'nemvo-email' );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . $slug ) ), esc_html( $text ) ) );
		return $links;
	}

	/**
	 * Mark our screens for the stylesheet.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, self::$hooks, true ) ) {
			$classes .= ' nve-screen';
		}
		return $classes;
	}

	/**
	 * Current page slug.
	 *
	 * @return string
	 */
	private static function page() {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen to show.
	}

	/**
	 * Enqueue assets on our screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		// These screens load nothing from other sites; WordPress's emoji script would.
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );

		$url  = NEMVO_EMAIL_URL . 'assets/';
		$ver  = NEMVO_EMAIL_VERSION;
		$page = self::page();
		wp_enqueue_style( 'nemvo-email-admin', $url . 'css/admin.css', array(), $ver );
		wp_enqueue_script( 'nemvo-email-admin', $url . 'js/admin.js', array( 'wp-i18n' ), $ver, true );
		wp_set_script_translations( 'nemvo-email-admin', 'nemvo-email', NEMVO_EMAIL_DIR . 'languages' );
		wp_localize_script(
			'nemvo-email-admin',
			'nveAdmin',
			array(
				'root'   => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'logUrl' => admin_url( 'admin.php?page=' . self::SLUG_LOG ),
			)
		);

		if ( in_array( $page, array( self::SLUG, self::SLUG_LOG ), true ) ) {
			$provider = Providers::current();
			wp_enqueue_script( 'nemvo-email-chart', $url . 'vendor/chart/chart.umd.min.js', array(), '4.5.1', true );
			wp_enqueue_script( 'nemvo-email-app', $url . 'js/app.js', array( 'nemvo-email-chart', 'nemvo-email-admin', 'wp-i18n' ), $ver, true );
			wp_set_script_translations( 'nemvo-email-app', 'nemvo-email', NEMVO_EMAIL_DIR . 'languages' );
			wp_localize_script(
				'nemvo-email-app',
				'nveApp',
				array(
					'view'        => self::SLUG_LOG === $page ? 'log' : 'overview',
					'overviewUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
					'logUrl'      => admin_url( 'admin.php?page=' . self::SLUG_LOG ),
					'settingsUrl' => admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ),
					'setupUrl'    => admin_url( 'admin.php?page=' . self::SLUG_SETUP ),
					'today'       => current_datetime()->format( 'Y-m-d' ),
					'locale'      => str_replace( '_', '-', get_user_locale() ),
					'startOfWeek' => (int) get_option( 'start_of_week', 1 ),
					'retention'   => (int) Settings::get( 'log_retention' ),
					'keepContent' => (bool) Settings::get( 'log_content' ),
					'provider'    => $provider->name(),
				)
			);
		}
	}

	/**
	 * Overview screen.
	 */
	public static function render_overview() {
		self::render_app( 'overview' );
	}

	/**
	 * Log screen.
	 */
	public static function render_log() {
		self::render_app( 'log' );
	}

	/**
	 * The overview and log app (one script, two views).
	 *
	 * @param string $view overview | log.
	 */
	private static function render_app( $view ) {
		$title = 'log' === $view ? __( 'Email log', 'nemvo-email' ) : __( 'Email', 'nemvo-email' );
		$page  = 'log' === $view ? self::SLUG_LOG : self::SLUG;
		require NEMVO_EMAIL_DIR . 'templates/app.php';
	}

	/**
	 * Settings screen.
	 */
	public static function render_settings() {
		require NEMVO_EMAIL_DIR . 'templates/settings.php';
	}

	/**
	 * Setup guide.
	 */
	public static function render_setup() {
		require NEMVO_EMAIL_DIR . 'templates/setup.php';
	}

	/**
	 * Shared header: site name, title, navigation.
	 *
	 * @param string $title   Title.
	 * @param string $current Current page slug.
	 */
	public static function header( $title, $current ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- both are used by the template.
		require NEMVO_EMAIL_DIR . 'templates/header.php';
	}

	/*
	 * Notices
	 * ------------------------------------------------------------------
	 */

	/**
	 * Show a message on the next screen.
	 *
	 * @param string $type success | error | warning.
	 * @param string $text Text.
	 */
	public static function flash( $type, $text ) {
		set_transient(
			'nemvo_email_flash_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and forget the flashed message.
	 */
	public static function print_flash() {
		$key   = 'nemvo_email_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="nve-flash is-%1$s" role="%2$s"><p>%3$s</p></div>',
			esc_attr( $flash['type'] ),
			'error' === $flash['type'] ? 'alert' : 'status',
			wp_kses( $flash['text'], self::inline_html() )
		);
	}

	/**
	 * Tags allowed in messages and guide texts.
	 *
	 * @return array
	 */
	public static function inline_html() {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
		);
	}

	/**
	 * Notices elsewhere in wp-admin: after activation, and when email
	 * isn't getting through.
	 */
	public static function notices() {
		if ( ! current_user_can( Plugin::cap() ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, self::$hooks, true ) ) {
			return;
		}

		if ( get_transient( 'nemvo_email_activated' ) ) {
			delete_transient( 'nemvo_email_activated' );
			if ( ! Providers::current()->is_connected() ) {
				$import = Import::wp_mail_smtp();
				printf(
					'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
					esc_html__( 'Nemvo Email is active.', 'nemvo-email' ),
					$import
						? esc_html__( 'Your WP Mail SMTP settings can be imported in the first step of the setup.', 'nemvo-email' )
						: esc_html__( 'WordPress keeps sending email the way it did until you connect Google Workspace. Setup takes about ten minutes.', 'nemvo-email' ),
					esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETUP ) ),
					esc_html__( 'Start setup', 'nemvo-email' )
				);
			}
		}

		$provider = Providers::current();
		$state    = $provider->state();
		if ( 'error' === $state['state'] ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
				/* translators: %s: provider */
				esc_html( sprintf( __( 'Email from this site is not being delivered: %s refuses the connection.', 'nemvo-email' ), $provider->name() ) ),
				esc_html( $state['message'] ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '#connection' ) ),
				esc_html__( 'Fix the connection', 'nemvo-email' )
			);
		}
	}

	/*
	 * Form handlers
	 * ------------------------------------------------------------------
	 */

	/**
	 * Check capability and nonce of a form post.
	 *
	 * @param string $action Action name.
	 */
	private static function verify( $action ) {
		if ( ! current_user_can( Plugin::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change email settings.', 'nemvo-email' ), 403 );
		}
		check_admin_referer( 'nemvo_email_' . $action );
	}

	/**
	 * Back to the screen the form came from.
	 *
	 * @param string $fallback Fallback URL.
	 */
	private static function back( $fallback ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify() by every caller.
		$to = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : '';
		wp_safe_redirect( '' !== $to ? $to : $fallback );
		exit;
	}

	/**
	 * URL of a setup step.
	 *
	 * @param string $step Step.
	 * @return string
	 */
	public static function setup_url( $step = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG_SETUP . ( '' !== $step ? '&step=' . $step : '' ) );
	}

	/**
	 * Save the OAuth client.
	 */
	public static function handle_credentials() {
		self::verify( 'credentials' );
		$provider = Providers::get( 'google' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		// phpcs:enable
		$saved = $provider->save_credentials( $id, $secret );
		if ( is_wp_error( $saved ) ) {
			self::flash( 'error', $saved->get_error_message() );
			self::back( self::setup_url( 'client' ) );
		}
		self::flash( 'success', __( 'OAuth client saved. Now connect the Google account that should send the email.', 'nemvo-email' ) );
		self::back( self::setup_url( 'connect' ) );
	}

	/**
	 * Send the browser to Google's consent screen.
	 */
	public static function handle_connect() {
		self::verify( 'connect' );
		$provider = Providers::current();
		if ( ! $provider->is_configured() ) {
			self::flash( 'error', __( 'Enter the OAuth client ID and secret first.', 'nemvo-email' ) );
			self::back( self::setup_url( 'client' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$return = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( $_POST['_back'] ) ) : self::setup_url( 'sender' );
		set_transient( 'nemvo_email_return_' . get_current_user_id(), $return, HOUR_IN_SECONDS );

		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) {
				$hosts[] = 'accounts.google.com';
				return $hosts;
			}
		);
		wp_safe_redirect( $provider->connect_url() );
		exit;
	}

	/**
	 * Google sends the browser back here.
	 */
	public static function handle_oauth() {
		if ( ! current_user_can( Plugin::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change email settings.', 'nemvo-email' ), 403 );
		}
		$provider = Providers::current();
		$query    = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth state takes a nonce's place: signed, bound to the user and single-use (Crypto::sign, PKCE transient).
		foreach ( array( 'state', 'code', 'error' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		$result = $provider->handle_callback( $query );
		$key    = 'nemvo_email_return_' . get_current_user_id();
		$return = get_transient( $key );
		delete_transient( $key );
		$return = is_string( $return ) && '' !== $return ? $return : self::setup_url( 'sender' );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( false !== strpos( $return, self::SLUG_SETUP ) ? self::setup_url( 'connect' ) : $return );
			exit;
		}
		/* translators: %s: account */
		self::flash( 'success', sprintf( __( 'Connected. WordPress now sends email as %s.', 'nemvo-email' ), '<strong>' . esc_html( $provider->account() ) . '</strong>' ) );
		$domain = Mailer::from_domain();
		if ( '' !== $domain ) {
			Dns::check( $domain, $provider );
		}
		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * Disconnect the account.
	 */
	public static function handle_disconnect() {
		self::verify( 'disconnect' );
		$provider = Providers::current();
		// An imported authorization is shared with WP Mail SMTP while it is active; don't revoke it there.
		$revoke = ! ( 'wp-mail-smtp' === $provider->imported() && '' !== Import::active_plugin() );
		$provider->disconnect( $revoke );
		self::flash( 'success', __( 'Disconnected. WordPress sends email from the web server again until you connect an account.', 'nemvo-email' ) );
		self::back( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '#connection' ) );
	}

	/**
	 * Check the connection.
	 */
	public static function handle_check() {
		self::verify( 'check' );
		$result = Providers::current()->check();
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} else {
			self::flash( 'success', __( 'The connection works: Google accepts messages from this site.', 'nemvo-email' ) );
		}
		self::back( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '#connection' ) );
	}

	/**
	 * Save the sender.
	 */
	public static function handle_sender() {
		self::verify( 'sender' );
		$input = isset( $_POST['nve'] ) && is_array( $_POST['nve'] ) ? map_deep( wp_unslash( $_POST['nve'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$clean = Settings::sanitize_sender( $input );
		if ( '' !== (string) ( $input['from_email'] ?? '' ) && '' === $clean['from_email'] ) {
			self::flash( 'error', __( 'The From address isn\'t a valid email address.', 'nemvo-email' ) );
			self::back( self::setup_url( 'sender' ) );
		}
		if ( Providers::current()->account() === $clean['from_email'] ) {
			$clean['from_email'] = '';
		}
		$old = Mailer::from_domain();
		Settings::update( $clean + array( 'sender_saved' => 1 ) );
		$domain = Mailer::from_domain();
		if ( '' !== $domain && $domain !== $old ) {
			Dns::check( $domain, Providers::current() );
		}
		self::flash( 'success', __( 'Sender saved.', 'nemvo-email' ) );
		self::back( self::setup_url( 'test' ) );
	}

	/**
	 * Save the general settings.
	 */
	public static function handle_general() {
		self::verify( 'general' );
		$input = isset( $_POST['nve'] ) && is_array( $_POST['nve'] ) ? map_deep( wp_unslash( $_POST['nve'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$clean = Settings::sanitize_general( $input );
		Settings::update( $clean );
		if ( ! $clean['log_content'] ) {
			self::drop_content();
		}
		self::flash( 'success', __( 'Settings saved.', 'nemvo-email' ) );
		self::back( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS ) );
	}

	/**
	 * Forget kept message content when keeping it is turned off.
	 */
	private static function drop_content() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET body = NULL, alt_body = NULL WHERE body IS NOT NULL', Log::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own log table.
	}

	/**
	 * Check the sender domain's DNS.
	 */
	public static function handle_dns() {
		self::verify( 'dns' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		if ( isset( $_POST['dkim_selector'] ) ) {
			Settings::update( array( 'dkim_selector' => Settings::sanitize_selector( sanitize_text_field( wp_unslash( $_POST['dkim_selector'] ) ) ) ) );
		}
		$domain = isset( $_POST['domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) ) : '';
		// phpcs:enable
		$domain = '' !== $domain ? $domain : Mailer::from_domain();
		if ( ! preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
			self::flash( 'error', __( 'Enter a domain name, like example.com.', 'nemvo-email' ) );
		} else {
			Dns::check( $domain, Providers::current() );
		}
		self::back( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '#domain' ) );
	}

	/**
	 * Import WP Mail SMTP's settings.
	 */
	public static function handle_import() {
		self::verify( 'import' );
		$result = Import::run();
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			self::back( self::setup_url() );
		}
		$text = $result['imported']
			/* translators: %s: list of imported settings */
			? sprintf( __( 'Imported from WP Mail SMTP: %s.', 'nemvo-email' ), implode( ', ', $result['imported'] ) )
			: __( 'WP Mail SMTP had nothing to import.', 'nemvo-email' );
		$type = 'success';
		$next = self::setup_url( 'client' );
		if ( true === $result['connection'] ) {
			/* translators: %s: account */
			$text .= ' ' . sprintf( __( 'The Gmail connection works: WordPress now sends email as %s.', 'nemvo-email' ), '<strong>' . esc_html( Providers::current()->account() ) . '</strong>' );
			$next  = self::setup_url( 'sender' );
		} elseif ( is_wp_error( $result['connection'] ) ) {
			$type = 'warning';
			/* translators: %s: error */
			$text .= ' ' . sprintf( __( 'The imported Gmail connection doesn\'t work: %s', 'nemvo-email' ), esc_html( $result['connection']->get_error_message() ) );
			$next  = self::setup_url( 'connect' );
		} elseif ( Providers::current()->is_configured() ) {
			$next = self::setup_url( 'connect' );
		}
		self::flash( $type, $text );
		self::back( $next );
	}

	/**
	 * Deactivate WP Mail SMTP.
	 */
	public static function handle_deactivate_other() {
		self::verify( 'deactivate_other' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to deactivate plugins.', 'nemvo-email' ), 403 );
		}
		if ( Import::deactivate() ) {
			self::flash( 'success', __( 'WP Mail SMTP is deactivated. Its settings are kept, so you can switch back at any time.', 'nemvo-email' ) );
		}
		self::back( self::setup_url( 'done' ) );
	}

	/**
	 * Finish the guide.
	 */
	public static function handle_finish() {
		self::verify( 'finish' );
		Settings::update( array( 'setup_done' => 1 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Empty the log.
	 */
	public static function handle_clear_log() {
		self::verify( 'clear_log' );
		Log::clear();
		self::flash( 'success', __( 'The log is empty.', 'nemvo-email' ) );
		self::back( admin_url( 'admin.php?page=' . self::SLUG_SETTINGS . '#log' ) );
	}
}
