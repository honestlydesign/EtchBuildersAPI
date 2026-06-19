<?php
/**
 * Encode JSON values for Etch HTML attributes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use InvalidArgumentException;

/**
 * Applies Etch double-brace escaping so JSON survives attribute sanitization.
 */
final class EtchJsonAttribute {

	/**
	 * Encode a PHP value or JSON string for use in an HTML attribute.
	 *
	 * @param array<int|string, mixed>|string $value PHP array (encoded with wp_json_encode) or pre-encoded JSON string.
	 * @throws InvalidArgumentException When the value cannot be JSON-encoded.
	 */
	public static function encode_value( array|string $value ): string {
		if ( is_array( $value ) ) {
			$json = Json::encode( $value );
			if ( '' === $json ) {
				throw new InvalidArgumentException( 'Value is not JSON-encodable.' );
			}
		} else {
			$json = $value;
		}

		return str_replace( array( '{', '}' ), array( '{{', '}}' ), $json );
	}
}