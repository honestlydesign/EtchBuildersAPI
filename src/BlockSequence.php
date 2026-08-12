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

	/**
	 * Registered pattern dependencies expanded into this sequence.
	 *
	 * @var array<int, PatternUse>
	 */
	private array $pattern_uses = array();

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
	 * @param Block|EtchBlockBuilderInterface|PatternUse $item Typed item.
	 */
	public function append( Block|EtchBlockBuilderInterface|PatternUse $item ): self {
		return $this->append_many( array( $item ) );
	}

	/**
	 * Append multiple typed blocks or builders.
	 *
	 * @param array<int, mixed> $items Ordered items; each value must be typed.
	 */
	public function append_many( array $items ): self {
		$detached_blocks = array();
		$pattern_uses    = array();
		foreach ( $items as $item ) {
			if ( $item instanceof PatternUse ) {
				$used_sequence = $item->sequence();
				$detached_blocks = array_merge( $detached_blocks, $used_sequence->to_blocks() );
				$pattern_uses[]  = $item;
				$pattern_uses    = array_merge( $pattern_uses, $used_sequence->pattern_uses() );
				continue;
			}

			if ( ! ( $item instanceof Block ) && ! ( $item instanceof EtchBlockBuilderInterface ) ) {
				throw new InvalidArgumentException( 'BlockSequence expects only Block, typed block-builder, or PatternUse instances.' );
			}

			$block             = $item instanceof EtchBlockBuilderInterface ? $item->to_block() : $item;
			$detached_blocks[] = $block->detached_copy();
		}

		$this->blocks = array_merge( $this->blocks, $detached_blocks );
		$this->pattern_uses = array_merge( $this->pattern_uses, $pattern_uses );

		return $this;
	}

	/**
	 * Append another typed sequence while preserving its dependency metadata.
	 */
	public function append_sequence( self $sequence ): self {
		if ( $sequence->is_empty() ) {
			throw new InvalidArgumentException( 'BlockSequence cannot append an empty sequence.' );
		}

		$this->blocks        = array_merge( $this->blocks, $sequence->to_blocks() );
		$this->pattern_uses  = array_merge( $this->pattern_uses, $sequence->pattern_uses() );

		return $this;
	}

	/**
	 * Return a detached sequence copy, including dependency metadata.
	 */
	public function copy(): self {
		$copy               = new self();
		$copy->blocks       = $this->to_blocks();
		$copy->pattern_uses = $this->pattern_uses;

		return $copy;
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

	/**
	 * Return checked raw fragments attached throughout the sequence.
	 *
	 * @return array<int, RawFragment>
	 */
	public function raw_fragments(): array {
		$fragments = array();
		foreach ( $this->blocks as $block ) {
			$fragments = array_merge( $fragments, $block->children_raw_fragments() );
		}

		return $fragments;
	}

	/**
	 * Return registered Pattern dependencies in expansion order.
	 *
	 * @return array<int, PatternUse>
	 */
	public function pattern_uses(): array {
		return $this->pattern_uses;
	}
}
