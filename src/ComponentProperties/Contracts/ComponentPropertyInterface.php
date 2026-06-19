<?php
/**
 * Component property builder contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Contracts;

use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Contract for fluent Etch component property builders.
 *
 * This interface defines the API for building component properties
 * with a fluent, type-safe interface.
 */
interface ComponentPropertyInterface {

	/**
	 * Returns the human-readable property name.
	 */
	public function get_name(): string;

	/**
	 * Returns the property key used in props.* expressions.
	 */
	public function get_key(): string;

	/**
	 * Returns the primitive type for the property.
	 */
	public function get_primitive(): PropertyPrimitive;

	/**
	 * Set the human-readable property name.
	 *
	 * @param string $name Property display name.
	 */
	public function name( string $name ): self;

	/**
	 * Set the property key used in props.* expressions.
	 *
	 * @param string $key Property key (camelCase recommended).
	 */
	public function key( string $key ): self;

	/**
	 * Set the key touched flag.
	 *
	 * @param bool $touched Whether the key has been touched.
	 */
	public function key_touched( bool $touched ): self;

	/**
	 * Set the source identifier for inherited props.
	 *
	 * @param string $id Source identifier.
	 */
	public function prop_source_id( string $id ): self;

	/**
	 * Set the property description.
	 *
	 * @param string $description Property description.
	 */
	public function description( string $description ): self;

	/**
	 * Set the default value for the property.
	 *
	 * @param mixed $value Default value.
	 */
	public function default( mixed $value ): self;

	/**
	 * Convert the property to Etch component schema array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array;
}
