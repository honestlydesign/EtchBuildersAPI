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
 * Fluent builder for Etch number properties.
 *
 * WARNING: NumberProperty is currently NOT SUPPORTED for component instance properties.
 *
 * Component instance properties are stored in HTML attributes, which can only contain strings.
 * Always use StringProperty with string defaults for numeric values:
 *
 *   // ❌ WRONG: NumberProperty will not work correctly
 *   NumberProperty::new('Slides Per View')
 *     ->key('slidesPerView')
 *     ->default(1)
 *
 *   // ✅ CORRECT: Use StringProperty with string default
 *   StringProperty::new('Slides Per View')
 *     ->key('slidesPerView')
 *     ->default('1')
 *
 * The TypeScript runtime must parse these string values to numbers when needed:
 *   parseInt(element.dataset.omideSlidesPerView, 10)
 *
 * This class exists for future use when Etch may support typed property storage.
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
