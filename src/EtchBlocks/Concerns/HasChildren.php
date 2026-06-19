<?php
/**
 * Trait providing child management methods for Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks\Concerns;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\RawHtmlBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Provides child block management methods for fluent Etch block builders.
 *
 * Usage:
 *   class MyBlock implements EtchBlockBuilderInterface {
 *       use HasChildren;
 *
 *       private array $children = array();
 *   }
 *
 * Note: The consuming class MUST have a private array $children property.
 */
trait HasChildren {

	/**
	 * Add a single child block.
	 *
	 * @param Block $child Child block to add.
	 * @return static
	 */
	public function child( Block $child ): static {
		$this->children[] = $child;
		return $this;
	}

	/**
	 * Add inline text content as a child.
	 *
	 * @param string $text Text content to add.
	 * @return static
	 */
	public function content( string $text ): static {
		$this->children[] = TextBlock::new()->content( $text )->to_block();
		return $this;
	}

	/**
	 * Add inline raw HTML content as a child.
	 *
	 * @param string $content Raw HTML content to add.
	 * @return static
	 */
	public function raw_content( string $content ): static {
		$this->children[] = RawHtmlBlock::new()->content( $content )->to_block();
		return $this;
	}

	/**
	 * Add multiple child blocks at once.
	 *
	 * @param array<int, Block> $children Child blocks to add.
	 * @return static
	 * @throws InvalidArgumentException When non-Block child is provided.
	 */
	public function children( array $children ): static {
		foreach ( $children as $child ) {
			if ( ! ( $child instanceof Block ) ) {
				throw new InvalidArgumentException( 'children() expects Block instances.' );
			}
			$this->children[] = $child;
		}
		return $this;
	}
}
