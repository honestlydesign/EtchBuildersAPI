<?php
/**
 * Class property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch class properties (specialized array).
 *
 * Example:
 *   ClassProperty::new('CSS Class')
 *     ->key('class')
 *     ->default(array('my-style-id'))
 *     ->to_array();
 */
final class ClassProperty extends BaseProperty {

	/**
	 * WordPress option name used by Etch styles.
	 */
	private const STYLES_OPTION_NAME = 'etch_styles';

	/**
	 * Create a new class property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default class style IDs.
	 *
	 * @param mixed $value Default style IDs array.
	 * @throws InvalidArgumentException When value is invalid.
	 */
	public function default( mixed $value ): self {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Class property default must be an array.' );
		}

		$style_ids = array_values( $value );
		$validated = array();

		foreach ( $style_ids as $style_id ) {
			if ( ! is_string( $style_id ) ) {
				throw new InvalidArgumentException( 'Class property default must contain only strings.' );
			}

			$normalized_style_id = trim( $style_id );
			if ( '' === $normalized_style_id ) {
				throw new InvalidArgumentException( 'Class property default cannot contain empty style IDs.' );
			}

			$this->assert_valid_class_style_id( $normalized_style_id );
			$validated[] = $normalized_style_id;
		}

		$this->default_value = $validated;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Append a single default class style ID.
	 *
	 * @param string $style_id Default class style ID.
	 * @throws InvalidArgumentException When style ID is invalid.
	 */
	public function default_style_id( string $style_id ): self {
		$style_ids = array();

		if ( $this->has_default && is_array( $this->default_value ) ) {
			foreach ( $this->default_value as $existing_style_id ) {
				if ( is_string( $existing_style_id ) ) {
					$style_ids[] = $existing_style_id;
				}
			}
		}

		$style_ids[] = $style_id;
		return $this->default( $style_ids );
	}

	/**
	 * Set default class style IDs.
	 *
	 * @param array<int, string> $style_ids Default class style IDs.
	 * @throws InvalidArgumentException When style IDs are invalid.
	 */
	public function default_style_ids( array $style_ids ): self {
		return $this->default( $style_ids );
	}

	/**
	 * Returns the array primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::ARRAY;
	}

	/**
	 * Returns the class specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'class';
	}

	/**
	 * Builds class-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}

	/**
	 * Validate style ID exists and is class style type.
	 *
	 * @param string $style_id Style ID.
	 * @throws InvalidArgumentException When style is unknown or not class type.
	 */
	private function assert_valid_class_style_id( string $style_id ): void {
		$styles = $this->load_registered_styles();

		if ( ! array_key_exists( $style_id, $styles ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( 'Unknown class style ID: ' . $style_id );
		}

		$style = $styles[ $style_id ];
		if ( ! is_array( $style ) || ! isset( $style['type'] ) || ! is_string( $style['type'] ) || 'class' !== $style['type'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( 'Class property style IDs must reference styles with type "class": ' . $style_id );
		}
	}

	/**
	 * Load Etch style registry from WordPress option storage.
	 *
	 * @return array<string, mixed>
	 */
	private function load_registered_styles(): array {
		$styles = array();

		$persisted_styles = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( is_array( $persisted_styles ) ) {
			$styles = $persisted_styles;
		}

		$in_memory_styles = Style::registered_styles();
		foreach ( $in_memory_styles as $style_id => $style ) {
			$styles[ $style_id ] = $style;
		}

		return $styles;
	}
}
