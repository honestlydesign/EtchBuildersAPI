<?php
/**
 * Inline docs callout builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Builds a single inline docs callout.
 *
 * @phpstan-type CalloutEntry array{type: 'paragraph', text: string}|array{type: 'bullets', items: array<int, string>}
 */
final class InlineDocsCallout {

	/**
	 * Callout tone.
	 *
	 * @var string
	 */
	private string $tone = '';

	/**
	 * Callout title.
	 *
	 * @var string
	 */
	private string $title = '';

	/**
	 * Ordered callout content entries.
	 *
	 * @var array<int, CalloutEntry>
	 */
	private array $entries = array();

	/**
	 * Private constructor.
	 *
	 * @param string $tone Tone.
	 * @param string $title Title.
	 */
	private function __construct( string $tone, string $title ) {
		$this->tone  = $tone;
		$this->title = $title;
	}

	/**
	 * Create an info callout.
	 *
	 * @param string $title Callout title.
	 * @return self
	 */
	public static function info( string $title ): self {
		return new self( 'info', $title );
	}

	/**
	 * Create a warning callout.
	 *
	 * @param string $title Callout title.
	 * @return self
	 */
	public static function warning( string $title ): self {
		return new self( 'warning', $title );
	}

	/**
	 * Add a paragraph entry.
	 *
	 * @param string $text Paragraph text.
	 * @return self
	 */
	public function paragraph( string $text ): self {
		$this->entries[] = array(
			'type' => 'paragraph',
			'text' => $text,
		);

		return $this;
	}

	/**
	 * Add a bullet list entry.
	 *
	 * @param array<int, string> $items Bullet item text.
	 * @return self
	 * @throws InvalidArgumentException When a bullet item is invalid.
	 */
	public function bullets( array $items ): self {
		$normalized_items = array();

		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				throw new InvalidArgumentException( 'InlineDocsCallout::bullets expects an array of strings.' );
			}

			$normalized_items[] = $item;
		}

		$this->entries[] = array(
			'type'  => 'bullets',
			'items' => $normalized_items,
		);

		return $this;
	}

	/**
	 * Convert the callout to an Etch block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$children = array(
			ElementBlock::new()
				->tag( 'p' )
				->attribute( 'data-omide-inline-docs-title', '' )
				->style( 'omide-inline-docs__title' )
				->child(
					TextBlock::new()
						->content( $this->title )
						->to_block()
				)
				->to_block(),
		);

		foreach ( $this->entries as $entry ) {
			if ( 'paragraph' === $entry['type'] ) {
				$children[] = ElementBlock::new()
					->tag( 'p' )
					->attribute( 'data-omide-inline-docs-body', '' )
					->style( 'omide-inline-docs__body' )
					->child(
						TextBlock::new()
							->content( $entry['text'] )
							->to_block()
					)
					->to_block();
				continue;
			}

			$list_items = array();
			foreach ( $entry['items'] as $item ) {
				$list_items[] = ElementBlock::new()
					->tag( 'li' )
					->attribute( 'data-omide-inline-docs-list-item', '' )
					->style( 'omide-inline-docs__list-item' )
					->child(
						TextBlock::new()
							->content( $item )
							->to_block()
					)
					->to_block();
			}

			$children[] = ElementBlock::new()
				->tag( 'ul' )
				->attribute( 'data-omide-inline-docs-list', '' )
				->style( 'omide-inline-docs__list' )
				->children( $list_items )
				->to_block();
		}

		return ElementBlock::new()
			->tag( 'div' )
			->attribute( 'data-omide-inline-docs-callout', '' )
			->attribute( 'data-omide-inline-docs-tone', $this->tone )
			->styles(
				array(
					'omide-inline-docs__callout',
					'omide-inline-docs__callout-' . $this->tone,
				)
			)
			->children( $children )
			->to_block();
	}
}
