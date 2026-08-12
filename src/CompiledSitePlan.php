<?php
/**
 * Immutable no-write result of compiling a Site Definition.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Carries all resolved plan sections without performing WordPress writes.
 */
final class CompiledSitePlan {

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<int, CompiledSiteResource>    $styles
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private function __construct(
		private readonly array $entities,
		private readonly array $dependencies,
		private readonly array $styles,
		private readonly array $assets,
		private readonly array $ownership,
		private readonly array $diagnostics
	) {
	}

	/**
	 * Create a plan from fully typed sections.
	 *
	 * Construction validates section types and duplicate identities, but does
	 * not resolve references, inspect WordPress, or persist any section.
	 *
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<int, CompiledSiteResource>    $styles
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	public static function from_sections(
		array $entities = array(),
		array $dependencies = array(),
		array $styles = array(),
		array $assets = array(),
		array $ownership = array(),
		array $diagnostics = array()
	): self {
		self::assert_list_of( $entities, CompiledSiteEntity::class, 'entities' );
		self::assert_list_of( $dependencies, CompiledSiteDependency::class, 'dependencies' );
		self::assert_list_of( $styles, CompiledSiteResource::class, 'styles' );
		self::assert_list_of( $assets, CompiledSiteResource::class, 'assets' );
		self::assert_list_of( $ownership, CompiledSiteOwnership::class, 'ownership' );
		self::assert_list_of( $diagnostics, CompiledSiteDiagnostic::class, 'diagnostics' );

		foreach ( $styles as $style ) {
			if ( CompiledSiteResourceType::STYLE !== $style->type() ) {
				throw new InvalidArgumentException( 'Compiled Site plan styles must contain STYLE resources.' );
			}
		}
		foreach ( $assets as $asset ) {
			if ( CompiledSiteResourceType::ASSET !== $asset->type() ) {
				throw new InvalidArgumentException( 'Compiled Site plan assets must contain ASSET resources.' );
			}
		}

		self::assert_unique(
			array_map( static fn ( CompiledSiteEntity $entity ): string => $entity->identity(), $entities ),
			'entity'
		);
		self::assert_unique(
			array_map( static fn ( CompiledSiteResource $resource ): string => $resource->identity(), $styles ),
			'style'
		);
		self::assert_unique(
			array_map( static fn ( CompiledSiteResource $resource ): string => $resource->identity(), $assets ),
			'asset'
		);
		self::assert_unique(
			array_map(
				static fn ( CompiledSiteDependency $dependency ): string => $dependency->consumer_identity() . '>' . $dependency->dependency_identity() . ':' . $dependency->kind(),
				$dependencies
			),
			'dependency edge'
		);
		self::assert_unique(
			array_map(
				static fn ( CompiledSiteOwnership $record ): string => $record->owner_identity() . '>' . $record->resource_identity() . ':' . $record->role(),
				$ownership
			),
			'ownership edge'
		);

		return new self(
			array_values( $entities ),
			array_values( $dependencies ),
			array_values( $styles ),
			array_values( $assets ),
			array_values( $ownership ),
			array_values( $diagnostics )
		);
	}

	/**
	 * Create an empty plan.
	 */
	public static function empty(): self {
		return self::from_sections();
	}

	/**
	 * @return array<int, CompiledSiteEntity>
	 */
	public function entities(): array {
		return $this->entities;
	}

	/**
	 * @return array<int, string>
	 */
	public function resolved_identities(): array {
		return array_map( static fn ( CompiledSiteEntity $entity ): string => $entity->identity(), $this->entities );
	}

	/**
	 * @return array<int, CompiledSiteDependency>
	 */
	public function dependencies(): array {
		return $this->dependencies;
	}

	/**
	 * @return array<int, CompiledSiteResource>
	 */
	public function styles(): array {
		return $this->styles;
	}

	/**
	 * @return array<int, CompiledSiteResource>
	 */
	public function assets(): array {
		return $this->assets;
	}

	/**
	 * @return array<int, CompiledSiteOwnership>
	 */
	public function ownership(): array {
		return $this->ownership;
	}

	/**
	 * @return array<int, CompiledSiteDiagnostic>
	 */
	public function diagnostics(): array {
		return $this->diagnostics;
	}

	/**
	 * Whether any diagnostic blocks plan application.
	 */
	public function has_errors(): bool {
		foreach ( $this->diagnostics as $diagnostic ) {
			if ( CompiledSiteDiagnosticSeverity::ERROR === $diagnostic->severity() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deterministic machine-readable plan projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'entities'      => array_map( static fn ( CompiledSiteEntity $item ): array => $item->to_array(), $this->entities ),
			'identities'     => $this->resolved_identities(),
			'dependencies'   => array_map( static fn ( CompiledSiteDependency $item ): array => $item->to_array(), $this->dependencies ),
			'styles'         => array_map( static fn ( CompiledSiteResource $item ): array => $item->to_array(), $this->styles ),
			'assets'         => array_map( static fn ( CompiledSiteResource $item ): array => $item->to_array(), $this->assets ),
			'ownership'      => array_map( static fn ( CompiledSiteOwnership $item ): array => $item->to_array(), $this->ownership ),
			'diagnostics'    => array_map( static fn ( CompiledSiteDiagnostic $item ): array => $item->to_array(), $this->diagnostics ),
		);
	}

	/**
	 * @param array<int, mixed> $values
	 */
	private static function assert_list_of( array $values, string $class, string $section ): void {
		if ( ! array_is_list( $values ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site plan %s must be a list.', $section ) );
		}

		foreach ( $values as $value ) {
			if ( ! $value instanceof $class ) {
				throw new InvalidArgumentException( sprintf( 'Compiled Site plan %s must contain %s values.', $section, $class ) );
			}
		}
	}

	/**
	 * @param array<int, string> $identities
	 */
	private static function assert_unique( array $identities, string $section ): void {
		$seen = array();
		foreach ( $identities as $identity ) {
			if ( isset( $seen[ $identity ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Compiled Site plan has duplicate %s identity "%s".', $section, $identity ) );
			}
			$seen[ $identity ] = true;
		}
	}
}
