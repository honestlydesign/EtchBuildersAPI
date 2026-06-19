<?php
/**
 * String property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch string properties.
 *
 * Example:
 *   StringProperty::new('Tag')
 *     ->key('tag')
 *     ->default('div')
 *     ->to_array();
 */
final class StringProperty extends BaseProperty {

	/**
	 * Create a new string property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default value.
	 *
	 * @param mixed $value Default string value.
	 * @throws InvalidArgumentException When value is not a string.
	 */
	public function default( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'String property default must be a string.' );
		}
		$this->default_value = $value;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Returns the string primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	/**
	 * Builds string-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
