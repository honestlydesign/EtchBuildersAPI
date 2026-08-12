<?php
/**
 * Typed ordered sequence of sibling Etch blocks.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;

/**
 * Keeps sibling composition structural until a caller explicitly serializes it.
 */
final class BlockSequence {

	/**
	 * Detached structural blocks in insertion order.
	 *
	 * @var array<int, Block>
	 */
	private array $blocks = array();

	private function __construct() {
	}

	/**
	 * Create an empty typed sequence.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Create a sequence from typed blocks or block builders.
	 *
	 * @param array<int, mixed> $items Ordered items; each value must be typed.
	 */
	public static function from( array $items ): self {
		$sequence = new self();
		return $sequence->append_many( $items );
	}

	/**
	 * Append one typed block or builder and detach its current structure.
	 *
	 * @param Block|EtchBlockBuilderInterface $item Typed item.
	 */
	public function append( Block|EtchBlockBuilderInterface $item ): self {
		return $this->append_many( array( $item ) );
	}

	/**
	 * Append multiple typed blocks or builders.
	 *
	 * @param array<int, mixed> $items Ordered items; each value must be typed.
	 */
	public function append_many( array $items ): self {
		$detached_blocks = array();
		foreach ( $items as $item ) {
			if ( ! ( $item instanceof Block ) && ! ( $item instanceof EtchBlockBuilderInterface ) ) {
				throw new InvalidArgumentException( 'BlockSequence expects only Block or typed block-builder instances.' );
			}

			$block             = $item instanceof EtchBlockBuilderInterface ? $item->to_block() : $item;
			$detached_blocks[] = $block->detached_copy();
		}

		$this->blocks = array_merge( $this->blocks, $detached_blocks );

		return $this;
	}

	/**
	 * Whether the sequence contains no sibling blocks.
	 */
	public function is_empty(): bool {
		return array() === $this->blocks;
	}

	/**
	 * Return a detached copy of every block in insertion order.
	 *
	 * @return array<int, Block>
	 */
	public function to_blocks(): array {
		$blocks = array();
		foreach ( $this->blocks as $block ) {
			$blocks[] = $block->detached_copy();
		}

		return $blocks;
	}

	/**
	 * Serialize the sequence at an explicit markup boundary.
	 *
	 * @throws InvalidArgumentException When the sequence is empty.
	 */
	public function to_markup(): string {
		if ( $this->is_empty() ) {
			throw new InvalidArgumentException( 'BlockSequence requires at least one block before serialization.' );
		}

		$markup = '';
		foreach ( $this->blocks as $block ) {
			$markup .= $block->to_string();
		}

		return $markup;
	}

	/**
	 * Return explicit non-wire class declarations from the complete sequence.
	 *
	 * @return array<int, ClassToken>
	 */
	public function class_tokens(): array {
		$class_tokens = array();
		foreach ( $this->blocks as $block ) {
			$class_tokens = array_merge( $class_tokens, $block->class_tokens_in_tree() );
		}

		return $class_tokens;
	}
}
