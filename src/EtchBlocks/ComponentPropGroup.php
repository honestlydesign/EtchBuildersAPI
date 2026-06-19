<?php
/**
 * Fluent group prop value builder for etch/component instance props.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

/**
 * Builds group-shaped Etch component prop payloads.
 */
final class ComponentPropGroup implements ComponentPropValueInterface {

	/**
	 * Group payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload = array();

	/**
	 * Create a new group prop builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set a string value.
	 *
	 * @param string $key Property key.
	 * @param string $value Property value.
	 */
	public function string( string $key, string $value ): self {
		$this->payload[ $key ] = $value;
		return $this;
	}

	/**
	 * Set a boolean value.
	 *
	 * @param string $key Property key.
	 * @param bool   $value Property value.
	 */
	public function boolean( string $key, bool $value ): self {
		$this->payload[ $key ] = $value;
		return $this;
	}

	/**
	 * Set an expression value.
	 *
	 * @param string $key Property key.
	 * @param string $expression Expression without surrounding braces.
	 */
	public function expression( string $key, string $expression ): self {
		$this->payload[ $key ] = ComponentPropValueEncoder::expression( $expression );
		return $this;
	}

	/**
	 * Set a class value.
	 *
	 * @param string             $key Property key.
	 * @param array<int, string> $class_names Class names or style IDs.
	 */
	public function class( string $key, array $class_names ): self {
		$this->payload[ $key ] = ComponentPropValueEncoder::class( $class_names );
		return $this;
	}

	/**
	 * Set an array value.
	 *
	 * @param string             $key Property key.
	 * @param ComponentPropArray $prop_array Array value.
	 */
	public function array( string $key, ComponentPropArray $prop_array ): self {
		$this->payload[ $key ] = $prop_array;
		return $this;
	}

	/**
	 * Set a nested group value.
	 *
	 * @param string             $key Property key.
	 * @param ComponentPropGroup $group Group value.
	 */
	public function group( string $key, ComponentPropGroup $group ): self {
		$this->payload[ $key ] = $group;
		return $this;
	}

	/**
	 * Set a wrapped object value.
	 *
	 * Primitive object props need the same wrapped payload format as group props
	 * so Etch hydrates authored JSON correctly.
	 *
	 * @param string                                           $key Property key.
	 * @param array<string, mixed>|array<int, mixed>|\stdClass $value Object-like value.
	 */
	public function object( string $key, array|\stdClass $value ): self {
		$this->payload[ $key ] = ComponentPropValueEncoder::group( (array) $value );
		return $this;
	}

	/**
	 * Set a repeater value.
	 *
	 * @param string                $key Property key.
	 * @param ComponentPropRepeater $repeater Repeater value.
	 */
	public function repeater( string $key, ComponentPropRepeater $repeater ): self {
		$this->payload[ $key ] = $repeater;
		return $this;
	}

	/**
	 * Set a raw payload value.
	 *
	 * @param string $key Property key.
	 * @param mixed  $value Raw value.
	 */
	public function value( string $key, mixed $value ): self {
		$this->payload[ $key ] = $value;
		return $this;
	}

	/**
	 * Return the unencoded payload array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->payload;
	}

	/**
	 * Encode the group payload.
	 */
	public function encode(): string {
		return ComponentPropValueEncoder::group( $this->payload );
	}
}
