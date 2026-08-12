<?php
/**
 * Curated routing prose for generated Authoring reference material.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Holds methodology only; API facts and snippets are generated elsewhere.
 */
final class AuthoringReferenceMethodology {

	/**
	 * @param array<int, mixed> $sections
	 */
	private function __construct(
		private readonly string $title,
		private readonly array $sections
	) {
	}

	/**
	 * Create routing prose that cannot contain hand-authored API snippets.
	 *
	 * @param array<int, mixed> $sections
	 */
	public static function new( string $title, array $sections ): self {
		$title = trim( $title );
		if ( '' === $title || ! array_is_list( $sections ) || array() === $sections ) {
			throw new InvalidArgumentException( 'Authoring reference methodology requires a title and ordered sections.' );
		}

		$normalized = array();
		foreach ( $sections as $section ) {
			if ( ! is_array( $section ) || array( 'body', 'heading' ) !== self::sorted_keys( $section ) ) {
				throw new InvalidArgumentException( 'Authoring reference methodology sections must contain exactly heading and body.' );
			}

			$heading = $section['heading'];
			$body    = $section['body'];
			if ( ! is_string( $heading ) || ! is_string( $body ) || '' === trim( $heading ) || '' === trim( $body ) ) {
				throw new InvalidArgumentException( 'Authoring reference methodology headings and bodies must be non-empty strings.' );
			}
			self::assert_routing_prose( $heading );
			self::assert_routing_prose( $body );
			$normalized[] = array(
				'heading' => trim( $heading ),
				'body'    => trim( $body ),
			);
		}

		return new self( $title, $normalized );
	}

	/**
	 * Rehydrate the canonical methodology projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		if ( array( 'sections', 'title' ) !== self::sorted_keys( $record ) ) {
			throw new InvalidArgumentException( 'Authoring reference methodology must contain exactly title and sections.' );
		}
		if ( ! is_string( $record['title'] ) || ! is_array( $record['sections'] ) ) {
			throw new InvalidArgumentException( 'Authoring reference methodology has invalid field shapes.' );
		}

		/** @var array<int, array{heading: string, body: string}> $sections */
		$sections = $record['sections'];
		$methodology = self::new( $record['title'], $sections );
		if ( $methodology->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring reference methodology must be canonical.' );
		}

		return $methodology;
	}

	public function title(): string {
		return $this->title;
	}

	/**
	 * @return array<int, array{heading: string, body: string}>
	 */
	public function sections(): array {
		return ImmutableArray::copy( $this->sections, 'Authoring reference methodology must contain scalar values.' );
	}

	/**
	 * @return array{title: string, sections: array<int, array{heading: string, body: string}>}
	 */
	public function to_array(): array {
		return array(
			'title'    => $this->title,
			'sections' => $this->sections(),
		);
	}

	/**
	 * Render only the curated methodology portion of a generated page.
	 */
	public function to_markdown(): string {
		$markdown = '## ' . $this->title . "\n\n";
		foreach ( $this->sections as $section ) {
			$markdown .= '### ' . $section['heading'] . "\n\n" . $section['body'] . "\n\n";
		}

		return $markdown;
	}

	private static function assert_routing_prose( string $text ): void {
		if ( str_contains( $text, '`' ) || str_contains( $text, '::' ) || str_contains( $text, '->' ) ) {
			throw new InvalidArgumentException( 'Authoring reference methodology must not contain API syntax; generated facts and snippets own that surface.' );
		}
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return array<int, string>
	 */
	private static function sorted_keys( array $record ): array {
		$keys = array_keys( $record );
		sort( $keys );

		return $keys;
	}
}
