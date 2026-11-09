<?php
/**
 * Status badges (16 × 16 icons in currentColor) for the templates.
 *
 * @package NemvoEmail
 */

namespace NemvoEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Icons.
 */
final class Icons {

	/**
	 * A badge with an icon for a state.
	 *
	 * @param string $state good | warning | critical | neutral.
	 * @param string $text  Visible text ('' for the icon alone).
	 * @param string $label Accessible label for an icon-only badge.
	 * @return string HTML.
	 */
	public static function badge( $state, $text = '', $label = '' ) {
		$paths = array(
			'good'     => '<path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'warning'  => '<path d="M8 4.5v4.5M8 11.5v.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
			'critical' => '<path d="M4.5 4.5l7 7M11.5 4.5l-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
			'neutral'  => '<circle cx="8" cy="8" r="2.5" fill="currentColor"/>',
		);
		$state = isset( $paths[ $state ] ) ? $state : 'neutral';
		$aria  = '' === $text && '' !== $label ? ' role="img" aria-label="' . esc_attr( $label ) . '"' : ' aria-hidden="true"';
		return sprintf(
			'<span class="nve-badge is-%1$s"><svg viewBox="0 0 16 16" width="14" height="14"%2$s>%3$s</svg>%4$s</span>',
			esc_attr( $state ),
			$aria,
			$paths[ $state ],
			esc_html( $text )
		);
	}

	/**
	 * Allowed SVG markup for wp_kses.
	 *
	 * @return array
	 */
	public static function kses() {
		return array(
			'span'   => array(
				'class'      => true,
				'role'       => true,
				'aria-label' => true,
			),
			'svg'    => array(
				'viewbox'     => true,
				'width'       => true,
				'height'      => true,
				'aria-hidden' => true,
				'role'        => true,
				'aria-label'  => true,
			),
			'path'   => array(
				'd'               => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			),
			'circle' => array(
				'cx'   => true,
				'cy'   => true,
				'r'    => true,
				'fill' => true,
			),
		);
	}

	/**
	 * Echo a badge.
	 *
	 * @param string $state State.
	 * @param string $text  Text.
	 * @param string $label Accessible label.
	 */
	public static function echo_badge( $state, $text = '', $label = '' ) {
		echo wp_kses( self::badge( $state, $text, $label ), self::kses() );
	}
}
