<?php
/**
 * Shared content buffer for Page and Template builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Content;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\ClassToken;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;

/**
 * Accumulates page/template content from structured blocks or raw markup.
 */
final class ContentBuffer {

	/**
	 * Structured block mode.
	 */
	private const MODE_BLOCKS = 'blocks';

	/**
	 * Raw markup mode.
	 */
	private const MODE_MARKUP = 'markup';

	/**
	 * Active content mode.
	 *
	 * @var string|null
	 */
	private ?string $mode = null;

	/**
	 * Structured blocks.
	 *
	 * @var array<int, Block>
	 */
	private array $blocks = array();

	/**
	 * Raw serialized block markup.
	 *
	 * @var string
	 */
	private string $markup = '';

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Create a new content buffer.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Append a structured block.
	 *
	 * @param Block|EtchBlockBuilderInterface $block Block or block builder.
	 * @throws InvalidArgumentException When raw markup mode is already active.
	 */
	public function block( Block|EtchBlockBuilderInterface $block ): self {
		if ( self::MODE_MARKUP === $this->mode ) {
			throw new InvalidArgumentException( 'Content builder cannot mix blocks_markup() with block().' );
		}

		$this->mode     = self::MODE_BLOCKS;
		$this->blocks[] = $block instanceof EtchBlockBuilderInterface ? $block->to_block() : $block;

		return $this;
	}

	/**
	 * Set serialized Gutenberg markup.
	 *
	 * @param string $markup Serialized markup.
	 * @throws InvalidArgumentException When structured block mode is already active or markup is empty.
	 */
	public function blocks_markup( string $markup ): self {
		if ( self::MODE_BLOCKS === $this->mode ) {
			throw new InvalidArgumentException( 'Content builder cannot mix block() with blocks_markup().' );
		}

		if ( '' === trim( $markup ) ) {
			throw new InvalidArgumentException( 'blocks_markup() requires non-empty markup.' );
		}

		$this->mode   = self::MODE_MARKUP;
		$this->markup = $markup;

		return $this;
	}

	/**
	 * Render buffered content as serialized Gutenberg markup.
	 *
	 * @throws InvalidArgumentException When content is empty.
	 */
	public function to_markup(): string {
		if ( self::MODE_MARKUP === $this->mode ) {
			return $this->markup;
		}

		if ( self::MODE_BLOCKS === $this->mode && array() !== $this->blocks ) {
			$markup = '';

			foreach ( $this->blocks as $block ) {
				$markup .= $block->to_string();
			}

			return $markup;
		}

		throw new InvalidArgumentException( 'Content builder requires non-empty content.' );
	}

	/**
	 * Return explicit class declarations retained by structured blocks.
	 *
	 * Raw markup intentionally remains unclassified.
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
