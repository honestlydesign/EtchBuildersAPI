<?php
/**
 * Defensive JSON-like array normalization for immutable value objects.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use InvalidArgumentException;

/**
 * Copies accepted scalar/nested-array payloads without sharing mutable input.
 */
final class ImmutableArray {

	private function __construct() {
	}

	/**
	 * @param array<array-key, mixed> $payload
	 * @return array<array-key, mixed>
	 */
	public static function copy( array $payload, string $invalid_message ): array {
		$frozen = array();
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$frozen[ $key ] = self::copy( $value, $invalid_message );
				continue;
			}
			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				$frozen[ $key ] = $value;
				continue;
			}

			throw new InvalidArgumentException( $invalid_message );
		}

		return $frozen;
	}

	/**
	 * Canonicalize nested associative maps while retaining list order.
	 *
	 * @param array<array-key, mixed> $value
	 * @return array<array-key, mixed>
	 */
	public static function canonicalize( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonicalize( $item );
			}
		}

		return $value;
	}
}
