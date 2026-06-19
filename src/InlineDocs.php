<?php
/**
 * Shared inline docs builder for Etch component guidance.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;

/**
 * Builds shared inline docs markup from regular Etch blocks.
 */
final class InlineDocs {
	/**
	 * Root wrapper style ID.
	 *
	 * @var string
	 */
	private const ROOT_STYLE_ID = 'omide-inline-docs';

	/**
	 * Inline docs callouts.
	 *
	 * @var array<int, InlineDocsCallout>
	 */
	private array $callouts = array();

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create a new inline docs builder.
	 *
	 * @return self
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Attach shared inline docs styles to a component.
	 *
	 * @param Component $component Component to extend.
	 * @return Component
	 */
	public static function add_to_component( Component $component ): Component {
		$styles_parser = StylesParser::new( self::styles_path() );

		foreach ( $styles_parser->get_all() as $style ) {
			$component->add_style( $style );
		}

		return $component;
	}

	/**
	 * Add a callout to the inline docs content.
	 *
	 * @param InlineDocsCallout $callout Callout builder.
	 * @return self
	 */
	public function callout( InlineDocsCallout $callout ): self {
		$this->callouts[] = $callout;
		return $this;
	}

	/**
	 * Convert docs to Etch blocks.
	 *
	 * @return array<int, Block>
	 */
	public function to_blocks(): array {
		if ( array() === $this->callouts ) {
			return array();
		}

		$callout_blocks = array();
		foreach ( $this->callouts as $callout ) {
			$callout_blocks[] = $callout->to_block();
		}

		$root_block = ElementBlock::new()
			->tag( 'div' )
			->attribute( 'data-omide-inline-docs', '' )
			->style( self::ROOT_STYLE_ID )
			->children( $callout_blocks )
			->to_block();

		return array( $root_block );
	}

	/**
	 * Serialize docs blocks into Gutenberg markup.
	 *
	 * @return string
	 */
	public function to_string(): string {
		$markup = '';

		foreach ( $this->to_blocks() as $block ) {
			$markup .= $block->to_string();
		}

		return $markup;
	}

	/**
	 * Resolve shared inline docs styles path.
	 *
	 * @return string
	 */
	private static function styles_path(): string {
		return __DIR__ . '/InlineDocs/styles.css';
	}
}
