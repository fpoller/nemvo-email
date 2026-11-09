<?php
/**
 * The setup guide.
 *
 * Included from Admin::render_setup(), so the variables below are local to
 * that method, not globals.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variables of Admin::render_setup().

namespace NemvoEmail;

use NemvoEmail\Providers\Google;

defined( 'ABSPATH' ) || exit;

$provider      = Providers::current();
$state         = $provider->state();
$connected     = $provider->is_connected();
$sender_domain = Mailer::from_domain();
$dns           = '' !== $sender_domain ? Dns::stored( $sender_domain ) : null;
if ( $connected && '' !== $sender_domain && ! $dns ) {
	$dns = Dns::check( $sender_domain, $provider );
}
$import  = Import::wp_mail_smtp();
$locked  = $provider instanceof Google && $provider->credentials_locked();
$console = 'https://console.cloud.google.com/';

$steps = array(
	'client'  => array(
		'label' => __( 'Google Cloud', 'nemvo-email' ),
		'done'  => $provider->is_configured(),
		'note'  => __( 'An OAuth client for this site', 'nemvo-email' ),
	),
	'connect' => array(
		'label' => __( 'Connect', 'nemvo-email' ),
		'done'  => $connected && 'error' !== $state['state'],
		'note'  => $connected ? $provider->account() : __( 'The account that sends', 'nemvo-email' ),
	),
	'sender'  => array(
		'label' => __( 'Sender', 'nemvo-email' ),
		'done'  => $connected && (bool) Settings::get( 'sender_saved' ),
		'note'  => $connected ? Mailer::from_email( $provider ) : __( 'Name and address', 'nemvo-email' ),
	),
	'test'    => array(
		'label' => __( 'Test', 'nemvo-email' ),
		'done'  => $connected && (bool) Settings::get( 'test_sent' ),
		'note'  => __( 'See a message arrive', 'nemvo-email' ),
	),
	'domain'  => array(
		'label' => __( 'Domain', 'nemvo-email' ),
		'done'  => $connected && $dns && in_array( $dns['state'], array( 'good', 'neutral' ), true ),
		'note'  => '' !== $sender_domain ? 'SPF · DKIM · DMARC' : __( 'SPF, DKIM and DMARC', 'nemvo-email' ),
	),
	'done'    => array(
		'label' => __( 'Done', 'nemvo-email' ),
		'done'  => (bool) Settings::get( 'setup_done' ),
		'note'  => '',
	),
);

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which step to show.
$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
if ( ! isset( $steps[ $step ] ) ) {
	$step = 'done';
	foreach ( $steps as $key => $info ) {
		if ( ! $info['done'] && 'done' !== $key ) {
			$step = $key;
			break;
		}
	}
}

/**
 * A "Continue" link to the next step.
 *
 * @param string $to    Step.
 * @param string $label Label.
 */
$continue = static function ( $to, $label = '' ) {
	printf( '<a class="button button-primary" href="%s">%s</a>', esc_url( Admin::setup_url( $to ) ), esc_html( '' !== $label ? $label : __( 'Continue', 'nemvo-email' ) ) );
};

/**
 * A link that opens in a new tab.
 *
 * @param string $url  URL.
 * @param string $text Text.
 * @return string
 */
$ext = static function ( $url, $text ) {
	return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
};

