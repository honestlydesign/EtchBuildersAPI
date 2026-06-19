<?php
/**
 * Type-safe builder for etch/svg block.
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
 * Builds etch/svg block with consistent fluent API.
 *
 * Pattern:
 *   SvgBlock::new()
 *     ->attribute('viewBox', '0 0 24 24')
 *     ->attribute('width', '24')
 *     ->style('svg-style')
 *     ->child($pathBlock)
 *     ->to_block();
 *
 * SvgBlock extends ElementBlock functionality but with tag='svg'.
 */
final class SvgBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasClassAndStyleAttributes;
	use HasChildren;

	/**
	 * SVG tag constant.
	 */
	private const SVG_TAG = 'svg';

	/**
	 * Placeholder SVG source used by pattern generation.
	 */
	private const IDE_ETCH_PLACEHOLDER_SRC = 'data:image/svg+xml,%3Csvg xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22 viewBox%3D%220 0 64 64%22%3E%3Crect width%3D%2264%22 height%3D%2264%22 rx%3D%2214%22 fill%3D%22%23141c2f%22%2F%3E%3Cpath d%3D%22M16 20h32v6H16zM16 29h22v6H16zM16 38h32v6H16z%22 fill%3D%22%23ffffff%22%2F%3E%3C%2Fsvg%3E';

	/**
	 * Default editor-facing metadata name.
	 */
	private const SVG_METADATA_NAME = 'SVG';

	/**
	 * SVG attributes.
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
	 * Create a new SvgBlock builder.
	 *
	 * Tag is automatically set to 'svg'.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Add a single SVG attribute.
	 *
	 * @param string      $name  Attribute name.
	 * @param string|null $value Attribute value.
	 */
	public function attribute( string $name, ?string $value ): self {
		$this->set_attribute_value( $name, $value );
		return $this;
	}

	/**
	 * Set all SVG attributes at once.
	 *
	 * @param Attributes $attrs Attributes to set.
	 */
	public function attributes( Attributes $attrs ): self {
		$this->attributes = $attrs;
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
	 * Set an editor-facing metadata name.
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
	 * Configure this SVG block to use the Oh My IDE Etch placeholder icon.
	 */
	public function is_ide_etch_placeholder(): self {
		$this->metadata_name( self::SVG_METADATA_NAME );
		$this->attribute( 'src', self::IDE_ETCH_PLACEHOLDER_SRC );

		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$block_attrs = array_merge(
			array(
				'tag'        => self::SVG_TAG,
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

		$block = Block::new( 'svg', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
