<?php
/**
 * Type-safe HTML attributes container.
 *
 * Enforces string keys and string values to ensure valid DOM attributes.
 * All values are automatically cast to strings.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Types;

use InvalidArgumentException;

/**
 * Immutable-style attributes container with fluent API.
 *
 * HTML attributes are always string=>string in the DOM. This class enforces
 * that contract and provides a clean API for building attribute sets.
 */
final class Attributes {

	/**
	 * Attribute storage.
	 *
	 * @var array<string, string>
	 */
	private array $attributes = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create empty attributes set.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Create from array, normalizing all values to strings.
	 *
	 * @param array<string, mixed> $attributes Initial attributes.
	 * @throws InvalidArgumentException When non-string key is provided.
	 */
	public static function from_array( array $attributes ): self {
		$instance = new self();

		foreach ( $attributes as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					'Attributes require string keys. Got: ' . gettype( $key )
				);
			}
			$instance->attributes[ $key ] = self::normalize_value( $value );
		}

		return $instance;
	}

	/**
	 * Add a single attribute.
	 *
	 * @param string $name Attribute name.
	 * @param mixed  $value Attribute value (will be cast to string).
	 */
	public function add( string $name, mixed $value ): self {
		$this->attributes[ $name ] = self::normalize_value( $value );
		return $this;
	}

	/**
	 * Add multiple attributes at once.
	 *
	 * @param array<string, mixed> $attributes Attributes to add.
	 * @throws InvalidArgumentException When non-string key is provided.
	 */
	public function add_many( array $attributes ): self {
		foreach ( $attributes as $name => $value ) {
			if ( ! is_string( $name ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					'Attributes require string keys. Got: ' . gettype( $name )
				);
			}
			$this->attributes[ $name ] = self::normalize_value( $value );
		}
		return $this;
	}

	/**
	 * Remove an attribute.
	 *
	 * @param string $name Attribute name to remove.
	 */
	public function remove( string $name ): self {
		unset( $this->attributes[ $name ] );
		return $this;
	}

	/**
	 * Check if attribute exists.
	 *
	 * @param string $name Attribute name.
	 */
	public function has( string $name ): bool {
		return array_key_exists( $name, $this->attributes );
	}

	/**
	 * Get a single attribute value.
	 *
	 * @param string $name           Attribute name.
	 * @param string $default_value  Default value if not found.
	 */
	public function get( string $name, string $default_value = '' ): string {
		return $this->attributes[ $name ] ?? $default_value;
	}

	/**
	 * Get all attributes as array.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return $this->attributes;
	}

	/**
	 * Merge with another Attributes instance.
	 *
	 * @param self $other Attributes to merge (overwrites existing).
	 */
	public function merge( self $other ): self {
		$this->attributes = array_merge( $this->attributes, $other->to_array() );
		return $this;
	}

	/**
	 * Normalize any value to a string.
	 *
	 * @param mixed $value Value to normalize.
	 * @throws InvalidArgumentException When value is an array or object.
	 */
	private static function normalize_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_null( $value ) ) {
			return '';
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				'Attribute values cannot be arrays or objects. Got: ' . gettype( $value )
			);
		}

		return (string) $value;
	}
}
