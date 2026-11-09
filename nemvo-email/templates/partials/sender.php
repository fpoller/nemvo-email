<?php
/**
 * Sender form.
 *
 * Expects $provider and $back (where to go after saving) in the including scope.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of the including method.

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$settings = Settings::get();
$account  = $provider->account();
$site     = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nve-form">
	<input type="hidden" name="action" value="nemvo_email_sender">
	<input type="hidden" name="_back" value="<?php echo esc_url( $back ); ?>">
	<?php wp_nonce_field( 'nemvo_email_sender' ); ?>
	<div class="nve-fields nve-fields--2">
		<label>
			<span><?php esc_html_e( 'From address', 'nemvo-email' ); ?></span>
			<input type="email" name="nve[from_email]" value="<?php echo esc_attr( $settings['from_email'] ); ?>" placeholder="<?php echo esc_attr( $account ); ?>" autocomplete="off" data-nve-from data-account="<?php echo esc_attr( $account ); ?>">
			<small>
				<?php
				if ( '' !== $account ) {
					/* translators: %s: connected account */
					echo esc_html( sprintf( __( 'Empty: %s itself. Another address must be one of its "Send mail as" addresses in Gmail (Settings › Accounts), a Google group it may send as, or an alias; otherwise Gmail sends as the account instead.', 'nemvo-email' ), $account ) );
				}
				?>
			</small>
		</label>
		<label>
			<span><?php esc_html_e( 'From name', 'nemvo-email' ); ?></span>
			<input type="text" name="nve[from_name]" value="<?php echo esc_attr( $settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( $site ); ?>" autocomplete="off">
			<small><?php esc_html_e( 'Empty: the site title.', 'nemvo-email' ); ?></small>
		</label>
	</div>
	<div class="nve-checks">
		<label class="nve-check">
			<input type="checkbox" name="nve[force_from_email]" value="1" <?php checked( $settings['force_from_email'] ); ?>>
			<span><?php esc_html_e( 'Send all email from this address', 'nemvo-email' ); ?><small><?php esc_html_e( 'Recommended. Some plugins set their own sender, such as a contact form using the visitor\'s address. Gmail can\'t send as those, so they are replaced.', 'nemvo-email' ); ?></small></span>
		</label>
		<label class="nve-check">
			<input type="checkbox" name="nve[reply_to_original]" value="1" <?php checked( $settings['reply_to_original'] ); ?>>
			<span><?php esc_html_e( 'Make a replaced outside sender the Reply-To', 'nemvo-email' ); ?><small><?php esc_html_e( 'So that replying to a contact form message goes to the visitor. Addresses of your own domain, no-reply addresses and an existing Reply-To are left as they are.', 'nemvo-email' ); ?></small></span>
		</label>
		<label class="nve-check">
			<input type="checkbox" name="nve[force_from_name]" value="1" <?php checked( $settings['force_from_name'] ); ?>>
			<span><?php esc_html_e( 'Use this name for all email', 'nemvo-email' ); ?><small><?php esc_html_e( 'Off: plugins keep the names they set, like a shop name on order emails. "WordPress" is always replaced.', 'nemvo-email' ); ?></small></span>
		</label>
	</div>
	<p class="nve-actions"><button type="submit" class="button button-primary"><?php echo esc_html( $submit_label ?? __( 'Save sender', 'nemvo-email' ) ); ?></button></p>
</form>
