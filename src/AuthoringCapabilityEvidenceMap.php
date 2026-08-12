<?php
/**
 * Validated evidence admission map for Authoring Capabilities.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Joins capability declarations, evidence links, and runtime applicability.
 */
final class AuthoringCapabilityEvidenceMap {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private readonly array $assessments;

	/**
	 * @param array<string, array<string, mixed>> $assessments
	 */
	private function __construct(
		private readonly AuthoringCapabilityEvidenceRequirementCatalog $requirements,
		private readonly AuthoringCapabilityEvidenceCatalog $evidence,
		array $assessments
	) {
		$this->assessments = $assessments;
	}

	/**
	 * Validate the complete evidence map against capability references and statuses.
	 */
	public static function from_catalogs(
		AuthoringCapabilityCatalog $capabilities,
		AuthoringCapabilityEvidenceRequirementCatalog $requirements,
		AuthoringCapabilityEvidenceCatalog $evidence
	): self {
		$capability_ids = array();
		foreach ( $capabilities->all() as $capability ) {
			$capability_ids[ $capability->id() ] = true;
			if ( ! $requirements->has( $capability->id() ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" has no evidence requirement.', $capability->id() ) );
			}
		}

		foreach ( $requirements->all() as $requirement ) {
			if ( ! isset( $capability_ids[ $requirement->capability_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring evidence requirement for unknown capability "%s" is orphaned.', $requirement->capability_id() ) );
			}
		}

		$referenced_evidence_ids = array();
		$assessments             = array();
		foreach ( $capabilities->all() as $capability ) {
			$requirement       = $requirements->for_capability( $capability->id() );
			$required_kinds     = $requirement->required_kinds();
			$present_kinds      = array();
			$present_kind_values = array();

			foreach ( $capability->evidence_ids() as $evidence_id ) {
				if ( ! $evidence->has( $evidence_id ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring capability "%s" references missing evidence ID "%s".', $capability->id(), $evidence_id )
					);
				}

				$record = $evidence->evidence( $evidence_id );
				if ( $record->capability_id() !== $capability->id() ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring evidence ID "%s" contradicts capability ownership.', $evidence_id )
					);
				}

				if ( ! in_array( $record->kind(), $required_kinds, true ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring evidence ID "%s" has kind "%s" that is not required for capability "%s".', $evidence_id, $record->kind()->value, $capability->id() )
					);
				}

				$referenced_evidence_ids[ $evidence_id ] = true;
				if ( ! in_array( $record->kind(), $present_kinds, true ) ) {
					$present_kinds[]       = $record->kind();
					$present_kind_values[] = $record->kind()->value;
				}
			}

			$missing_kinds       = array();
			$missing_kind_values = array();
			foreach ( $required_kinds as $required_kind ) {
				if ( ! in_array( $required_kind, $present_kinds, true ) ) {
					$missing_kinds[]       = $required_kind;
					$missing_kind_values[] = $required_kind->value;
				}
			}

			if ( $capability->status()->is_admitted() && array() !== $missing_kinds ) {
				throw new InvalidArgumentException(
					sprintf( 'Admitted authoring capability "%s" is missing %s evidence.', $capability->id(), implode( ', ', $missing_kind_values ) )
				);
			}

			if ( $capability->status()->is_pending() && array() === $missing_kinds ) {
				throw new InvalidArgumentException( sprintf( 'Pending authoring capability "%s" has no missing evidence.', $capability->id() ) );
			}

			$assessments[ $capability->id() ] = array(
				'capability_id'          => $capability->id(),
				'status'                 => $capability->status()->value,
				'status_reason'          => $capability->status_reason(),
				'required_evidence_kinds' => array_map(
					static fn ( AuthoringCapabilityEvidenceKind $kind ): string => $kind->value,
					$required_kinds
				),
				'present_evidence_kinds' => $present_kind_values,
				'missing_evidence_kinds' => $missing_kind_values,
			);
		}

		foreach ( $evidence->all() as $record ) {
			if ( ! isset( $referenced_evidence_ids[ $record->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring evidence ID "%s" is an orphan evidence record.', $record->id() ) );
			}
		}

		return new self( $requirements, $evidence, $assessments );
	}

	/**
	 * Rehydrate and revalidate a canonical map projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record, AuthoringCapabilityCatalog $capabilities ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'assessments', 'evidence', 'requirements' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring evidence map must contain exactly assessments, evidence, and requirements.' );
		}

		$assessments = $record['assessments'];
		if ( ! is_array( $assessments ) || ! array_is_list( $assessments ) ) {
			throw new InvalidArgumentException( 'Authoring evidence map assessments must be a list.' );
		}
		if ( ! is_array( $record['evidence'] ) || ! is_array( $record['requirements'] ) ) {
			throw new InvalidArgumentException( 'Authoring evidence map catalogs must be object records.' );
		}

		$map = self::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_array( $record['requirements'] ),
			AuthoringCapabilityEvidenceCatalog::from_array( $record['evidence'] )
		);
		if ( $map->assessments_array() !== $assessments ) {
			throw new InvalidArgumentException( 'Authoring evidence map assessments are stale or contradictory.' );
		}

		return $map;
	}

	/**
	 * @return array<int, string>
	 */
	public function missing_evidence_kinds( string $capability_id ): array {
		return $this->assessment( $capability_id )['missing_evidence_kinds'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assessment_for( string $capability_id ): array {
		return $this->assessment( $capability_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'requirements' => $this->requirements->to_array(),
			'evidence'     => $this->evidence->to_array(),
			'assessments'  => $this->assessments_array(),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function assessments_array(): array {
		return array_values( $this->assessments );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function assessment( string $capability_id ): array {
		if ( ! isset( $this->assessments[ $capability_id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring evidence map has no capability ID "%s".', $capability_id ) );
		}

		return $this->assessments[ $capability_id ];
	}
}
