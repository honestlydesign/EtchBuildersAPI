<?php
/**
 * Type-safe builder for etch/raw-html block.
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
 * Builds etch/raw-html block with consistent fluent API.
 *
 * Pattern:
 *   RawHtmlBlock::new()
 *     ->content('<div>Raw HTML</div>')
 *     ->to_block();
 *
 * Raw HTML blocks are always self-closing and always render as escaped content.
 * The Etch `unsafe` rendering flag is intentionally never serialized here; raw
 * HTML is treated as untrusted by default and should be used only for narrow,
 * trusted fragments that cannot be expressed with the typed builders.
 */
final class RawHtmlBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;

	/**
	 * HTML content.
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
	 * Create a new RawHtmlBlock builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the HTML content.
	 *
	 * @param string $content The HTML content.
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
		$block_attrs = array_merge(
			array( 'content' => $this->content ),
			$this->base->to_array()
		);

		return Block::new_self_closing( 'raw-html', $block_attrs );
	}
}
