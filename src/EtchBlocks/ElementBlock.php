<?php
/**
 * Type-safe builder for etch/element block.
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
 * Builds etch/element block with consistent fluent API.
 *
 * Pattern:
 *   ElementBlock::new()
 *     ->tag('div')
 *     ->attribute('class', 'my-div')
 *     ->style('style-1')
 *     ->child($childBlock)
 *     ->to_block();
 */
final class ElementBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasClassAndStyleAttributes;
	use HasChildren;

	/**
	 * Native Etch wrapper constants used by pattern builders.
	 */
	private const ETCH_SECTION_TAG             = 'section';
	private const ETCH_CONTAINER_TAG           = 'div';
	private const ETCH_SECTION_ELEMENT         = 'section';
	private const ETCH_CONTAINER_ELEMENT       = 'container';
	private const ETCH_SECTION_METADATA_NAME   = 'Section';
	private const ETCH_CONTAINER_METADATA_NAME = 'Container';
	private const ETCH_SECTION_STYLE_ID        = 'etch-section-style';
	private const ETCH_CONTAINER_STYLE_ID      = 'etch-container-style';

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
	 * Block editor metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata = array();

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
	 * Create a new ElementBlock builder.
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
	 * Set block metadata at the Gutenberg block level.
	 *
	 * @param array<string, mixed> $metadata Metadata payload.
	 */
	public function metadata( array $metadata ): self {
		$this->metadata = $metadata;
		return $this;
	}

	/**
	 * Set a metadata name for editor-facing block labels.
	 *
	 * @param string $name Editor-facing block name.
	 */
	public function metadata_name( string $name ): self {
		$this->metadata['name'] = $name;
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
	 * Configure this block as an Etch-native section wrapper.
	 *
	 * @param string $tag  HTML tag name. Defaults to 'section'.
	 * @param string $name Editor-facing metadata name. Defaults to 'Section'.
	 */
	public function is_etch_section( string $tag = self::ETCH_SECTION_TAG, string $name = self::ETCH_SECTION_METADATA_NAME ): self {
		$this->tag( $tag );
		$this->attribute( 'data-etch-element', self::ETCH_SECTION_ELEMENT );
		$this->metadata_name( $name );
		$this->ensure_style( self::ETCH_SECTION_STYLE_ID );

		return $this;
	}

	/**
	 * Configure this block as an Etch-native section container wrapper.
	 */
	public function is_etch_section_container(): self {
		$this->tag( self::ETCH_CONTAINER_TAG );
		$this->attribute( 'data-etch-element', self::ETCH_CONTAINER_ELEMENT );
		$this->metadata_name( self::ETCH_CONTAINER_METADATA_NAME );
		$this->ensure_style( self::ETCH_CONTAINER_STYLE_ID );

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
			throw new InvalidArgumentException( 'ElementBlock requires a tag. Use ->tag() before to_block().' );
		}

		$block_attrs = array_merge(
			array(
				'tag'        => $this->tag,
				'attributes' => $this->attributes->to_array(),
			),
			$this->base->to_array()
		);

		if ( array() !== $this->metadata ) {
			$block_attrs['metadata'] = $this->metadata;
		}

		if ( array() !== $this->styles ) {
			$block_attrs['styles'] = $this->styles;
		}

		$block = Block::new( 'element', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}

	/**
	 * Add a style only once.
	 *
	 * @param string $style_id Style ID to add.
	 */
	private function ensure_style( string $style_id ): void {
		if ( ! in_array( $style_id, $this->styles, true ) ) {
			$this->styles[] = $style_id;
		}
	}
}
