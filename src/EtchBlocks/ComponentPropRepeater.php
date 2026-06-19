<?php
/**
 * Fluent repeater prop value builder for etch/component instance props.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use stdClass;

/**
 * Builds repeater-shaped Etch component prop payloads.
 */
final class ComponentPropRepeater implements ComponentPropValueInterface {

	/**
	 * Repeater items.
	 *
	 * @var array<int, array<int|string, mixed>|ComponentPropGroup|stdClass>
	 */
	private array $items = array();

	/**
	 * Create a new repeater prop builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Append one repeater item.
	 *
	 * @param array<int|string, mixed>|ComponentPropGroup|stdClass $item Repeater item.
	 * @throws InvalidArgumentException When item is a list array, including an empty array.
	 */
	public function item( array|ComponentPropGroup|stdClass $item ): self {
		if ( is_array( $item ) && array_is_list( $item ) ) {
			throw new InvalidArgumentException( 'Component repeater items must be associative arrays.' );
		}

		$this->items[] = $item;
		return $this;
	}

	/**
	 * Return the unencoded repeater items.
	 *
	 * @return array<int, array<int|string, mixed>|ComponentPropGroup|stdClass>
	 */
	public function to_array(): array {
		return $this->items;
	}

	/**
	 * Encode the repeater payload.
	 */
	public function encode(): string {
		return ComponentPropValueEncoder::repeater( $this->items );
	}
}
