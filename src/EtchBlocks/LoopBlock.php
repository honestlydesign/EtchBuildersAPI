<?php
/**
 * Type-safe builder for etch/loop block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasChildren;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\LoopPreset;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/loop block with consistent fluent API.
 *
 * Pattern:
 *   LoopBlock::new()
 *     ->target('parent.categories')
 *     ->item_id('item')
 *     ->param('count', 5)
 *     ->child($templateBlock)
 *     ->to_block();
 */
final class LoopBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasChildren;

	/**
	 * Loop target expression.
	 *
	 * @var string|null
	 */
	private ?string $target = null;

	/**
	 * Item identifier variable name.
	 *
	 * @var string|null
	 */
	private ?string $item_id = null;

	/**
	 * Index identifier variable name.
	 *
	 * @var string|null
	 */
	private ?string $index_id = null;

	/**
	 * Loop identifier.
	 *
	 * @var string|null
	 */
	private ?string $loop_id = null;

	/**
	 * Loop parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $loop_params = array();

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
	 * Private constructor.
	 */
	private function __construct() {
		$this->base = BlockBase::new();
	}

	/**
	 * Create a new LoopBlock builder.
	 *
	 * @return self
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the loop target expression.
	 *
	 * Examples: 'parent.categories', 'item.children', 'myJson($arg1: 2)'
	 *
	 * @param string $target The loop target expression.
	 * @return self
	 */
	public function target( string $target ): self {
		$this->target = $target;
		return $this;
	}

	/**
	 * Set the item identifier variable name.
	 *
	 * Default is typically 'item'.
	 *
	 * @param string $item_id The item identifier.
	 * @return self
	 */
	public function item_id( string $item_id ): self {
		$this->item_id = $item_id;
		return $this;
	}

	/**
	 * Set the index identifier variable name.
	 *
	 * Default is typically 'index'.
	 *
	 * @param string $index_id The index identifier.
	 * @return self
	 */
	public function index_id( string $index_id ): self {
		$this->index_id = $index_id;
		return $this;
	}

	/**
	 * Set the loop identifier.
	 *
	 * Validates that the key matches a registered LoopPreset. Use target() for
	 * raw dynamic expressions or built-in handler ids (main-query, etc.) which
	 * are not validated.
	 *
	 * @param string $loop_id The loop preset key.
	 * @return self
	 * @throws InvalidArgumentException When the key is not a registered LoopPreset key.
	 */
	public function loop_id( string $loop_id ): self {
		if ( ! LoopPreset::is_registered_key( $loop_id ) ) {
			$known = LoopPreset::registered_keys();
			$hint  = array() === $known ? ' (no presets registered)' : ' Known: ' . implode( ', ', $known ) . '.';
			throw new InvalidArgumentException(
				sprintf( 'LoopBlock::loop_id("%s") — no LoopPreset registered with key "%s".%s', $loop_id, $loop_id, $hint )
			);
		}

		$this->loop_id = $loop_id;
		return $this;
	}

	/**
	 * Add a single loop parameter.
	 *
	 * @param string $key   Parameter name.
	 * @param mixed  $value Parameter value.
	 * @return self
	 */
	public function param( string $key, mixed $value ): self {
		$this->loop_params[ $key ] = $value;
		return $this;
	}

	/**
	 * Add multiple loop parameters at once.
	 *
	 * @param array<string, mixed> $params Parameters to add.
	 * @return self
	 * @throws InvalidArgumentException When non-string key is provided.
	 */
	public function params( array $params ): self {
		foreach ( $params as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Loop parameter keys must be strings.' );
			}
			$this->loop_params[ $key ] = $value;
		}
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$block_attrs = array_merge(
			array( 'target' => $this->target ?? '' ),
			$this->base->to_array()
		);

		if ( null !== $this->item_id ) {
			$block_attrs['itemId'] = $this->item_id;
		}

		if ( null !== $this->index_id ) {
			$block_attrs['indexId'] = $this->index_id;
		}

		if ( null !== $this->loop_id ) {
			$block_attrs['loopId'] = $this->loop_id;
		}

		if ( array() !== $this->loop_params ) {
			$block_attrs['loopParams'] = $this->loop_params;
		}

		$block = Block::new( 'loop', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
