<?php
/**
 * The OAuth redirect URI with a copy button.
 *
 * Expects $provider in the including scope.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables of the including method.

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$uri = $provider->redirect_uri();
?>
<div class="nve-copyfield">
	<input type="text" readonly value="<?php echo esc_attr( $uri ); ?>" aria-label="<?php esc_attr_e( 'Authorized redirect URI', 'nemvo-email' ); ?>" spellcheck="false">
	<button type="button" class="button" data-nve-copy="<?php echo esc_attr( $uri ); ?>"><?php esc_html_e( 'Copy', 'nemvo-email' ); ?></button>
</div>
<?php
$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
if ( 0 !== strpos( $uri, 'https://' ) && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) :
	?>
	<p class="nve-flash is-error"><?php esc_html_e( 'Google only accepts https:// redirect URIs (except for localhost). Turn on SSL and change the site address to https:// under Settings › General first.', 'nemvo-email' ); ?></p>
<?php endif; ?>
