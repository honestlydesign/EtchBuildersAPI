<?php
/**
 * Type-safe builder for etch/slot-content block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasChildren;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/slot-content block with consistent fluent API.
 *
 * Pattern:
 *   SlotContentBlock::new()
 *     ->name('default')
 *     ->child($contentBlock)
 *     ->to_block();
 */
final class SlotContentBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasChildren;

	/**
	 * Slot name.
	 *
	 * @var string
	 */
	private string $name = '';

	/**
	 * Base block attributes.
	 *
	 * @var BlockBase
	 */
	private BlockBase $base;

	/**
	 * Child blocks.
	 *
	 * @var array<int, Block>
	 */
	private array $children = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->base = BlockBase::new();
	}

	/**
	 * Create a new SlotContentBlock builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the slot name.
	 *
	 * @param string $name The slot name.
	 */
	public function name( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$block_attrs = array_merge(
			array( 'name' => $this->name ),
			$this->base->to_array()
		);

		$block = Block::new( 'slot-content', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