$needs_connection = static function () use ( $continue ) {
	echo '<p class="nve-note">' . esc_html__( 'Connect a Google account first.', 'nemvo-email' ) . '</p><p class="nve-actions">';
	$continue( 'connect', __( 'Go to Connect', 'nemvo-email' ) );
	echo '</p>';
};
?>
<div class="wrap nve-wrap nve-setup">
	<?php Admin::header( __( 'Set up email', 'nemvo-email' ), Admin::SLUG_SETUP ); ?>
	<?php Admin::print_flash(); ?>

	<div class="nve-setup__layout">
		<nav aria-label="<?php esc_attr_e( 'Setup steps', 'nemvo-email' ); ?>">
			<ol class="nve-stepper">
				<?php
				$number = 0;
				foreach ( $steps as $key => $info ) :
					++$number;
					$classes = array();
					if ( $info['done'] ) {
						$classes[] = 'is-done';
					}
					if ( $key === $step ) {
						$classes[] = 'is-current';
					}
					?>
					<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<a href="<?php echo esc_url( Admin::setup_url( $key ) ); ?>"<?php echo $key === $step ? ' aria-current="step"' : ''; ?>>
							<span class="nve-stepper__dot" aria-hidden="true"><?php echo $info['done'] ? '' : esc_html( (string) $number ); ?></span>
							<span class="nve-stepper__text">
								<span class="nve-stepper__label">
									<?php echo esc_html( $info['label'] ); ?>
									<?php if ( $info['done'] ) : ?>
										<span class="screen-reader-text"><?php esc_html_e( '(done)', 'nemvo-email' ); ?></span>
									<?php endif; ?>
								</span>
								<?php if ( '' !== $info['note'] ) : ?>
									<span class="nve-stepper__note"><?php echo esc_html( $info['note'] ); ?></span>
								<?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
		</nav>

		<section class="nve-card nve-step" aria-labelledby="nve-step-title">
			<?php if ( $import && ! $connected && in_array( $step, array( 'client', 'connect' ), true ) && 'wp-mail-smtp' !== Settings::get( 'imported_from' ) ) : ?>
				<div class="nve-callout">
					<h2><?php esc_html_e( 'WP Mail SMTP is set up on this site', 'nemvo-email' ); ?></h2>
					<p>
						<?php
						if ( $import['gmail']['connected'] ) {
							/* translators: %s: Gmail account */
							echo esc_html( sprintf( __( 'It sends through Gmail as %s. Import its settings and that connection, and you can skip the Google Cloud steps.', 'nemvo-email' ), $import['gmail']['email'] ) );
						} elseif ( $import['gmail']['one_click'] ) {
							esc_html_e( 'It uses WP Mail SMTP\'s own Google app ("One-Click Setup"), which other plugins can\'t use, so you\'ll create an OAuth client below. Its sender settings can be imported.', 'nemvo-email' );
						} elseif ( $import['gmail']['client'] ) {
							esc_html_e( 'It has a Google OAuth client but no Gmail connection. Import the client and its sender settings, then connect.', 'nemvo-email' );
						} else {
							esc_html_e( 'Its sender settings can be imported. Gmail itself still needs to be connected below.', 'nemvo-email' );
						}
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nemvo_email_import">
						<?php wp_nonce_field( 'nemvo_email_import' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import from WP Mail SMTP', 'nemvo-email' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
			<?php if ( 'client' === $step ) : ?>

				<h2 id="nve-step-title"><?php esc_html_e( 'Create an OAuth client in Google Cloud', 'nemvo-email' ); ?></h2>
				<p class="nve-lead"><?php esc_html_e( 'WordPress sends through your Google account with the Gmail API. For that, the site needs its own OAuth client in Google Cloud. It\'s free, takes about ten minutes, and is done once. Sign in to Google Cloud with your Google Workspace administrator account.', 'nemvo-email' ); ?></p>

				<ol class="nve-guide">
					<li>
						<strong><?php esc_html_e( 'Create a project.', 'nemvo-email' ); ?></strong>
						<?php
						/* translators: %s: link */
						echo wp_kses( sprintf( __( 'Open %s, name it, for example "Website email", and click Create. Check that the new project is selected at the top of the page.', 'nemvo-email' ), $ext( $console . 'projectcreate', 'console.cloud.google.com/projectcreate' ) ), Admin::inline_html() );
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Enable the Gmail API.', 'nemvo-email' ); ?></strong>
						<?php
						/* translators: %s: link */
						echo wp_kses( sprintf( __( 'Open the %s and click Enable.', 'nemvo-email' ), $ext( $console . 'apis/library/gmail.googleapis.com', __( 'Gmail API', 'nemvo-email' ) ) ), Admin::inline_html() );
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Set up the consent screen.', 'nemvo-email' ); ?></strong>
						<?php
						/* translators: %s: link */
						echo wp_kses( sprintf( __( 'Open %s and click Get started. Enter an app name (your organisation\'s name) and a support email, then choose the audience:', 'nemvo-email' ), $ext( $console . 'auth/overview', __( 'Google Auth Platform', 'nemvo-email' ) ) ), Admin::inline_html() );
						?>
						<ul>
							<li><?php echo wp_kses( __( '<strong>Internal</strong> if the sending account is in your Google Workspace. Recommended: no review by Google, and the connection doesn\'t expire.', 'nemvo-email' ), Admin::inline_html() ); ?></li>
							<li><?php echo wp_kses( __( '<strong>External</strong> for a personal Gmail account. Afterwards, open <em>Audience</em> and click <strong>Publish app</strong>; in "Testing" the connection stops working after 7 days. When you connect, Google warns that the app isn\'t verified: click <em>Advanced</em> and continue to your app.', 'nemvo-email' ), Admin::inline_html() ); ?></li>
						</ul>
						<?php esc_html_e( 'Enter a contact email, agree, and click Create. There\'s no need to add scopes; the plugin asks for them when you connect.', 'nemvo-email' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Create the OAuth client.', 'nemvo-email' ); ?></strong>
						<?php
						/* translators: %s: link */
						echo wp_kses( sprintf( __( 'Open %s, click Create client, choose the application type "Web application" and name it "WordPress". Under "Authorized redirect URIs", click Add URI and paste exactly this address:', 'nemvo-email' ), $ext( $console . 'auth/clients', __( 'Clients', 'nemvo-email' ) ) ), Admin::inline_html() );
						require NEMVO_EMAIL_DIR . 'templates/partials/redirect-uri.php';
						esc_html_e( 'Leave "Authorized JavaScript origins" empty and click Create.', 'nemvo-email' );
						?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Copy the client ID and secret', 'nemvo-email' ); ?></strong>
						<?php esc_html_e( 'that Google shows into the fields below. (Closed the dialog? Open the client in the list; you can add a new secret there.)', 'nemvo-email' ); ?>
					</li>
				</ol>

				<?php if ( $locked ) : ?>
					<p class="nve-note"><?php esc_html_e( 'The OAuth client is set with NEMVO_EMAIL_GOOGLE_CLIENT_ID and NEMVO_EMAIL_GOOGLE_CLIENT_SECRET (in wp-config.php or the environment).', 'nemvo-email' ); ?></p>
					<p class="nve-actions"><?php $continue( 'connect' ); ?></p>
				<?php else : ?>
					<?php $data = $provider->data(); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nve-form">
						<input type="hidden" name="action" value="nemvo_email_credentials">
						<?php wp_nonce_field( 'nemvo_email_credentials' ); ?>
						<div class="nve-fields">
							<label>
								<span><?php esc_html_e( 'Client ID', 'nemvo-email' ); ?></span>
								<input type="text" name="client_id" class="code" value="<?php echo esc_attr( $data['client_id'] ); ?>" placeholder="<?php echo esc_attr( '1234567890-abc123def456' . Google::CLIENT_SUFFIX ); ?>" autocomplete="off" spellcheck="false" required>
							</label>
							<label>
								<span><?php esc_html_e( 'Client secret', 'nemvo-email' ); ?></span>
								<input type="password" name="client_secret" class="code" value="" placeholder="<?php echo '' !== $data['client_secret'] ? esc_attr__( 'Saved; leave empty to keep it', 'nemvo-email' ) : 'GOCSPX-…'; ?>" autocomplete="new-password" spellcheck="false"<?php echo '' === $data['client_secret'] ? ' required' : ''; ?>>
								<small><?php esc_html_e( 'Stored encrypted. A different client ID disconnects the current account.', 'nemvo-email' ); ?></small>
							</label>
						</div>
						<p class="nve-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save and continue', 'nemvo-email' ); ?></button></p>
					</form>
				<?php endif; ?>

			<?php elseif ( 'connect' === $step ) : ?>

				<h2 id="nve-step-title"><?php esc_html_e( 'Connect the account that sends', 'nemvo-email' ); ?></h2>
				<?php if ( ! $provider->is_configured() ) : ?>
					<p class="nve-note"><?php esc_html_e( 'Enter the OAuth client ID and secret first.', 'nemvo-email' ); ?></p>
					<p class="nve-actions"><?php $continue( 'client', __( 'Go to Google Cloud', 'nemvo-email' ) ); ?></p>
				<?php else : ?>
					<?php if ( 'error' === $state['state'] ) : ?>
						<p class="nve-flash is-error"><?php echo esc_html( $state['message'] ); ?></p>
					<?php elseif ( $connected ) : ?>
						<p class="nve-done">
							<?php Icons::echo_badge( 'good' ); ?>
							<span>
								<?php
								/* translators: %s: account */
								echo wp_kses( sprintf( __( 'Connected as %s.', 'nemvo-email' ), '<strong>' . esc_html( $provider->account() ) . '</strong>' ), Admin::inline_html() );
								?>
							</span>
						</p>
					<?php endif; ?>
					<p class="nve-lead"><?php esc_html_e( 'Sign in with the Google account WordPress should send as: a mailbox such as info@ or website@ your domain works well. Google then asks to let this site send email on the account\'s behalf. The plugin can only send; it can\'t read or delete email.', 'nemvo-email' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nve-actions">
						<input type="hidden" name="action" value="nemvo_email_connect">
						<input type="hidden" name="_back" value="<?php echo esc_url( Admin::setup_url( 'sender' ) ); ?>">
						<?php wp_nonce_field( 'nemvo_email_connect' ); ?>
						<button type="submit" class="button <?php echo $connected && 'error' !== $state['state'] ? '' : 'button-primary'; ?> nve-google">
							<?php echo $connected ? esc_html__( 'Connect a different account', 'nemvo-email' ) : esc_html__( 'Sign in with Google', 'nemvo-email' ); ?>
						</button>
						<?php
						if ( $connected && 'error' !== $state['state'] ) {
							$continue( 'sender' );
						}
						?>
					</form>
					<details class="nve-details">
						<summary><?php esc_html_e( 'If Google shows an error', 'nemvo-email' ); ?></summary>
						<dl class="nve-faq">
							<dt>redirect_uri_mismatch</dt>
							<dd>
								<?php esc_html_e( 'The redirect URI in the OAuth client doesn\'t match this one exactly (check http/https, www, and that nothing was added at the end):', 'nemvo-email' ); ?>
								<?php require NEMVO_EMAIL_DIR . 'templates/partials/redirect-uri.php'; ?>
							</dd>
							<dt><?php esc_html_e( 'Access blocked / org_internal', 'nemvo-email' ); ?></dt>
							<dd><?php esc_html_e( 'The consent screen is Internal, but you signed in with an account outside that Google Workspace. Use an account of the organisation, or change the audience to External.', 'nemvo-email' ); ?></dd>
							<dt><?php esc_html_e( '"Google hasn\'t verified this app"', 'nemvo-email' ); ?></dt>
							<dd><?php esc_html_e( 'Expected for External apps. Click Advanced, then "Go to (app name)". It\'s your own app.', 'nemvo-email' ); ?></dd>
							<dt>admin_policy_enforced</dt>
							<dd><?php esc_html_e( 'Your Workspace administrator restricts third-party apps. In the Google Admin console, open Security › Access and data control › API controls › Manage third-party app access, and mark your OAuth client as Trusted.', 'nemvo-email' ); ?></dd>
							<dt>invalid_client</dt>
							<dd><?php esc_html_e( 'The client ID or secret is wrong, or the client was deleted. Enter them again in the Google Cloud step.', 'nemvo-email' ); ?></dd>
						</dl>
					</details>
				<?php endif; ?>

			<?php elseif ( 'sender' === $step ) : ?>

				<h2 id="nve-step-title"><?php esc_html_e( 'Who the email is from', 'nemvo-email' ); ?></h2>
				<?php if ( ! $connected ) : ?>
					<?php $needs_connection(); ?>
				<?php else : ?>
					<p class="nve-lead"><?php esc_html_e( 'The sender recipients see. The defaults suit most sites: the connected account and the site title.', 'nemvo-email' ); ?></p>
					<?php
					$back         = Admin::setup_url( 'test' );
					$submit_label = __( 'Save and continue', 'nemvo-email' );
					require NEMVO_EMAIL_DIR . 'templates/partials/sender.php';
					?>
				<?php endif; ?>

			<?php elseif ( 'test' === $step ) : ?>

				<h2 id="nve-step-title"><?php esc_html_e( 'Send a test email', 'nemvo-email' ); ?></h2>
				<?php if ( ! $connected ) : ?>
					<?php $needs_connection(); ?>
				<?php else : ?>
					<p class="nve-lead"><?php esc_html_e( 'A short message through the same path as every WordPress email. Check that it arrives in the inbox, not the spam folder. Sending to an address outside your organisation shows best how others receive it.', 'nemvo-email' ); ?></p>
					<?php
					$next = Admin::setup_url( 'domain' );
					require NEMVO_EMAIL_DIR . 'templates/partials/test.php';
					?>
					<p class="nve-actions"><?php $continue( 'domain', Settings::get( 'test_sent' ) ? __( 'Continue', 'nemvo-email' ) : __( 'Skip', 'nemvo-email' ) ); ?></p>
				<?php endif; ?>

			<?php elseif ( 'domain' === $step ) : ?>

				<h2 id="nve-step-title">
					<?php
					echo esc_html(
						'' !== $sender_domain
							/* translators: %s: domain */
							? sprintf( __( 'Check the DNS of %s', 'nemvo-email' ), $sender_domain )
							: __( 'Check the sender domain', 'nemvo-email' )
					);
					?>
				</h2>
				<?php if ( ! $connected ) : ?>
					<?php $needs_connection(); ?>
				<?php else : ?>
					<p class="nve-lead"><?php esc_html_e( 'Receivers check three DNS records to tell your email from forgeries: SPF (which servers may send), DKIM (a signature) and DMARC (what to do with failures). Without them, email lands in spam or is rejected.', 'nemvo-email' ); ?></p>
					<?php require NEMVO_EMAIL_DIR . 'templates/partials/dns.php'; ?>
					<div class="nve-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="nemvo_email_dns">
							<input type="hidden" name="_back" value="<?php echo esc_url( Admin::setup_url( 'domain' ) ); ?>">
							<?php wp_nonce_field( 'nemvo_email_dns' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Check again', 'nemvo-email' ); ?></button>
						</form>
						<?php $continue( 'done', $steps['domain']['done'] ? __( 'Continue', 'nemvo-email' ) : __( 'Continue, fix it later', 'nemvo-email' ) ); ?>
					</div>
				<?php endif; ?>

			<?php else : ?>

				<h2 id="nve-step-title"><?php echo $connected ? esc_html__( 'Email is set up', 'nemvo-email' ) : esc_html__( 'Almost there', 'nemvo-email' ); ?></h2>
				<ul class="nve-checklist">
					<?php
					foreach ( array_slice( $steps, 0, 5, true ) as $key => $info ) :
						$state_key = $info['done'] ? 'good' : ( 'domain' === $key && $dns ? $dns['state'] : 'warning' );
						?>
						<li class="is-<?php echo esc_attr( $state_key ); ?>">
							<?php Icons::echo_badge( $state_key ); ?>
							<div>
								<strong><?php echo esc_html( $info['label'] ); ?></strong>
								<p><?php echo esc_html( $info['note'] ); ?></p>
							</div>
							<a href="<?php echo esc_url( Admin::setup_url( $key ) ); ?>"><?php echo $info['done'] ? esc_html__( 'Change', 'nemvo-email' ) : esc_html__( 'Open', 'nemvo-email' ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>

				<?php if ( '' !== Import::active_plugin() && $connected ) : ?>
					<div class="nve-callout">
						<h2><?php esc_html_e( 'WP Mail SMTP is still active', 'nemvo-email' ); ?></h2>
						<p><?php esc_html_e( 'Nemvo Email sends every message now, so WP Mail SMTP has nothing left to do. Deactivate it so only one plugin handles email; its settings are kept.', 'nemvo-email' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="nemvo_email_deactivate_other">
							<?php wp_nonce_field( 'nemvo_email_deactivate_other' ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Deactivate WP Mail SMTP', 'nemvo-email' ); ?></button>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( $connected ) : ?>
					<p class="nve-lead"><?php esc_html_e( 'Every email WordPress sends now goes through Google and appears in the log, with statistics on the overview. The domain check runs again every day, and Site Health warns you if something stops working.', 'nemvo-email' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nve-actions">
						<input type="hidden" name="action" value="nemvo_email_finish">
						<?php wp_nonce_field( 'nemvo_email_finish' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Finish', 'nemvo-email' ); ?></button>
					</form>
				<?php else : ?>
					<p class="nve-actions"><?php $continue( $provider->is_configured() ? 'connect' : 'client', __( 'Continue setup', 'nemvo-email' ) ); ?></p>
				<?php endif; ?>

			<?php endif; ?>
		</section>
	</div>
</div>
