<?php
/**
 * Test email form (sent through the REST API by admin.js).
 *
 * Expects $next (URL of the next step, or '') in the including scope.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of the including method.

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$user = wp_get_current_user();
?>
<form class="nve-form nve-test" data-nve-test<?php echo '' !== ( $next ?? '' ) ? ' data-next="' . esc_url( $next ) . '"' : ''; ?>>
	<div class="nve-fields nve-fields--inline">
		<label>
			<span><?php esc_html_e( 'Send a test email to', 'nemvo-email' ); ?></span>
			<input type="email" name="to" value="<?php echo esc_attr( $user->user_email ); ?>" required class="regular-text">
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Send test email', 'nemvo-email' ); ?></button>
	</div>
	<div class="nve-test__result" role="status" aria-live="polite"></div>
</form>
