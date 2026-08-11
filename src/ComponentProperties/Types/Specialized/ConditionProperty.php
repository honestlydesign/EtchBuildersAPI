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
	 * Nested child properties in declaration order.
	 *
	 * @var array<int, ComponentPropertyInterface>
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
		foreach ( $this->properties as $index => $existing ) {
			if ( $existing->get_key() === $property->get_key() ) {
				$this->properties[ $index ] = $property;
				return $this;
			}
		}

		$this->properties[] = $property;
		return $this;
	}

	/**
	 * Return child definitions that resolve transparently into the parent scope.
	 *
	 * @internal
	 * @return array<int, ComponentPropertyInterface>
	 */
	public function nested_properties(): array {
		return $this->properties;
	}

	/**
	 * Reject ambiguous live definition keys throughout nested conditions.
	 *
	 * @internal
	 */
	public function assert_unique_nested_keys(): void {
		$seen = array();

		foreach ( $this->properties as $property ) {
			$key = $property->get_key();
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Condition property has duplicate current child property key "%s".', $key )
				);
			}

			$seen[ $key ] = true;
			if ( $property instanceof self ) {
				$property->assert_unique_nested_keys();
			}
		}
	}

	/**
	 * Convert the condition only after live child-key validation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$this->assert_unique_nested_keys();
		return parent::to_array();
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
