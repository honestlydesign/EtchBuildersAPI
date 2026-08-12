<?php
/**
 * Explicit read/check/classify/accept boundaries for Contract Lab evidence.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Coordinates immutable values; only accept/classify return a new ledger.
 */
final class ContractLabCompatibilityWorkflow {

	private function __construct(
		private readonly ?ContractLabSnapshot $accepted_snapshot,
		private readonly ContractLabCompatibilityLedger $ledger,
		private readonly ContractLabCompatibilityRunContext $context
	) {
	}

	public static function new(
		?ContractLabSnapshot $accepted_snapshot,
		ContractLabCompatibilityLedger $ledger,
		ContractLabCompatibilityRunContext $context
	): self {
		return new self( $accepted_snapshot, $ledger, $context );
	}

	/**
	 * Read-only candidate normalization and comparison.
	 */
	public function check( ContractLabCandidateObservation $candidate ): ContractLabCompatibilityCheck {
		return ContractLabCompatibilityCheck::from_candidate( $candidate, $this->accepted_snapshot );
	}

	/**
	 * Promote a changed candidate only after a reviewed green decision.
	 */
	public function accept( ContractLabCompatibilityCheck $check, ContractLabCompatibilityReview $review ): ContractLabCompatibilityResult {
		$this->assert_current_check( $check );
		if ( ! $review->is_green() ) {
			throw new ContractLabObservationException( 'unsupported', 'Snapshot acceptance requires a reviewed green compatibility gate.' );
		}

		$snapshot_promoted = $check->has_semantic_change();
		$snapshot          = $snapshot_promoted ? $check->candidate_snapshot() : $this->accepted_snapshot;
		if ( null === $snapshot ) {
			throw new ContractLabObservationException( 'malformed', 'Green snapshot acceptance could not resolve an accepted snapshot.' );
		}
		$record = $this->context->ledger_record( $snapshot, $review );
		$ledger = $this->ledger->append( $record );

		return ContractLabCompatibilityResult::new( $review, $snapshot, $record, $ledger, $snapshot_promoted );
	}

	/**
	 * Append only reviewed yellow/red history. Inconclusive results stay ephemeral.
	 */
	public function classify( ContractLabCompatibilityCheck $check, ContractLabCompatibilityReview $review ): ContractLabCompatibilityResult {
		$this->assert_current_check( $check );
		if ( $review->is_green() ) {
			throw new ContractLabObservationException( 'unsupported', 'Green compatibility decisions must use explicit snapshot acceptance.' );
		}
		if ( $review->is_inconclusive() ) {
			return ContractLabCompatibilityResult::new( $review, null, null, $this->ledger, false );
		}
		if ( null === $this->accepted_snapshot ) {
			throw new ContractLabObservationException( 'malformed', 'Yellow or red classification requires an accepted baseline snapshot.' );
		}

		$record = $this->context->ledger_record( $this->accepted_snapshot, $review );
		$ledger = $this->ledger->append( $record );

		return ContractLabCompatibilityResult::new( $review, $this->accepted_snapshot, $record, $ledger, false );
	}

	public function accepted_snapshot(): ?ContractLabSnapshot {
		return $this->accepted_snapshot;
	}

	public function ledger(): ContractLabCompatibilityLedger {
		return $this->ledger;
	}

	private function assert_current_check( ContractLabCompatibilityCheck $check ): void {
		$expected = null !== $this->accepted_snapshot ? $this->accepted_snapshot->digest() : null;
		$actual   = null !== $check->accepted_snapshot() ? $check->accepted_snapshot()->digest() : null;
		if ( $expected !== $actual ) {
			throw new ContractLabObservationException( 'conflict', 'Contract Lab compatibility check does not match the current accepted snapshot.' );
		}
	}
}
