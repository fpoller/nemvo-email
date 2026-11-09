<?php
/**
 * Settings screen.
 *
 * Included from Admin::render_settings(), so the variables below are local
 * to that method, not globals.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variables of Admin::render_settings().

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$provider      = Providers::current();
$state         = $provider->state();
$connected     = $provider->is_connected();
$settings      = Settings::get();
$checks        = Health::checks();
$sender_domain = Mailer::from_domain();
$dns           = '' !== $sender_domain ? Dns::stored( $sender_domain ) : null;
$stored        = get_option( Dns::OPTION );
if ( ! $dns && is_array( $stored ) ) {
	// A domain checked by hand.
	$dns = $stored;
}
$import       = Import::wp_mail_smtp();
$settings_url = admin_url( 'admin.php?page=' . Admin::SLUG_SETTINGS );
$post_url     = admin_url( 'admin-post.php' );

/**
 * A small form that posts one action.
 *
 * @param string $action  Action (without prefix).
 * @param string $label   Button label.
 * @param string $classes Button classes.
 * @param string $back    Return URL.
 * @param string $confirm Confirmation question, or ''.
 */
$button = static function ( $action, $label, $classes = 'button', $back = '', $confirm = '' ) use ( $post_url ) {
	?>
	<form method="post" action="<?php echo esc_url( $post_url ); ?>"<?php echo '' !== $confirm ? ' data-nve-confirm="' . esc_attr( $confirm ) . '"' : ''; ?>>
		<input type="hidden" name="action" value="nemvo_email_<?php echo esc_attr( $action ); ?>">
		<?php if ( '' !== $back ) : ?>
			<input type="hidden" name="_back" value="<?php echo esc_url( $back ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'nemvo_email_' . $action ); ?>
		<button type="submit" class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};

$cli = <<<'SH'
wp nemvo-email status                   # connection, sender, DNS and warnings
wp nemvo-email test you@example.com     # send a test email
wp nemvo-email dns [example.com]        # check SPF, DKIM, DMARC and MX
wp nemvo-email log --status=failed      # recent messages
wp nemvo-email import                   # import WP Mail SMTP's settings
wp nemvo-email purge --days=30          # delete log entries older than 30 days
SH;

