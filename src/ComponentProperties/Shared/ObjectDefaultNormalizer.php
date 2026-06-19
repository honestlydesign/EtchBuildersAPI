<?php
/**
 * Shared normalizer for object-like component property defaults.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

use InvalidArgumentException;
use stdClass;

/**
 * Normalize array/stdClass defaults for object-shaped properties.
 */
final class ObjectDefaultNormalizer {

	/**
	 * Normalize an object-like default into an array.
	 *
	 * @param mixed  $value   Default value to normalize.
	 * @param string $context Context label used in exception messages.
	 * @return array<string, mixed>|array<int, mixed>
	 * @throws InvalidArgumentException When the value is not array/stdClass.
	 */
	public static function normalize( mixed $value, string $context ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( $value instanceof stdClass ) {
			return get_object_vars( $value );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new InvalidArgumentException( $context . ' default must be an array or stdClass.' );
	}
}
