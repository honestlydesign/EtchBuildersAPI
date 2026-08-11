<?php
/**
 * Class property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\ClassPropertyDefaultValue;
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
	 * Validated default and its exact identity proofs.
	 */
	private ?ClassPropertyDefaultValue $class_default = null;

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
		$this->set_class_default( ClassPropertyDefaultValue::from( $value ) );
		return $this;
	}

	/**
	 * Set a typed ordered class-style default.
	 *
	 * @param ClassStyleSet $classes Validated ordered class-style references.
	 * @throws InvalidArgumentException When a reference no longer identifies the same style.
	 */
	public function default_classes( ClassStyleSet $classes ): self {
		$this->set_class_default( ClassPropertyDefaultValue::from( $classes ) );
		return $this;
	}

	/**
	 * Append a single default class style ID.
	 *
	 * @param string $style_id Default class style ID.
	 * @throws InvalidArgumentException When style ID is invalid.
	 */
	public function default_style_id( string $style_id ): self {
		$this->assert_class_default_is_current();

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
	 * Convert the property to an Etch schema after revalidating style identity.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$this->assert_class_default_is_current();
		return parent::to_array();
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
	 * Store a validated class default and its unchanged opaque IDs.
	 *
	 * @param ClassPropertyDefaultValue $default Validated default value.
	 */
	private function set_class_default( ClassPropertyDefaultValue $default ): void {
		$this->class_default = $default;
		$this->default_value = $default->ids();
		$this->has_default   = true;
	}

	/**
	 * Revalidate all remembered references at the final serialization boundary.
	 */
	private function assert_class_default_is_current(): void {
		if ( null !== $this->class_default ) {
			$this->class_default->assert_current();
		}
	}
}
