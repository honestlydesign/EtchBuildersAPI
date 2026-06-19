<?php
/**
 * Object property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive;

use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\ObjectDefaultNormalizer;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch object properties.
 *
 * Example:
 *   ObjectProperty::new('Config')
 *     ->key('config')
 *     ->default(['enabled' => true])
 *     ->to_array();
 */
final class ObjectProperty extends BaseProperty {

	/**
	 * Optional default object/array value.
	 *
	 * @var array<string, mixed>|array<int, mixed>|null
	 */
	private ?array $default_object = null;

	/**
	 * Create a new object property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default value.
	 *
	 * @param mixed $value Default object/array value.
	 */
	public function default( mixed $value ): self {
		$this->default_object = ObjectDefaultNormalizer::normalize( $value, 'Object property' );
		$this->default_value  = $this->default_object;
		$this->has_default    = true;
		return $this;
	}

	/**
	 * Returns the object primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::OBJECT;
	}

	/**
	 * Builds object-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
