<?php
/**
 * Repeater group property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch repeater properties (specialized array).
 */
final class RepeaterGroupProperty extends BaseProperty {

	/**
	 * Nested child properties keyed by prop key.
	 *
	 * @var array<string, ComponentPropertyInterface>
	 */
	private array $properties = array();

	/**
	 * Create a new repeater property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Repeater properties do not support defaults.
	 *
	 * @param mixed $value Unused default value.
	 * @throws InvalidArgumentException Always.
	 */
	public function default( mixed $value ): self {
		unset( $value );

		throw new InvalidArgumentException( 'Repeater property does not support a default.' );
	}

	/**
	 * Add a nested property to the repeater property.
	 *
	 * @param ComponentPropertyInterface $property Nested property builder.
	 */
	public function prop( ComponentPropertyInterface $property ): self {
		$this->properties[ $property->get_key() ] = $property;
		return $this;
	}

	/**
	 * Returns the array primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::ARRAY;
	}

	/**
	 * Returns the repeater specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'repeater';
	}

	/**
	 * Builds repeater-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		$properties = array();

		foreach ( $this->properties as $property ) {
			$properties[] = $property->to_array();
		}

		return array(
			'properties' => $properties,
		);
	}
}
