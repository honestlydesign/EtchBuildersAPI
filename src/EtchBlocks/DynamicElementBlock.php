<?php
/**
 * Type-safe builder for etch/dynamic-element block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasClassAndStyleAttributes;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasChildren;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Types\Attributes;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/dynamic-element block with consistent fluent API.
 *
 * Pattern:
 *   DynamicElementBlock::new()
 *     ->tag('button')
 *     ->attribute('class', 'my-btn')
 *     ->child($contentBlock)
 *     ->to_block();
 *
 * DynamicElementBlock is like ElementBlock but for dynamic tag rendering.
 */
final class DynamicElementBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasClassAndStyleAttributes;
	use HasChildren;

	/**
	 * HTML tag name.
	 *
	 * @var string
	 */
	private string $tag = '';

	/**
	 * HTML attributes.
	 *
	 * @var Attributes
	 */
	private Attributes $attributes;

	/**
	 * Style IDs.
	 *
	 * @var array<int, string>
	 */
	private array $styles = array();

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
		$this->attributes = Attributes::new();
		$this->base       = BlockBase::new();
	}

	/**
	 * Create a new DynamicElementBlock builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the HTML tag (required).
	 *
	 * @param string $tag The HTML tag name.
	 */
	public function tag( string $tag ): self {
		$this->tag = $tag;
		return $this;
	}

	/**
	 * Add a single attribute.
	 *
	 * @param string      $name  Attribute name.
	 * @param string|null $value Attribute value.
	 */
	public function attribute( string $name, ?string $value ): self {
		$this->set_attribute_value( $name, $value );
		return $this;
	}

	/**
	 * Set all attributes at once.
	 *
	 * @param Attributes $attrs Attributes to set.
	 */
	public function attributes( Attributes $attrs ): self {
		$this->set_attributes_value( $attrs );
		return $this;
	}

	/**
	 * Add a style ID.
	 *
	 * @param string $style_id Style ID to add.
	 */
	public function style( string $style_id ): self {
		$this->styles[] = $style_id;
		$this->sync_standalone_class_style_linkage();
		return $this;
	}

	/**
	 * Add multiple style IDs at once.
	 *
	 * @param array<int, string> $style_ids Style IDs to add.
	 * @throws InvalidArgumentException When non-string style ID is provided.
	 */
	public function styles( array $style_ids ): self {
		foreach ( $style_ids as $style_id ) {
			if ( ! is_string( $style_id ) ) {
				throw new InvalidArgumentException( 'Style IDs must be strings.' );
			}
			$this->styles[] = $style_id;
		}
		$this->sync_standalone_class_style_linkage();
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 * @throws InvalidArgumentException When tag is not set.
	 */
	public function to_block(): Block {
		if ( '' === $this->tag ) {
			throw new InvalidArgumentException( 'DynamicElementBlock requires a tag. Use ->tag() before to_block().' );
		}

		$block_attrs = array_merge(
			array(
				'tag'        => $this->tag,
				'attributes' => $this->attributes->to_array(),
			),
			$this->base->to_array()
		);

		if ( array() !== $this->styles ) {
			$block_attrs['styles'] = $this->styles;
		}

		$block = Block::new( 'dynamic-element', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
