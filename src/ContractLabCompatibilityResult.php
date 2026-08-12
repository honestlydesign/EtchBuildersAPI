<?php
/**
 * Value result of a reviewed Contract Lab acceptance or classification action.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Makes promotion, audit, and ledger write outcomes explicit to callers.
 */
final class ContractLabCompatibilityResult {

	private function __construct(
		private readonly string $classification,
		private readonly ContractLabCompatibilityReview $review,
		private readonly ?ContractLabSnapshot $snapshot,
		private readonly ?ContractLabCompatibilityLedgerRecord $ledger_record,
		private readonly ContractLabCompatibilityLedger $ledger,
		private readonly bool $snapshot_promoted
	) {
	}

	public static function new(
		ContractLabCompatibilityReview $review,
		?ContractLabSnapshot $snapshot,
		?ContractLabCompatibilityLedgerRecord $ledger_record,
		ContractLabCompatibilityLedger $ledger,
		bool $snapshot_promoted
	): self {
		return new self( $review->classification(), $review, $snapshot, $ledger_record, $ledger, $snapshot_promoted );
	}

	public function classification(): string {
		return $this->classification;
	}

	public function review(): ContractLabCompatibilityReview {
		return $this->review;
	}

	public function snapshot(): ?ContractLabSnapshot {
		return $this->snapshot;
	}

	public function ledger_record(): ?ContractLabCompatibilityLedgerRecord {
		return $this->ledger_record;
	}

	public function ledger(): ContractLabCompatibilityLedger {
		return $this->ledger;
	}

	public function snapshot_promoted(): bool {
		return $this->snapshot_promoted;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'classification'    => $this->classification,
			'review'            => $this->review->to_array(),
			'snapshot'          => null !== $this->snapshot ? $this->snapshot->to_array() : null,
			'ledger_record'     => null !== $this->ledger_record ? $this->ledger_record->to_array() : null,
			'ledger'            => $this->ledger->to_array(),
			'snapshot_promoted' => $this->snapshot_promoted,
		);
	}
}
