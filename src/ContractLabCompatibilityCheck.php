<?php
/**
 * Read-only comparison result for one Contract Lab candidate.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Holds disposable candidate evidence and its semantic diff without writes.
 */
final class ContractLabCompatibilityCheck {

	private function __construct(
		private readonly ContractLabCandidateObservation $candidate,
		private readonly ContractLabSnapshot $candidate_snapshot,
		private readonly ?ContractLabSnapshot $accepted_snapshot,
		private readonly ?ContractLabSemanticDiff $diff
	) {
	}

	public static function from_candidate( ContractLabCandidateObservation $candidate, ?ContractLabSnapshot $accepted_snapshot ): self {
		$candidate_snapshot = ContractLabSnapshot::from_candidate( $candidate );
		$diff              = null !== $accepted_snapshot ? ContractLabSemanticDiff::compare_snapshots( $accepted_snapshot, $candidate_snapshot ) : null;

		return new self( $candidate, $candidate_snapshot, $accepted_snapshot, $diff );
	}

	public function candidate(): ContractLabCandidateObservation {
		return $this->candidate;
	}

	public function candidate_snapshot(): ContractLabSnapshot {
		return $this->candidate_snapshot;
	}

	public function accepted_snapshot(): ?ContractLabSnapshot {
		return $this->accepted_snapshot;
	}

	public function diff(): ?ContractLabSemanticDiff {
		return $this->diff;
	}

	public function has_semantic_change(): bool {
		return null === $this->accepted_snapshot || null === $this->diff || $this->diff->is_changed();
	}

	/**
	 * @return array{candidate_digest: string, accepted_snapshot_digest: ?string, semantic_diff: ?array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'candidate_digest'          => $this->candidate_snapshot->digest(),
			'accepted_snapshot_digest'  => null !== $this->accepted_snapshot ? $this->accepted_snapshot->digest() : null,
			'semantic_diff'             => null !== $this->diff ? $this->diff->to_array() : null,
		);
	}
}
