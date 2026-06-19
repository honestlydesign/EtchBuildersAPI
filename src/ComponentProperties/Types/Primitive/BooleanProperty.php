<?php
/**
 * Boolean property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch boolean properties.
 *
 * Example:
 *   BooleanProperty::new('Loop')
 *     ->key('loop')
 *     ->default(true)
 *     ->to_array();
 */
final class BooleanProperty extends BaseProperty {

	/**
	 * Default boolean value.
	 *
	 * @var bool
	 */
	private bool $default_boolean = false;

	/**
	 * Create a new boolean property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default value.
	 *
	 * @param mixed $value Default boolean value.
	 * @throws InvalidArgumentException When value is not a boolean.
	 */
	public function default( mixed $value ): self {
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'Boolean property default must be a boolean.' );
		}
		$this->default_boolean = $value;
		$this->default_value   = $value;
		$this->has_default     = true;
		return $this;
	}

	/**
	 * Builds boolean-only payload fields.
	 *
	 * Boolean always includes default (false when not explicitly set).
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		// Always return default for boolean properties.
		return array(
			'default' => $this->default_boolean,
		);
	}

	/**
	 * Returns the boolean primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::BOOLEAN;
	}
}
