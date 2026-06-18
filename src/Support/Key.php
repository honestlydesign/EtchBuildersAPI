<?php
/**
 * Key sanitization helper matching WordPress sanitize_key exactly.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

/**
 * Pure-PHP replacement for sanitize_key.
 */
final class Key {

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Sanitize a key: lowercase and remove any character outside [a-z0-9_-].
	 *
	 * Verified golden-equivalent to WordPress sanitize_key. WP removes invalid
	 * characters (it does NOT replace them with hyphens) and strips all whitespace.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function sanitize( string $key ): string {
		$key = strtolower( $key );
		$key = preg_replace( '/[^a-z0-9_-]/', '', $key );

		return null === $key ? '' : $key;
	}
}
