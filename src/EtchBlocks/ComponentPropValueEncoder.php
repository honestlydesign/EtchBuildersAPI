<?php
/**
 * Shared Etch component prop value encoder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use stdClass;
use HonestlyDesign\EtchBuilders\Support\Json;

/**
 * Encodes Etch component instance values using the real Etch attribute wire format.
 */
final class ComponentPropValueEncoder {

	/**
	 * Prevent instantiation of static helper.
	 */
	private function __construct() {
	}

	/**
	 * Encode a top-level plain string prop.
	 *
	 * @param string $value String prop value.
	 */
	public static function string( string $value ): string {
		return $value;
	}

	/**
	 * Encode a top-level boolean prop as an Etch expression string.
	 *
	 * @param bool $value Boolean prop value.
	 */
	public static function boolean( bool $value ): string {
		return $value ? '{true}' : '{false}';
	}

	/**
	 * Encode a top-level expression prop.
	 *
	 * @param string $expression Expression without surrounding braces.
	 * @return string
	 * @throws InvalidArgumentException When expression is empty.
	 */
	public static function expression( string $expression ): string {
		$normalized_expression = trim( $expression );

		if ( '' === $normalized_expression ) {
			throw new InvalidArgumentException( 'Component prop expression must be non-empty.' );
		}

		if ( str_starts_with( $normalized_expression, '{' ) && str_ends_with( $normalized_expression, '}' ) ) {
			return $normalized_expression;
		}

		return '{' . $normalized_expression . '}';
	}

	/**
	 * Encode a top-level class prop as a space-delimited string.
	 *
	 * @param array<int, string> $class_names Class names or style IDs.
	 * @return string
	 */
	public static function class( array $class_names ): string {
		return implode( ' ', self::normalize_class_names( $class_names, 'Component prop class' ) );
	}

	/**
	 * Encode a top-level group prop.
	 *
	 * @param array<string, mixed> $payload Group payload.
	 * @return string
	 */
	public static function group( array $payload ): string {
		$normalized_payload = self::normalize_group_payload( $payload, true );
		$encoded_payload    = self::json_encode_payload(
			array() === $normalized_payload ? new stdClass() : $normalized_payload,
			'Component group prop payload'
		);

		return '{' . $encoded_payload . '}';
	}

	/**
	 * Encode a top-level array prop.
	 *
	 * @param array<int, mixed> $items Array items.
	 * @return string
	 */
	public static function array( array $items ): string {
		$normalized_items = self::normalize_array_items( $items );
		$encoded_items    = self::json_encode_payload( $normalized_items, 'Component array prop payload' );

		return '{' . $encoded_items . '}';
	}

	/**
	 * Encode a top-level repeater prop.
	 *
	 * @param array<int, array<int|string, mixed>|ComponentPropGroup|stdClass> $items Repeater items.
	 * @return string
	 * @throws InvalidArgumentException When items are invalid.
	 */
	public static function repeater( array $items ): string {
		$normalized_items = self::normalize_repeater_items( $items );
		$encoded_items    = self::json_encode_payload( $normalized_items, 'Component repeater prop payload' );

		return '{' . $encoded_items . '}';
	}

	/**
	 * Normalize a group payload recursively.
	 *
	 * @param array<int|string, mixed> $payload Group payload.
	 * @param bool                     $is_root Whether this is the root group payload.
	 * @return array<int|string, mixed>
	 * @throws InvalidArgumentException When payload keys are invalid.
	 */
	public static function normalize_group_payload( array $payload, bool $is_root ): array {
		$normalized_payload = array();

		foreach ( $payload as $payload_key => $payload_value ) {
			if ( $is_root && ! is_string( $payload_key ) ) {
				throw new InvalidArgumentException( 'Component group payload keys must be strings.' );
			}

			$normalized_payload[ $payload_key ] = self::normalize_group_value(
				$payload_key,
				$payload_value
			);
		}

		return $normalized_payload;
	}

