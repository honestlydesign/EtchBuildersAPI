<?php
/**
 * Number property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch number property definitions.
 *
 * Etch supports the primitive=number definition and numeric defaults. Authored
 * component instance attributes still cross the HTML wire as a numeric-string;
 * Etch resolves that string to a number. There is deliberately no prop_number()
 * instance method: use the matrix-backed numeric-string value route.
 *
 * Example:
 *   NumberProperty::new('Count')
 *     ->key('count')
 *     ->default(5)
 *     ->option(1)
 *     ->option(2)
 *     ->to_array();
 */
final class NumberProperty extends BaseProperty {

	/**
	 * Optional numeric option list.
	 *
	 * @var array<int, int|float>|null
	 */
	private ?array $options = null;

	/**
	 * Create a new number property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default value.
	 *
	 * @param mixed $value Default numeric value.
	 * @throws InvalidArgumentException When value is not numeric.
	 */
	public function default( mixed $value ): self {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			throw new InvalidArgumentException( 'Number property default must be numeric.' );
		}
		$this->default_value = $value;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Add a numeric option.
	 *
	 * @param int|float $value Option value.
	 */
	public function option( int|float $value ): self {
		if ( null === $this->options ) {
			$this->options = array();
		}
		$this->options[] = $value;
		return $this;
	}

	/**
	 * Add multiple numeric options at once.
	 *
	 * @param array<int, mixed> $values Option values.
	 * @throws InvalidArgumentException When a value is not numeric.
	 */
	public function options( array $values ): self {
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				throw new InvalidArgumentException( 'Number options must be numeric.' );
			}
		}
		$this->options = array_values( $values );
		return $this;
	}

	/**
	 * Returns the number primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::NUMBER;
	}

	/**
	 * Builds number-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		$payload = array();

		if ( null !== $this->options ) {
			$payload['options'] = $this->options;
		}

		return $payload;
	}
}
