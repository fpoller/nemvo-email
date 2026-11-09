<?php
/**
 * Screen header: site name, title, navigation and the theme switch.
 *
 * Included from Admin::header(); $title and $current are its parameters.
 *
 * @package NemvoEmail
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- local variables of Admin::header().

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

$links = array(
	Admin::SLUG          => __( 'Overview', 'nemvo-email' ),
	Admin::SLUG_LOG      => __( 'Log', 'nemvo-email' ),
	Admin::SLUG_SETTINGS => __( 'Settings', 'nemvo-email' ),
);
if ( ! Settings::get( 'setup_done' ) ) {
	$links = array( Admin::SLUG_SETUP => __( 'Set up', 'nemvo-email' ) ) + $links;
}
?>
<header class="nve-top">
	<div class="nve-top__title">
		<span class="nve-top__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		<h1><?php echo esc_html( $title ); ?></h1>
	</div>
	<nav class="nve-top__nav" aria-label="<?php esc_attr_e( 'Email', 'nemvo-email' ); ?>">
		<?php foreach ( $links as $slug => $label ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<button type="button" class="nve-icon-btn" data-nve-theme-toggle hidden></button>
</header>
