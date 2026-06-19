<?php
/**
 * Select property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\SelectOption;

/**
 * Fluent builder for Etch select properties (specialized string).
 *
 * Example:
 *   SelectProperty::new('Type')
 *     ->key('type')
 *     ->option('single')
 *     ->option_with_label('Multiple', 'multiple')
 *     ->option_with_label('Single', 'single')
 *     ->default('single')
 *     ->to_array();
 *
 * Produces options array and selectOptionsString in Etch format.
 */
final class SelectProperty extends BaseProperty {

	/**
	 * Select options.
	 *
	 * @var array<int, SelectOption>
	 */
	private array $select_options = array();

	/**
	 * Create a new select property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default select value.
	 *
	 * @param mixed $value Default option value.
	 * @throws InvalidArgumentException When value is not a string.
	 */
	public function default( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Select property default must be a string.' );
		}
		$this->default_value = $value;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Add an option with value only (label equals value).
	 *
	 * @param string $value Option value (also used as label).
	 */
	public function option( string $value ): self {
		$this->select_options[] = SelectOption::from_value( $value );
		return $this;
	}

	/**
	 * Add an option with separate label and value.
	 *
	 * @param string $label Option display label.
	 * @param string $value Option value.
	 */
	public function option_with_label( string $label, string $value ): self {
		$this->select_options[] = SelectOption::from_label_and_value( $label, $value );
		return $this;
	}

	/**
	 * Add multiple options from array.
	 *
	 * @param array<int, string> $values Option values (labels equal values).
	 * @throws InvalidArgumentException When a value is not a string.
	 */
	public function options( array $values ): self {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Select options must be strings.' );
			}
			$this->select_options[] = SelectOption::from_value( $value );
		}
		return $this;
	}

	/**
	 * Returns the string primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	/**
	 * Returns the select specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'select';
	}

	/**
	 * Builds select-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		$payload = array();

		if ( array() !== $this->select_options ) {
			// Build options array of values.
			$options = array();
			foreach ( $this->select_options as $option ) {
				$options[] = $option->get_value();
			}
			$payload['options'] = $options;

			// Build selectOptionsString.
			$option_strings = array();
			foreach ( $this->select_options as $option ) {
				$option_strings[] = $option->to_string();
			}
			$payload['selectOptionsString'] = implode( "\n", $option_strings );
		}

		return $payload;
	}
}
