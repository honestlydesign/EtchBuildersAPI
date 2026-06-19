<?php
/**
 * Type-safe builder for etch/slot-placeholder block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/slot-placeholder block with consistent fluent API.
 *
 * Pattern:
 *   SlotPlaceholderBlock::new()
 *     ->name('default')
 *     ->to_block();
 *
 * Slot placeholders are always self-closing.
 */
final class SlotPlaceholderBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;

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
	 * Constructor.
	 */
	private function __construct() {
		$this->base = BlockBase::new();
	}

	/**
	 * Create a new SlotPlaceholderBlock builder.
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

		return Block::new_self_closing( 'slot-placeholder', $block_attrs );
	}
}
