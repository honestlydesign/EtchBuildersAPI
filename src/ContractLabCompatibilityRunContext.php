<?php
/**
 * Runtime identity captured for one Contract Lab compatibility run.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Supplies the identity and environment facts written by a reviewed action.
 */
final class ContractLabCompatibilityRunContext {

	public const CONTEXT_VERSION = '1';

	/**
	 * @param array<string, mixed> $environment
	 */
	private function __construct(
		private readonly string $record_id,
		private readonly string $builder_contract_version,
		private readonly string $builder_source_revision,
		private readonly string $etch_release,
		private readonly string $artifact_fingerprint,
		private readonly array $environment
	) {
	}

	/**
	 * @param array<string, mixed> $environment
	 */
	public static function from_values(
		string $record_id,
		string $builder_contract_version,
		string $builder_source_revision,
		string $etch_release,
		string $artifact_fingerprint,
		array $environment
	): self {
		AcyclicArrayGuard::assert_acyclic( $environment );
		try {
			ContractLabManifestSafety::assert_stable_id( $record_id, 'Contract Lab compatibility run record ID' );
			ContractLabVersionConstraint::assert_version( $builder_contract_version, 'Contract Lab compatibility run Builder contract version' );
			ContractLabVersionConstraint::assert_version( $etch_release, 'Contract Lab compatibility run Etch release' );
			ContractLabManifestSafety::assert_digest( $artifact_fingerprint, 'Contract Lab compatibility run artifact fingerprint' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/D', $builder_source_revision ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility run Builder source revision must be a hexadecimal commit identifier.' );
		}

		return new self(
			$record_id,
			$builder_contract_version,
			$builder_source_revision,
			$etch_release,
			$artifact_fingerprint,
			ImmutableArray::copy( $environment, 'Contract Lab compatibility run environment must contain only persisted data.' )
		);
	}

	public function record_id(): string {
		return $this->record_id;
	}

	public function builder_contract_version(): string {
		return $this->builder_contract_version;
	}

	public function builder_source_revision(): string {
		return $this->builder_source_revision;
	}

	public function etch_release(): string {
		return $this->etch_release;
	}

	public function artifact_fingerprint(): string {
		return $this->artifact_fingerprint;
	}

	/**
	 * Create the reviewed ledger record for this run.
	 */
	public function ledger_record( ContractLabSnapshot $snapshot, ContractLabCompatibilityReview $review ): ContractLabCompatibilityLedgerRecord {
		$review_record = $review->to_array();
		/** @var array<int, array<string, mixed>> $evidence */
		$evidence = $review_record['evidence'];

		return ContractLabCompatibilityLedgerRecord::from_snapshot(
			$this->record_id,
			$this->builder_contract_version,
			$this->builder_source_revision,
			$this->etch_release,
			$this->artifact_fingerprint,
			$this->environment,
			$review->classification(),
			$snapshot,
			$evidence,
			$review
		);
	}
}
