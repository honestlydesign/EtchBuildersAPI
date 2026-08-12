<?php
/**
 * Immutable catalog of curated Authoring Capability declarations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Validates intent references and exposes deterministic capability projections.
 */
final class AuthoringCapabilityCatalog {

	/**
	 * @var array<int, AuthoringCapability>
	 */
	private readonly array $capabilities;

	/**
	 * @var array<string, AuthoringCapability>
	 */
	private readonly array $capabilities_by_id;

	private function __construct( array $capabilities ) {
		$by_id = array();
		foreach ( $capabilities as $capability ) {
			if ( isset( $by_id[ $capability->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring Capability Catalog has duplicate capability ID "%s".', $capability->id() ) );
			}

			$by_id[ $capability->id() ] = $capability;
		}

		$this->capabilities       = array_values( $capabilities );
		$this->capabilities_by_id = $by_id;
	}

	/**
	 * Build a catalog from ordered declarations and closed external references.
	 */
	public static function from_declarations(
		AuthoringCapabilityReferenceIndex $references,
		AuthoringCapability ...$capabilities
	): self {
		$catalog = new self( array_values( $capabilities ) );

		foreach ( $catalog->capabilities as $capability ) {
			foreach ( $capability->prerequisite_ids() as $prerequisite_id ) {
				if ( ! isset( $catalog->capabilities_by_id[ $prerequisite_id ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring capability "%s" references unknown prerequisite capability ID "%s".', $capability->id(), $prerequisite_id )
					);
				}

				if ( $capability->id() === $prerequisite_id ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring capability "%s" cannot require itself.', $capability->id() )
					);
				}
			}

			foreach ( $capability->recipe_ids() as $recipe_id ) {
				if ( ! $references->has_recipe( $recipe_id ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" references unknown recipe ID "%s".', $capability->id(), $recipe_id ) );
				}
			}

			foreach ( $capability->diagnostic_ids() as $diagnostic_id ) {
				if ( ! $references->has_diagnostic( $diagnostic_id ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" references unknown diagnostic ID "%s".', $capability->id(), $diagnostic_id ) );
				}
			}

			foreach ( $capability->evidence_ids() as $evidence_id ) {
				if ( ! $references->has_evidence( $evidence_id ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" references unknown evidence ID "%s".', $capability->id(), $evidence_id ) );
				}
			}

		}

		return $catalog;
	}

	/**
	 * Create an empty catalog.
	 */
	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * Rehydrate a canonical declaration projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record, ?AuthoringCapabilityReferenceIndex $references = null ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'capabilities' ) !== $keys ) {
			throw new InvalidArgumentException( 'Accepted authoring capability catalog must contain exactly capabilities.' );
		}

		$records = $record['capabilities'];
		if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
			throw new InvalidArgumentException( 'Accepted authoring capability catalog capabilities must be a list.' );
		}

		$capabilities = array();
		foreach ( $records as $capability_record ) {
			if ( ! is_array( $capability_record ) ) {
				throw new InvalidArgumentException( 'Accepted authoring capability catalog entries must be object records.' );
			}

			$capabilities[] = AuthoringCapability::from_array( $capability_record );
		}

		$catalog = self::from_declarations( $references ?? AuthoringCapabilityReferenceIndex::empty(), ...$capabilities );
		if ( $catalog->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Accepted authoring capability catalog must be a canonical model projection.' );
		}

		return $catalog;
	}

	public function has( string $id ): bool {
		return isset( $this->capabilities_by_id[ $id ] );
	}

	/**
	 * Require one exact capability ID.
	 */
	public function capability( string $id ): AuthoringCapability {
		if ( ! isset( $this->capabilities_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring Capability Catalog has no capability ID "%s".', $id ) );
		}

		return $this->capabilities_by_id[ $id ];
	}

	/**
	 * @return array<int, AuthoringCapability>
	 */
	public function all(): array {
		return $this->capabilities;
	}

	/**
	 * @return array{capabilities: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'capabilities' => array_map(
				static fn ( AuthoringCapability $capability ): array => $capability->to_array(),
				$this->capabilities
			),
		);
	}
}
