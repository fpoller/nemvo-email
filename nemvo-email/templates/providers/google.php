<?php
/**
 * Google-specific connection details on the settings screen.
 *
 * Expects $provider (Providers\Google) in the including scope.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of the including template.

namespace NemvoEmail;

use NemvoEmail\Providers\Google;

defined( 'ABSPATH' ) || exit;

if ( ! $provider instanceof Google ) {
	return;
}
$data   = $provider->data();
$scopes = preg_split( '/\s+/', trim( (string) $data['scope'] ) );
$full   = in_array( Google::SCOPE_FULL, $scopes, true );
?>
<dl class="nve-facts">
	<?php if ( $provider->is_connected() ) : ?>
		<div>
			<dt><?php esc_html_e( 'Account type', 'nemvo-email' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					$provider->is_personal()
						? __( 'Personal Gmail', 'nemvo-email' )
						/* translators: %s: Workspace domain */
						: ( '' !== $data['hd'] ? sprintf( __( 'Google Workspace (%s)', 'nemvo-email' ), $data['hd'] ) : __( 'Google Workspace', 'nemvo-email' ) )
				);
				?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Permission', 'nemvo-email' ); ?></dt>
			<dd><?php echo $full ? esc_html__( 'Full Gmail access', 'nemvo-email' ) : esc_html__( 'Send email only', 'nemvo-email' ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Daily limit', 'nemvo-email' ); ?></dt>
			<dd>
				<?php
				/* translators: %s: number of messages */
				echo esc_html( sprintf( __( '%s messages', 'nemvo-email' ), number_format_i18n( $provider->daily_limit() ) ) );
				?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Connected', 'nemvo-email' ); ?></dt>
			<dd>
				<?php
				echo esc_html( $data['connected_at'] ? wp_date( get_option( 'date_format' ), (int) $data['connected_at'] ) : '–' );
				if ( 'wp-mail-smtp' === $data['imported'] ) {
					echo ' · ' . esc_html__( 'imported from WP Mail SMTP', 'nemvo-email' );
				}
				?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Last checked', 'nemvo-email' ); ?></dt>
			<dd><?php echo esc_html( $data['checked_at'] ? sprintf( /* translators: %s: time span, e.g. 5 mins */ __( '%s ago', 'nemvo-email' ), human_time_diff( (int) $data['checked_at'] ) ) : '–' ); ?></dd>
		</div>
	<?php endif; ?>
	<div class="is-wide">
		<dt><?php esc_html_e( 'OAuth client ID', 'nemvo-email' ); ?></dt>
		<dd><code><?php echo esc_html( '' !== $provider->client_id() ? $provider->client_id() : '–' ); ?></code></dd>
	</div>
</dl>

<?php if ( $full && $provider->is_connected() ) : ?>
	<p class="nve-note"><?php esc_html_e( 'This connection was made by WP Mail SMTP, which asks for full access to the mailbox. Connect again here to limit it to sending, after adding the redirect URI below to the OAuth client in Google Cloud (Clients › your client › Authorized redirect URIs).', 'nemvo-email' ); ?></p>
<?php endif; ?>

<?php if ( $provider->credentials_locked() ) : ?>
	<p class="nve-muted"><?php esc_html_e( 'The OAuth client is set with NEMVO_EMAIL_GOOGLE_CLIENT_ID and NEMVO_EMAIL_GOOGLE_CLIENT_SECRET.', 'nemvo-email' ); ?></p>
<?php endif; ?>

<h3><?php esc_html_e( 'Authorized redirect URI', 'nemvo-email' ); ?></h3>
<p class="nve-muted"><?php esc_html_e( 'Listed under the OAuth client in Google Cloud. It changes if the site\'s address changes.', 'nemvo-email' ); ?></p>
<?php require NEMVO_EMAIL_DIR . 'templates/partials/redirect-uri.php'; ?>
