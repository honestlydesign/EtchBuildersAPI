<?php
/**
 * Condition property builder.
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
 * Fluent builder for Etch condition properties (specialized string).
 */
final class ConditionProperty extends BaseProperty {

	/**
	 * Nested child properties keyed by prop key.
	 *
	 * @var array<string, ComponentPropertyInterface>
	 */
	private array $properties = array();

	/**
	 * Create a new condition property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default condition expression.
	 *
	 * @param mixed $value Default condition expression.
	 * @throws InvalidArgumentException When value is not a string.
	 */
	public function default( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Condition property default must be a string.' );
		}

		$this->default_value = $value;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Add a nested property to the condition property.
	 *
	 * @param ComponentPropertyInterface $property Nested property builder.
	 */
	public function prop( ComponentPropertyInterface $property ): self {
		$this->properties[ $property->get_key() ] = $property;
		return $this;
	}

	/**
	 * Returns the string primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	/**
	 * Returns the condition specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'condition';
	}

	/**
	 * Builds condition-only payload fields.
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
