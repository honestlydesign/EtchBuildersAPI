<?php
/**
 * JSON encoding helper matching wp_json_encode for Etch's wire format.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

/**
 * Pure-PHP replacement for wp_json_encode with Etch's required flags.
 */
final class Json {

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Encode a value with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE.
	 *
	 * Mirrors the flags Etch expects for class names with non-ASCII chars
	 * and URLs with forward slashes.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	public static function encode( mixed $value ): string {
		$result = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $result ? '' : $result;
	}
}