	/**
	 * Normalize one value inside a group payload.
	 *
	 * @param int|string $payload_key Payload key.
	 * @param mixed      $payload_value Payload value.
	 * @return mixed
	 * @throws InvalidArgumentException When payload values are invalid.
	 */
	public static function normalize_group_value( int|string $payload_key, mixed $payload_value ): mixed {
		$normalized_payload_key = is_string( $payload_key ) ? trim( $payload_key ) : (string) $payload_key;

		if ( is_bool( $payload_value ) ) {
			return self::boolean( $payload_value );
		}

		if ( is_int( $payload_value ) || is_float( $payload_value ) ) {
			return (string) $payload_value;
		}

		if ( is_string( $payload_value ) || null === $payload_value ) {
			return null === $payload_value ? '' : $payload_value;
		}

		if ( $payload_value instanceof ComponentPropGroup ) {
			return self::normalize_group_payload( $payload_value->to_array(), false );
		}

		if ( $payload_value instanceof ComponentPropArray ) {
			return $payload_value->encode();
		}

		if ( $payload_value instanceof ComponentPropRepeater ) {
			return $payload_value->encode();
		}

		if ( $payload_value instanceof stdClass ) {
			return self::normalize_group_payload( get_object_vars( $payload_value ), false );
		}

		if ( is_array( $payload_value ) ) {
			if ( self::should_treat_array_as_class_value( $normalized_payload_key, $payload_value ) ) {
				return self::class( $payload_value );
			}

			if ( self::is_list( $payload_value ) ) {
				return self::array( $payload_value );
			}

			return self::normalize_group_payload( $payload_value, false );
		}

		throw new InvalidArgumentException(
			'Component group payload values must be strings, integers, floats, booleans, arrays, ComponentPropGroup, ComponentPropArray, ComponentPropRepeater, or stdClass.'
		);
	}

	/**
	 * Normalize generic array items recursively.
	 *
	 * @param array<int, mixed> $items Array items.
	 * @return array<int, mixed>
	 */
	public static function normalize_array_items( array $items ): array {
		$normalized_items = array();

		foreach ( $items as $item ) {
			$normalized_items[] = self::normalize_array_item( $item );
		}

		return $normalized_items;
	}

	/**
	 * Normalize repeater items recursively.
	 *
	 * @param array<int, mixed> $items Repeater items.
	 * @return array<int, array<int|string, mixed>>
	 * @throws InvalidArgumentException When items are invalid.
	 */
	public static function normalize_repeater_items( array $items ): array {
		$normalized_items = array();

		foreach ( $items as $item ) {
			if ( $item instanceof ComponentPropGroup ) {
				$normalized_items[] = self::normalize_repeater_item_payload( $item->to_array() );
				continue;
			}

			if ( $item instanceof stdClass ) {
				$normalized_items[] = self::normalize_repeater_item_payload( get_object_vars( $item ) );
				continue;
			}

			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'Component repeater items must be arrays, ComponentPropGroup, or stdClass.' );
			}

			if ( ! self::is_associative_array( $item ) ) {
				throw new InvalidArgumentException( 'Component repeater items must be associative arrays.' );
			}

