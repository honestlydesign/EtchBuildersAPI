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
	public function accept( ContractLabMaintainerGate|ContractLabCompatibilityCheck $gate, ContractLabCompatibilityReview $review ): ContractLabCompatibilityResult {
		$gate = $this->require_maintainer_gate( $gate );
		$this->assert_gate_context( $gate );
		$gate->assert_review( $review );
		if ( ! $gate->is_ready() || ! $review->is_green() ) {
			throw new ContractLabObservationException( 'unsupported', 'Snapshot acceptance requires a reviewed green compatibility gate.' );
		}
		$check = $gate->check( $this->accepted_snapshot );

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
	public function classify( ContractLabMaintainerGate|ContractLabCompatibilityCheck $gate, ContractLabCompatibilityReview $review ): ContractLabCompatibilityResult {
		$gate = $this->require_maintainer_gate( $gate );
		$this->assert_gate_context( $gate );
		$gate->assert_review( $review );
		if ( $gate->is_inconclusive() ) {
			return ContractLabCompatibilityResult::new( $review, null, null, $this->ledger, false );
		}
		if ( $gate->is_ready() && $review->is_green() ) {
			throw new ContractLabObservationException( 'unsupported', 'Green compatibility decisions must use explicit snapshot acceptance.' );
		}
		if ( $review->is_inconclusive() ) {
			throw new ContractLabObservationException( 'unsupported', 'An inconclusive review requires an inconclusive current-Etch gate.' );
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

	private function assert_gate_context( ContractLabMaintainerGate $gate ): void {
		$candidate = $gate->candidate()->to_array();
		$metadata  = $candidate['metadata'];
		if ( $candidate['contract_version'] !== $this->context->builder_contract_version() ) {
			throw new ContractLabObservationException( 'conflict', 'Contract Lab gate candidate contract version does not match the run context.' );
		}
		if ( $metadata['etch_release'] !== $this->context->etch_release() ) {
			throw new ContractLabObservationException( 'conflict', 'Contract Lab gate candidate Etch release does not match the run context.' );
		}
		if ( $metadata['artifact_fingerprint'] !== $this->context->artifact_fingerprint() ) {
			throw new ContractLabObservationException( 'conflict', 'Contract Lab gate candidate artifact fingerprint does not match the run context.' );
		}
		if ( $gate->candidate_source_revision() !== $this->context->builder_source_revision() ) {
			throw new ContractLabObservationException( 'conflict', 'Contract Lab gate candidate source revision does not match the run context.' );
		}
	}

	private function require_maintainer_gate( ContractLabMaintainerGate|ContractLabCompatibilityCheck $gate ): ContractLabMaintainerGate {
		if ( $gate instanceof ContractLabCompatibilityCheck ) {
			throw new ContractLabObservationException( 'unsupported', 'Raw Contract Lab compatibility checks cannot bypass the current-Etch maintainer gate.' );
		}

		return $gate;
	}
}