$constants = implode(
	"\n",
	array(
		'// OAuth client from wp-config.php instead of this screen (env vars work too, with _FILE variants).',
		"define( 'NEMVO_EMAIL_GOOGLE_CLIENT_ID', '1234567890-abc" . Providers\Google::CLIENT_SUFFIX . "' );",
		"define( 'NEMVO_EMAIL_GOOGLE_CLIENT_SECRET', 'GOCSPX-…' );",
		'',
		'// Encrypt stored tokens with this key instead of the WordPress salts.',
		"define( 'NEMVO_EMAIL_KEY', 'a-long-random-string' );",
		'',
		"// Log email but don't send it (staging copies of a live site).",
		"define( 'NEMVO_EMAIL_PAUSE', true );",
	)
);
?>
<div class="wrap nve-wrap nve-settings">
	<?php Admin::header( __( 'Email settings', 'nemvo-email' ), Admin::SLUG_SETTINGS ); ?>
	<?php Admin::print_flash(); ?>

	<div class="nve-settings__layout">
		<nav class="nve-settings__nav" aria-label="<?php esc_attr_e( 'Settings sections', 'nemvo-email' ); ?>">
			<a href="#status"><?php esc_html_e( 'Status', 'nemvo-email' ); ?></a>
			<a href="#connection"><?php esc_html_e( 'Connection', 'nemvo-email' ); ?></a>
			<a href="#sender"><?php esc_html_e( 'Sender', 'nemvo-email' ); ?></a>
			<a href="#test"><?php esc_html_e( 'Test email', 'nemvo-email' ); ?></a>
			<a href="#domain"><?php esc_html_e( 'Domain', 'nemvo-email' ); ?></a>
			<a href="#sending"><?php esc_html_e( 'Sending & log', 'nemvo-email' ); ?></a>
			<a href="#import"><?php esc_html_e( 'Import', 'nemvo-email' ); ?></a>
			<a href="#developers"><?php esc_html_e( 'Developers', 'nemvo-email' ); ?></a>
		</nav>

		<div class="nve-settings__main">
			<section id="status" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Status', 'nemvo-email' ); ?></h2>
				<p class="nve-lead"><?php esc_html_e( 'The same checks appear under Tools › Site Health.', 'nemvo-email' ); ?></p>
				<ul class="nve-checklist">
					<?php foreach ( $checks as $check ) : ?>
						<li class="is-<?php echo esc_attr( $check['state'] ); ?>">
							<?php Icons::echo_badge( $check['state'] ); ?>
							<div>
								<strong><?php echo esc_html( $check['title'] ); ?></strong>
								<p><?php echo esc_html( $check['text'] ); ?></p>
							</div>
							<a href="<?php echo esc_url( $check['url'] ); ?>"><?php echo esc_html( $check['link'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="nve-actions"><a class="button" href="<?php echo esc_url( Admin::setup_url( Settings::get( 'setup_done' ) ? 'done' : '' ) ); ?>"><?php esc_html_e( 'Open the setup guide', 'nemvo-email' ); ?></a></p>
			</section>

			<section id="connection" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Connection', 'nemvo-email' ); ?></h2>
				<div class="nve-state is-<?php echo esc_attr( $state['state'] ); ?>">
					<?php
					switch ( $state['state'] ) {
						case 'connected':
							Icons::echo_badge( 'good', __( 'Connected', 'nemvo-email' ) );
							/* translators: 1: provider, 2: account */
							echo ' <span>' . wp_kses( sprintf( __( 'Sending through %1$s as %2$s.', 'nemvo-email' ), esc_html( $provider->name() ), '<strong>' . esc_html( $provider->account() ) . '</strong>' ), Admin::inline_html() ) . '</span>';
							break;
						case 'error':
							Icons::echo_badge( 'critical', __( 'Not working', 'nemvo-email' ) );
							echo ' <span>' . esc_html( $state['message'] ) . '</span>';
							break;
						default:
							Icons::echo_badge( 'neutral', __( 'Not connected', 'nemvo-email' ) );
							echo ' <span>' . esc_html__( 'WordPress sends email from the web server.', 'nemvo-email' ) . '</span>';
					}
					?>
				</div>

				<?php require NEMVO_EMAIL_DIR . 'templates/providers/' . sanitize_key( $provider->id() ) . '.php'; ?>

				<div class="nve-actions">
					<?php
					if ( $connected ) {
						$button( 'check', __( 'Check connection', 'nemvo-email' ), 'button', $settings_url . '#connection' );
					}
					if ( $provider->is_configured() ) {
						$button( 'connect', $connected ? __( 'Connect again', 'nemvo-email' ) : __( 'Connect', 'nemvo-email' ), 'error' === $state['state'] || ! $connected ? 'button button-primary' : 'button', $settings_url . '#connection' );
					}
					?>
					<a class="button" href="<?php echo esc_url( Admin::setup_url( 'client' ) ); ?>"><?php esc_html_e( 'Change OAuth client', 'nemvo-email' ); ?></a>
					<?php
					if ( $connected ) {
						$button( 'disconnect', __( 'Disconnect', 'nemvo-email' ), 'button nve-button-danger', $settings_url . '#connection', __( 'Disconnect the account? WordPress will send email from the web server until you connect again.', 'nemvo-email' ) );
					}
					?>
				</div>
			</section>

			<section id="sender" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Sender', 'nemvo-email' ); ?></h2>
				<?php if ( $connected ) : ?>
					<?php
					$back = $settings_url . '#sender';
					require NEMVO_EMAIL_DIR . 'templates/partials/sender.php';
					?>
				<?php else : ?>
					<p class="nve-muted"><?php esc_html_e( 'Connect an account first; the sender defaults to it.', 'nemvo-email' ); ?></p>
				<?php endif; ?>
			</section>

			<section id="test" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Test email', 'nemvo-email' ); ?></h2>
				<p class="nve-lead"><?php esc_html_e( 'Sends a short message through wp_mail(), like any other WordPress email, and shows the result.', 'nemvo-email' ); ?></p>
				<?php
				$next = '';
				require NEMVO_EMAIL_DIR . 'templates/partials/test.php';
				?>
			</section>

			<section id="domain" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Domain', 'nemvo-email' ); ?></h2>
				<p class="nve-lead"><?php esc_html_e( 'SPF, DKIM and DMARC let receivers tell your email from forgeries. The sender domain is checked every day; check another domain, or a custom DKIM selector, here.', 'nemvo-email' ); ?></p>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="nve-form">
					<input type="hidden" name="action" value="nemvo_email_dns">
					<input type="hidden" name="_back" value="<?php echo esc_url( $settings_url . '#domain' ); ?>">
					<?php wp_nonce_field( 'nemvo_email_dns' ); ?>
					<div class="nve-fields nve-fields--inline">
						<label>
							<span><?php esc_html_e( 'Domain', 'nemvo-email' ); ?></span>
							<input type="text" name="domain" value="<?php echo esc_attr( $dns ? $dns['domain'] : $sender_domain ); ?>" placeholder="example.com" spellcheck="false">
						</label>
						<label>
							<span><?php esc_html_e( 'DKIM selector', 'nemvo-email' ); ?></span>
							<input type="text" name="dkim_selector" value="<?php echo esc_attr( $settings['dkim_selector'] ); ?>" placeholder="<?php echo esc_attr( $provider->dns_profile()['dkim_selector'] ); ?>" class="small-text nve-input-selector" spellcheck="false">
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Check', 'nemvo-email' ); ?></button>
					</div>
				</form>
				<?php
				if ( $dns ) {
					require NEMVO_EMAIL_DIR . 'templates/partials/dns.php';
				}
				?>
			</section>

			<section id="sending" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Sending & log', 'nemvo-email' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="nve-form">
					<input type="hidden" name="action" value="nemvo_email_general">
					<input type="hidden" name="_back" value="<?php echo esc_url( $settings_url . '#sending' ); ?>">
					<?php wp_nonce_field( 'nemvo_email_general' ); ?>
					<div class="nve-checks">
						<label class="nve-check">
							<input type="checkbox" name="nve[pause]" value="1" <?php checked( $settings['pause'] ); ?><?php disabled( Settings::pause_locked() ); ?>>
							<span>
								<?php esc_html_e( 'Pause sending', 'nemvo-email' ); ?>
								<small>
									<?php
									echo Settings::pause_locked()
										? esc_html__( 'Set by NEMVO_EMAIL_PAUSE.', 'nemvo-email' )
										: esc_html__( 'Messages are logged but not sent, and WordPress is told they were sent. For staging copies of a live site, or while you test.', 'nemvo-email' );
									?>
								</small>
							</span>
						</label>
						<label class="nve-check">
							<input type="checkbox" name="nve[log_content]" value="1" <?php checked( $settings['log_content'] ); ?>>
							<span>
								<?php esc_html_e( 'Keep the content of messages in the log', 'nemvo-email' ); ?>
								<small><?php esc_html_e( 'Lets you read and resend messages. Messages can contain password reset links and personal data, so this is off by default. Turning it off deletes kept content.', 'nemvo-email' ); ?></small>
							</span>
						</label>
					</div>
					<div class="nve-fields nve-fields--inline">
						<label>
							<span><?php esc_html_e( 'Keep log entries for', 'nemvo-email' ); ?></span>
							<span class="nve-input-unit"><input type="number" name="nve[log_retention]" min="1" max="3650" value="<?php echo esc_attr( (string) $settings['log_retention'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'nemvo-email' ); ?></span>
						</label>
					</div>
					<p class="nve-muted"><?php esc_html_e( 'The statistics are counted from the log, so they reach back as far as it does. Recipients\' addresses are personal data; Tools › Export and Erase Personal Data include the log.', 'nemvo-email' ); ?></p>
					<p class="nve-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'nemvo-email' ); ?></button></p>
				</form>
				<h3><?php esc_html_e( 'Empty the log', 'nemvo-email' ); ?></h3>
				<div class="nve-actions">
					<?php $button( 'clear_log', __( 'Delete all log entries', 'nemvo-email' ), 'button nve-button-danger', $settings_url . '#sending', __( 'Delete every log entry? The statistics start again from zero.', 'nemvo-email' ) ); ?>
				</div>
			</section>

			<section id="import" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Import from WP Mail SMTP', 'nemvo-email' ); ?></h2>
				<?php if ( $import ) : ?>
					<dl class="nve-facts">
						<div>
							<dt><?php esc_html_e( 'Mailer', 'nemvo-email' ); ?></dt>
							<dd><code><?php echo esc_html( $import['mailer'] ); ?></code><?php echo $import['active'] ? '' : ' · ' . esc_html__( 'plugin inactive', 'nemvo-email' ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'From', 'nemvo-email' ); ?></dt>
							<dd><?php echo esc_html( trim( $import['from_name'] . ' <' . $import['from_email'] . '>' ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Gmail', 'nemvo-email' ); ?></dt>
							<dd>
								<?php
								if ( $import['gmail']['connected'] ) {
									/* translators: %s: account */
									echo esc_html( sprintf( __( 'connected as %s', 'nemvo-email' ), $import['gmail']['email'] ) );
								} elseif ( $import['gmail']['one_click'] ) {
									esc_html_e( 'One-Click Setup (can\'t be imported)', 'nemvo-email' );
								} elseif ( $import['gmail']['client'] ) {
									esc_html_e( 'OAuth client, not connected', 'nemvo-email' );
								} else {
									echo '–';
								}
								?>
							</dd>
						</div>
					</dl>
					<p><?php esc_html_e( 'Imports the sender, "Do not send", and the Gmail mailer\'s OAuth client and connection. WP Mail SMTP\'s own settings stay as they are.', 'nemvo-email' ); ?></p>
					<div class="nve-actions">
						<?php $button( 'import', __( 'Import', 'nemvo-email' ), 'button', $settings_url . '#import', $connected ? __( 'Importing replaces the current connection and sender settings. Continue?', 'nemvo-email' ) : '' ); ?>
						<?php
						if ( '' !== $import['active'] && $connected ) {
							$button( 'deactivate_other', __( 'Deactivate WP Mail SMTP', 'nemvo-email' ), 'button', $settings_url . '#import' );
						}
						?>
					</div>
				<?php else : ?>
					<p class="nve-muted"><?php esc_html_e( 'WP Mail SMTP isn\'t installed here, or has no settings worth importing.', 'nemvo-email' ); ?></p>
				<?php endif; ?>
			</section>

			<section id="developers" class="nve-card nve-section">
				<h2><?php esc_html_e( 'Developers', 'nemvo-email' ); ?></h2>
				<h3><?php esc_html_e( 'wp-config.php', 'nemvo-email' ); ?></h3>
				<div class="nve-code"><button type="button" class="nve-copy nve-code__copy" data-nve-copy="<?php echo esc_attr( $constants ); ?>"><?php esc_html_e( 'Copy', 'nemvo-email' ); ?></button><pre><code><?php echo esc_html( $constants ); ?></code></pre></div>
				<h3><?php esc_html_e( 'WP-CLI', 'nemvo-email' ); ?></h3>
				<div class="nve-code"><button type="button" class="nve-copy nve-code__copy" data-nve-copy="<?php echo esc_attr( $cli ); ?>"><?php esc_html_e( 'Copy', 'nemvo-email' ); ?></button><pre><code><?php echo esc_html( $cli ); ?></code></pre></div>
				<h3><?php esc_html_e( 'Hooks', 'nemvo-email' ); ?></h3>
				<table class="nve-table nve-hooks">
					<tbody>
						<tr><td><code>nemvo_email_sent</code>, <code>nemvo_email_failed</code>, <code>nemvo_email_paused</code></td><td><?php esc_html_e( 'Actions after each message, with the PHPMailer object, the result (message id or error) and the provider.', 'nemvo-email' ); ?></td></tr>
						<tr><td><code>nemvo_email_log_entry</code></td><td><?php esc_html_e( 'Filter a log entry before it is written; return false to leave a message out of the log.', 'nemvo-email' ); ?></td></tr>
						<tr><td><code>nemvo_email_capability</code></td><td><?php esc_html_e( 'Capability for these screens (default manage_options).', 'nemvo-email' ); ?></td></tr>
					</tbody>
				</table>
				<p class="nve-muted"><?php esc_html_e( 'Every wp_mail() filter keeps working: wp_mail, wp_mail_from, wp_mail_content_type, phpmailer_init, wp_mail_succeeded and wp_mail_failed.', 'nemvo-email' ); ?></p>
			</section>
		</div>
	</div>
</div>
