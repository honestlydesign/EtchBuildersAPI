<?php
/**
 * Color property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch color properties (specialized string).
 *
 * Example:
 *   ColorProperty::new('Background Color')
 *     ->key('bgColor')
 *     ->default('#ffffff')
 *     ->to_array();
 */
final class ColorProperty extends BaseProperty {

	/**
	 * Create a new color property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default color value.
	 *
	 * @param mixed $value Default color value (hex, rgb, etc.).
	 * @throws InvalidArgumentException When value is not a string.
	 */
	public function default( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Color property default must be a string.' );
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
	 * Returns the color specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'color';
	}

	/**
	 * Builds color-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
