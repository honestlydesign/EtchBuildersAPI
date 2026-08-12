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
use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\ClassToken;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\PatternUse;

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
	 * Structured sibling sequence.
	 *
	 * @var BlockSequence
	 */
	private BlockSequence $blocks;

	/**
	 * Raw serialized block markup.
	 *
	 * @var string
	 */
	private string $markup = '';

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->blocks = BlockSequence::new();
	}

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

		$this->mode = self::MODE_BLOCKS;
		$this->blocks->append( $block );

		return $this;
	}

	/**
	 * Append one typed sibling sequence.
	 *
	 * @param BlockSequence $sequence Ordered typed blocks.
	 * @throws InvalidArgumentException When raw markup mode is already active.
	 */
	public function sequence( BlockSequence $sequence ): self {
		if ( self::MODE_MARKUP === $this->mode ) {
			throw new InvalidArgumentException( 'Content builder cannot mix blocks_markup() with sequence().' );
		}

		if ( $sequence->is_empty() ) {
			throw new InvalidArgumentException( 'Content builder sequence requires at least one block.' );
		}

		$this->mode = self::MODE_BLOCKS;
		$this->blocks->append_sequence( $sequence );

		return $this;
	}

	/**
	 * Append one registered Pattern Use as typed content.
	 */
	public function pattern_use( PatternUse $pattern_use ): self {
		if ( self::MODE_MARKUP === $this->mode ) {
			throw new InvalidArgumentException( 'Content builder cannot mix blocks_markup() with pattern_use().' );
		}

		$this->mode = self::MODE_BLOCKS;
		$this->blocks->append( $pattern_use );

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

		if ( self::MODE_BLOCKS === $this->mode && ! $this->blocks->is_empty() ) {
			return $this->blocks->to_markup();
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
		return $this->blocks->class_tokens();
	}

	/**
	 * Return registered Pattern dependencies retained by structured content.
	 *
	 * @return array<int, PatternUse>
	 */
	public function pattern_uses(): array {
		return $this->blocks->pattern_uses();
	}
}
