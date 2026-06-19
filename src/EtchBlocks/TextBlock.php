<?php
/**
 * Type-safe builder for etch/text block.
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
 * Builds etch/text block with consistent fluent API.
 *
 * Pattern:
 *   TextBlock::new()
 *     ->content('Hello World')
 *     ->hidden(true)
 *     ->script('my-script', 'code')
 *     ->to_block();
 */
final class TextBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;

	/**
	 * Text content.
	 *
	 * @var string
	 */
	private string $content = '';

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
	 * Create a new TextBlock builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set text content.
	 *
	 * @param string $content The text content.
	 */
	public function content( string $content ): self {
		$this->content = $content;
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$attributes = array_merge(
			array( 'content' => $this->content ),
			$this->base->to_array()
		);

		return Block::new_self_closing( 'text', $attributes );
	}
}