			$normalized_items[] = self::normalize_repeater_item_payload( $item );
		}

		return $normalized_items;
	}

	/**
	 * Normalize one repeater item payload.
	 *
	 * Nested group values inside repeater rows must stay encoded as Etch group
	 * strings so the Etch editor can resolve them using its group transform path.
	 *
	 * @param array<int|string, mixed> $payload Repeater item payload.
	 * @return array<int|string, mixed>
	 * @throws InvalidArgumentException When payload keys or values are invalid.
	 */
	private static function normalize_repeater_item_payload( array $payload ): array {
		$normalized_payload = array();

		foreach ( $payload as $payload_key => $payload_value ) {
			if ( ! is_string( $payload_key ) ) {
				throw new InvalidArgumentException( 'Component group payload keys must be strings.' );
			}

			if ( $payload_value instanceof ComponentPropGroup ) {
				$normalized_payload[ $payload_key ] = $payload_value->encode();
				continue;
			}

			if ( $payload_value instanceof stdClass ) {
				$normalized_payload[ $payload_key ] = self::group( get_object_vars( $payload_value ) );
				continue;
			}

			if ( is_array( $payload_value ) && ! self::is_list( $payload_value ) ) {
				$normalized_payload[ $payload_key ] = self::group( $payload_value );
				continue;
			}

			$normalized_payload[ $payload_key ] = self::normalize_group_value(
				$payload_key,
				$payload_value
			);
		}

		return $normalized_payload;
	}

	/**
	 * Normalize one generic array item.
	 *
	 * @param mixed $item Array item.
	 * @return mixed
	 * @throws InvalidArgumentException When item type is invalid.
	 */
	private static function normalize_array_item( mixed $item ): mixed {
		if ( is_bool( $item ) ) {
			return self::boolean( $item );
		}

		if ( is_int( $item ) || is_float( $item ) ) {
			return (string) $item;
		}

		if ( is_string( $item ) || null === $item ) {
			return null === $item ? '' : $item;
		}

		if ( $item instanceof ComponentPropGroup ) {
			return self::normalize_group_payload( $item->to_array(), true );
		}

		if ( $item instanceof ComponentPropArray ) {
			return $item->encode();
		}

		if ( $item instanceof ComponentPropRepeater ) {
			return $item->encode();
		}

		if ( $item instanceof stdClass ) {
			return self::normalize_group_payload( get_object_vars( $item ), true );
		}

		if ( is_array( $item ) ) {
			if ( self::is_list( $item ) ) {
				return self::array( $item );
			}

			return self::normalize_group_payload( $item, true );
		}

		throw new InvalidArgumentException( 'Component array items must be scalars, arrays, ComponentPropGroup, ComponentPropArray, ComponentPropRepeater, or stdClass.' );
	}

	/**
	 * JSON-encode a normalized payload.
	 *
	 * @param mixed  $payload Payload to encode.
	 * @param string $context Error context label.
	 * @return string
	 * @throws InvalidArgumentException When encoding fails.
	 */
	private static function json_encode_payload( mixed $payload, string $context ): string {
		$encoded_payload = Json::encode( $payload );

		if ( '' === $encoded_payload ) {
			throw new InvalidArgumentException( $context . ' could not be JSON encoded.' );
		}

		return $encoded_payload;
	}

	/**
	 * Normalize class names.
	 *
	 * @param array<int, mixed> $class_names Raw class names.
	 * @param string            $context Error context label.
	 * @return array<int, string>
	 * @throws InvalidArgumentException When class names are invalid.
	 */
	private static function normalize_class_names( array $class_names, string $context ): array {
		$normalized_class_names = array();

		foreach ( $class_names as $class_name ) {
			if ( ! is_string( $class_name ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $context . ' values must contain only strings.' );
			}

			$normalized_class_name = trim( $class_name );
			if ( '' === $normalized_class_name ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $context . ' values cannot contain empty strings.' );
			}

			$normalized_class_names[] = $normalized_class_name;
		}

		return $normalized_class_names;
	}

	/**
	 * Determine whether an array value should be encoded as a class string.
	 *
	 * @param string            $payload_key Payload key.
	 * @param array<int, mixed> $payload_value Payload value.
	 */
	private static function should_treat_array_as_class_value( string $payload_key, array $payload_value ): bool {
		if ( ! self::is_list( $payload_value ) ) {
			return false;
		}

		$normalized_payload_key = strtolower( $payload_key );
		if ( ! str_ends_with( $normalized_payload_key, 'class' ) ) {
			return false;
		}

		foreach ( $payload_value as $entry ) {
			if ( ! is_string( $entry ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether an array is associative.
	 *
	 * @param array<mixed> $value Proposed array.
	 */
	private static function is_associative_array( array $value ): bool {
		return array() !== $value && ! self::is_list( $value );
	}

	/**
	 * Determine whether an array is a list.
	 *
	 * @param array<mixed> $value Proposed array.
	 */
	private static function is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}

		$expected_key = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			++$expected_key;
		}

		return true;
	}
}
