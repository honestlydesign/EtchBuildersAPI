<?php
/**
 * Type-safe builder for etch/dynamic-image block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasClassAndStyleAttributes;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Types\Attributes;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/dynamic-image block with consistent fluent API.
 *
 * Pattern:
 *   DynamicImageBlock::new()
 *     ->attribute('src', '{props.image}')
 *     ->attribute('alt', '{props.alt}')
 *     ->style('responsive')
 *     ->to_block();
 *
 * DynamicImageBlock is always self-closing (img tag).
 */
final class DynamicImageBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasClassAndStyleAttributes;

	/**
	 * Image tag constant.
	 */
	private const IMG_TAG = 'img';

	/**
	 * Image attributes.
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
	 * Constructor.
	 */
	private function __construct() {
		$this->attributes = Attributes::new();
		$this->base       = BlockBase::new();
	}

	/**
	 * Create a new DynamicImageBlock builder.
	 *
	 * Tag is automatically set to 'img'.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Add a single image attribute.
	 *
	 * @param string      $name  Attribute name.
	 * @param string|null $value Attribute value.
	 */
	public function attribute( string $name, ?string $value ): self {
		$this->set_attribute_value( $name, $value );
		return $this;
	}

	/**
	 * Set all image attributes at once.
	 *
	 * @param Attributes $attrs Attributes to set.
	 */
	public function attributes( Attributes $attrs ): self {
		$this->attributes = $attrs;
		return $this;
	}

	/**
	 * Set the WordPress media attachment ID or dynamic media ID expression.
	 *
	 * @param int|string $media_id Media attachment ID or dynamic expression.
	 * @throws InvalidArgumentException When media ID is empty.
	 */
	public function media_id( int|string $media_id ): self {
		$value = trim( (string) $media_id );

		if ( '' === $value ) {
			throw new InvalidArgumentException( 'Dynamic image media ID cannot be empty.' );
		}

		$this->attributes->add( 'mediaId', $value );
		return $this;
	}

	/**
	 * Set whether the media-library render path should output srcset attributes.
	 *
	 * @param bool $use_srcset Whether to output srcset attributes.
	 */
	public function use_srcset( bool $use_srcset ): self {
		$this->attributes->add( 'useSrcSet', $use_srcset );
		return $this;
	}

	/**
	 * Set the largest WordPress image size to use for the media-library render path.
	 *
	 * @param string $maximum_size WordPress image size name.
	 * @throws InvalidArgumentException When maximum size is empty.
	 */
	public function maximum_size( string $maximum_size ): self {
		$maximum_size = trim( $maximum_size );

		if ( '' === $maximum_size ) {
			throw new InvalidArgumentException( 'Dynamic image maximum size cannot be empty.' );
		}

		$this->attributes->add( 'maximumSize', $maximum_size );
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
	 */
	public function to_block(): Block {
		$block_attrs = array_merge(
			array(
				'tag'        => self::IMG_TAG,
				'attributes' => $this->attributes->to_array(),
			),
			$this->base->to_array()
		);

		if ( array() !== $this->styles ) {
			$block_attrs['styles'] = $this->styles;
		}

		return Block::new_self_closing( 'dynamic-image', $block_attrs );
	}
}
