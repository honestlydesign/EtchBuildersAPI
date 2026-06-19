<?php
/**
 * Value object for select property options.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

use InvalidArgumentException;

/**
 * Represents a single option in a select property.
 *
 * Format: "label : value" or just "value" when label equals value.
 */
final class SelectOption {

	/**
	 * Option display label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Option value.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $label Option display label.
	 * @param string $value Option value.
	 * @throws InvalidArgumentException When value is empty.
	 */
	private function __construct( string $label, string $value ) {
		$value = trim( $value );

		if ( '' === $value ) {
			throw new InvalidArgumentException( 'Select option value cannot be empty.' );
		}

		$this->label = trim( $label );
		$this->value = $value;
	}

	/**
	 * Create an option with only a value (label equals value).
	 *
	 * @param string $value Option value (also used as label).
	 */
	public static function from_value( string $value ): self {
		$value = trim( $value );
		return new self( $value, $value );
	}

	/**
	 * Create an option with separate label and value.
	 *
	 * @param string $label Option display label.
	 * @param string $value Option value.
	 */
	public static function from_label_and_value( string $label, string $value ): self {
		return new self( $label, $value );
	}

	/**
	 * Get the option label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Get the option value.
	 */
	public function get_value(): string {
		return $this->value;
	}

	/**
	 * Convert to selectOptionsString format.
	 *
	 * Returns "label : value" or just "value" when label equals value.
	 */
	public function to_string(): string {
		if ( $this->label === $this->value ) {
			return $this->value;
		}
		return "{$this->label} : {$this->value}";
	}
}
