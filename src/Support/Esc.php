<?php
/**
 * HTML escaping helper matching esc_html.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

/**
 * Pure-PHP replacement for esc_html.
 */
final class Esc {

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Escape a string for HTML output.
	 *
	 * Matches WordPress esc_html for the inputs Etch uses (CSS strings,
	 * style bodies). Uses htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE
	 * and UTF-8, mirroring WP's $trusted_html escaping.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	public static function html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
