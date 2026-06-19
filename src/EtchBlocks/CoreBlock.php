<?php
/**
 * Type-safe builder for WordPress core Gutenberg blocks.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;

/**
 * Builds serialized markup for WordPress core blocks (wp:* comments).
 *
 * Pattern:
 *   ElementBlock::new()
 *     ->tag( 'main' )
 *     ->child( CoreBlock::post_content()->to_block() )
 *     ->to_block();
 */
final class CoreBlock implements EtchBlockBuilderInterface {

	/**
	 * Core block name without the core/ namespace (e.g. post-content).
	 */
	private string $name;

	/**
	 * Block attributes.
	 *
	 * @var array<string, mixed>
	 */
	private array $attributes;

	/**
	 * Whether the block is self-closing.
	 */
	private bool $self_closing;

	/**
	 * Create a core block builder.
	 *
	 * @param string               $name         Core block name (with or without core/ prefix).
	 * @param array<string, mixed> $attributes   Block attributes.
	 * @param bool                 $self_closing Whether the block is self-closing.
	 */
	private function __construct( string $name, array $attributes, bool $self_closing ) {
		$this->name           = $name;
		$this->attributes     = $attributes;
		$this->self_closing   = $self_closing;
	}

	/**
	 * WordPress post-content block for template inner content areas.
	 *
	 * Renders: <!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->
	 *
	 * @param string $align       Block align attribute.
	 * @param string $layout_type Layout type inside the layout object.
	 */
	public static function post_content( string $align = 'full', string $layout_type = 'default' ): self {
		return new self(
			'post-content',
			array(
				'align'  => $align,
				'layout' => array(
					'type' => $layout_type,
				),
			),
			true
		);
	}

	/**
	 * Build and return the Block instance.
	 */
	public function to_block(): Block {
		return Block::new_core( $this->name, $this->attributes, $this->self_closing );
	}
}
