<?php
/**
 * Canonical generated readable Authoring reference material.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Stores the exact projection and rendered Markdown emitted by the generator.
 */
final class AuthoringReferenceMaterial {

	/**
	 * @param array<string, mixed> $record
	 */
	private function __construct( private readonly array $record ) {
	}

	/**
	 * Rehydrate and validate a canonical generated projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$record = ImmutableArray::copy( $record, 'Authoring reference material must contain only scalar or nested array values.' );
		self::assert_exact_keys(
			$record,
			array( 'metadata', 'methodology', 'capabilities', 'components', 'recipes', 'diagnostics', 'links', 'markdown' ),
			'Authoring reference material'
		);

		if ( ! is_array( $record['metadata'] ) || ! is_array( $record['methodology'] ) || ! is_array( $record['capabilities'] ) || ! is_array( $record['components'] ) || ! is_array( $record['recipes'] ) || ! is_array( $record['diagnostics'] ) || ! is_array( $record['links'] ) || ! is_string( $record['markdown'] ) || '' === trim( $record['markdown'] ) ) {
			throw new InvalidArgumentException( 'Authoring reference material has invalid field shapes.' );
		}

		self::assert_metadata( $record['metadata'] );
		AuthoringReferenceMethodology::from_array( $record['methodology'] );
		self::assert_list( $record['capabilities'], 'capabilities' );
		self::assert_list( $record['components'], 'components' );
		self::assert_list( $record['recipes'], 'recipes' );
		self::assert_list( $record['diagnostics'], 'diagnostics' );
		self::assert_links( $record['links'], $record['markdown'] );

		return new self( $record );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy( $this->record, 'Authoring reference material must contain only scalar or nested array values.' );
	}

	public function markdown(): string {
		return $this->record['markdown'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		/** @var array<string, mixed> $metadata */
		$metadata = $this->record['metadata'];

		return ImmutableArray::copy( $metadata, 'Authoring reference metadata must contain scalar values.' );
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	private static function assert_metadata( array $metadata ): void {
		self::assert_exact_keys(
			$metadata,
			array(
				'reference_schema_version',
				'catalog_schema_version',
				'catalog_contract_version',
				'package_version',
				'source_digest',
				'recipe_schema_version',
				'recipe_versions',
				'recipe_digest',
				'component_digest',
			),
			'Authoring reference metadata'
		);
		foreach ( array( 'reference_schema_version', 'catalog_schema_version', 'catalog_contract_version', 'package_version', 'source_digest', 'recipe_schema_version', 'recipe_digest', 'component_digest' ) as $key ) {
			if ( ! is_string( $metadata[ $key ] ) || '' === trim( $metadata[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring reference metadata field "%s" must be a non-empty string.', $key ) );
			}
		}
		if ( ! is_array( $metadata['recipe_versions'] ) || ! array_is_list( $metadata['recipe_versions'] ) || array() === $metadata['recipe_versions'] ) {
			throw new InvalidArgumentException( 'Authoring reference metadata recipe_versions must be a non-empty list.' );
		}
		foreach ( $metadata['recipe_versions'] as $version ) {
			if ( ! is_string( $version ) || '' === trim( $version ) ) {
				throw new InvalidArgumentException( 'Authoring reference metadata recipe_versions must contain non-empty strings.' );
			}
		}
	}

	/**
	 * @param array<int, mixed> $records
	 */
	private static function assert_list( array $records, string $label ): void {
		if ( ! array_is_list( $records ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring reference %s must be ordered lists.', $label ) );
		}
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring reference %s must contain object records.', $label ) );
			}
		}
	}

	/**
	 * @param array<int, mixed> $links
	 */
	private static function assert_links( array $links, string $markdown ): void {
		if ( ! array_is_list( $links ) ) {
			throw new InvalidArgumentException( 'Authoring reference links must be an ordered list.' );
		}
		$ids = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				throw new InvalidArgumentException( 'Authoring reference links must contain object records.' );
			}
			self::assert_exact_keys( $link, array( 'id', 'kind', 'target' ), 'Authoring reference link' );
			if ( ! is_string( $link['id'] ) || ! is_string( $link['kind'] ) || ! is_string( $link['target'] ) || '' === trim( $link['id'] ) || '' === trim( $link['kind'] ) || 1 !== preg_match( '/^#[a-z0-9-]+$/D', $link['target'] ) ) {
				throw new InvalidArgumentException( 'Authoring reference links must contain stable IDs, kinds, and internal targets.' );
			}
			if ( isset( $ids[ $link['id'] ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring reference links have duplicate ID "%s".', $link['id'] ) );
			}
			$ids[ $link['id'] ] = true;
			if ( ! str_contains( $markdown, 'id="' . substr( $link['target'], 1 ) . '"' ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring reference link target "%s" is missing from Markdown.', $link['target'] ) );
			}
		}
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $expected
	 */
	private static function assert_exact_keys( array $record, array $expected, string $label ): void {
		$actual = array_keys( $record );
		$left   = $actual;
		$right  = $expected;
		sort( $left );
		sort( $right );
		if ( $left !== $right ) {
			throw new InvalidArgumentException( sprintf( '%s must contain exactly its canonical fields.', $label ) );
		}
	}
}
