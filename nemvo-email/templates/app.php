<?php
/**
 * Overview and log (one script, two views).
 *
 * Included from Admin::render_app(); $title, $page and $view are its variables.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variables of Admin::render_app().

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap nve-wrap nve-<?php echo esc_attr( $view ); ?>">
	<?php Admin::header( $title, $page ); ?>
	<?php Admin::print_flash(); ?>
	<?php
	if ( 'overview' === $view ) {
		require NEMVO_EMAIL_DIR . 'templates/partials/status.php';
	}
	?>
	<div id="nve-app" data-view="<?php echo esc_attr( $view ); ?>">
		<noscript><p class="nve-note"><?php esc_html_e( 'This screen needs JavaScript.', 'nemvo-email' ); ?></p></noscript>
	</div>
</div>
