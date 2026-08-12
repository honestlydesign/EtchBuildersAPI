<?php
/**
 * Immutable append-only Contract Lab compatibility ledger.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;

/**
 * Keeps reviewed runtime classifications in append order without mutation.
 */
final class ContractLabCompatibilityLedger {

	public const LEDGER_VERSION = '1';

	/**
	 * @param array<int, ContractLabCompatibilityLedgerRecord> $records
	 */
	private function __construct( private readonly array $records ) {
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * Rehydrate an append-only ledger.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'ledger_version', 'records' ) !== $keys ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility ledger has an unknown or missing field.' );
		}
		if ( ContractLabCompatibilityLedger::LEDGER_VERSION !== ( $record['ledger_version'] ?? null ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility ledger version is unsupported.' );
		}
		if ( ! is_array( $record['records'] ?? null ) || ! array_is_list( $record['records'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility ledger records must be an ordered list.' );
		}

		$records = array();
		$seen    = array();
		foreach ( $record['records'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility ledger entries must be records.' );
			}
			$normalized = ContractLabCompatibilityLedgerRecord::from_array( $entry );
			if ( null === $normalized->review() ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility ledger records require an auditable review.' );
			}
			if ( isset( $seen[ $normalized->record_id() ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab compatibility ledger has duplicate record ID "%s".', $normalized->record_id() ) );
			}
			$seen[ $normalized->record_id() ] = true;
			$records[] = $normalized;
		}

		return new self( $records );
	}

	public function append( ContractLabCompatibilityLedgerRecord $record ): self {
		if ( null === $record->review() ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility ledger writes require an auditable review.' );
		}
		foreach ( $this->records as $existing ) {
			if ( $existing->record_id() === $record->record_id() ) {
				throw new ContractLabObservationException( 'conflict', sprintf( 'Contract Lab compatibility ledger record ID "%s" already exists.', $record->record_id() ) );
			}
		}

		$records   = $this->records;
		$records[] = $record;

		return new self( $records );
	}

	/**
	 * @return array<int, ContractLabCompatibilityLedgerRecord>
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * @return array{ledger_version: string, records: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'ledger_version' => self::LEDGER_VERSION,
			'records'        => array_map(
				static fn ( ContractLabCompatibilityLedgerRecord $record ): array => $record->to_array(),
				$this->records
			),
		);
	}
}
