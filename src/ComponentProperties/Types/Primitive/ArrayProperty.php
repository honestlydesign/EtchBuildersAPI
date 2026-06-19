<?php
/**
 * Array property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch array properties.
 *
 * Example:
 *   ArrayProperty::new('Items')
 *     ->key('items')
 *     ->default(['a', 'b', 'c'])
 *     ->to_array();
 */
final class ArrayProperty extends BaseProperty {

	/**
	 * Optional default array value.
	 *
	 * @var array<int, mixed>|null
	 */
	private ?array $default_array = null;

	/**
	 * Create a new array property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default value.
	 *
	 * @param mixed $value Default array value.
	 * @throws InvalidArgumentException When value is not an array.
	 */
	public function default( mixed $value ): self {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Array property default must be an array.' );
		}
		$this->default_array = array_values( $value );
		$this->default_value = $this->default_array;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Returns the array primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::ARRAY;
	}

	/**
	 * Builds array-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
