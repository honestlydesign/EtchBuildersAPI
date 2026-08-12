<?php
/**
 * Canonical candidate observation for Contract Lab compatibility checks.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Separates candidate evidence from the semantic payload used for comparison.
 */
final class ContractLabCandidateObservation {

	/**
	 * @param array<string, mixed>                          $record
	 * @param array<int, ContractLabIntegrationOutcome>     $outcomes
	 */
	private function __construct(
		private readonly array $record,
		private readonly ContractLabCandidateObservationSchema $schema,
		private readonly array $outcomes
	) {
	}

	/**
	 * Normalize one disposable candidate observation.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record, ?ContractLabCandidateObservationSchema $schema = null ): self {
		$schema     = $schema ?? ContractLabCandidateObservationSchema::current();
		$normalized = $schema->normalize( $record );

		/** @var array<string, mixed> $runtime_shape_record */
		$runtime_shape_record = $normalized['runtime_shape'];
		$runtime_shape        = ContractLabRuntimeShapeObservation::from_array( $runtime_shape_record );

		/** @var array<int, mixed> $outcome_records */
		$outcome_records = $normalized['integration_outcomes'];
		$outcomes        = array();
		$seen_names      = array();
		foreach ( $outcome_records as $outcome_record ) {
			if ( ! is_array( $outcome_record ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab integration outcomes must be records.' );
			}
			$outcome = ContractLabIntegrationOutcome::from_array( $outcome_record );
			if ( isset( $seen_names[ $outcome->name() ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab candidate contains duplicate integration outcome "%s".', $outcome->name() ) );
			}
			$seen_names[ $outcome->name() ] = true;
			$outcomes[] = $outcome;
		}

		$canonical_outcomes = array_map(
			static fn ( ContractLabIntegrationOutcome $outcome ): array => $outcome->to_array(),
			$outcomes
		);
		$canonical = array(
			'candidate_version'    => $normalized['candidate_version'],
			'schema_version'       => $normalized['schema_version'],
			'contract_version'     => $normalized['contract_version'],
			'metadata'             => ImmutableArray::copy( $normalized['metadata'], 'Contract Lab candidate metadata must contain only persisted data.' ),
			'runtime_shape'        => $runtime_shape->to_array(),
			'integration_outcomes' => $canonical_outcomes,
		);

		return new self( $canonical, $schema, $outcomes );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->record;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function semantic_projection(): array {
		return $this->schema->semantic_projection( $this->record );
	}

	public function schema(): ContractLabCandidateObservationSchema {
		return $this->schema;
	}

	/**
	 * @return array<int, ContractLabIntegrationOutcome>
	 */
	public function integration_outcomes(): array {
		return $this->outcomes;
	}
}
