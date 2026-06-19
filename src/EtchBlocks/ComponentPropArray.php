<?php
/**
 * Fluent array prop value builder for etch/component instance props.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use stdClass;

/**
 * Builds array-shaped Etch component prop payloads.
 */
final class ComponentPropArray implements ComponentPropValueInterface {

	/**
	 * Array items.
	 *
	 * @var array<int, mixed>
	 */
	private array $items = array();

	/**
	 * Create a new array prop builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Append a raw item.
	 *
	 * @param mixed $item Array item.
	 */
	public function item( mixed $item ): self {
		$this->items[] = $item;
		return $this;
	}

	/**
	 * Append a string item.
	 *
	 * @param string $value Item value.
	 */
	public function string( string $value ): self {
		return $this->item( $value );
	}

	/**
	 * Append a boolean item.
	 *
	 * @param bool $value Item value.
	 */
	public function boolean( bool $value ): self {
		return $this->item( $value );
	}

	/**
	 * Append an expression item.
	 *
	 * @param string $expression Expression without surrounding braces.
	 */
	public function expression( string $expression ): self {
		return $this->item( ComponentPropValueEncoder::expression( $expression ) );
	}

	/**
	 * Append an object/group item.
	 *
	 * @param array<string, mixed>|ComponentPropGroup|stdClass $item Item value.
	 */
	public function object( array|ComponentPropGroup|stdClass $item ): self {
		return $this->item( $item );
	}

	/**
	 * Return the unencoded items.
	 *
	 * @return array<int, mixed>
	 */
	public function to_array(): array {
		return $this->items;
	}

	/**
	 * Encode the array payload.
	 */
	public function encode(): string {
		return ComponentPropValueEncoder::array( $this->items );
	}
}
