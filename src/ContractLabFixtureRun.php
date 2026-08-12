<?php
/**
 * Result of one Contract Lab fixture seed or cleanup operation.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Keeps runtime records available to adapters while exposing a symbolic
 * projection for observations and diagnostics.
 */
final class ContractLabFixtureRun {

	/**
	 * @param array<int, ContractLabFixtureRecord> $records
	 */
	private function __construct( private readonly string $status, private readonly array $records ) {
	}

	/**
	 * @param array<int, ContractLabFixtureRecord> $records
	 */
	public static function new( string $status, array $records ): self {
		return new self( $status, array_values( $records ) );
	}

	public function status(): string {
		return $this->status;
	}

	/**
	 * @return array<int, ContractLabFixtureRecord>
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * @return array{status: string, fixtures: array<int, array{record_version: string, namespace: string, owner: string, logical_id: string, kind: string, symbolic_id: string, symbolic_url: string, payload_digest: string}>}
	 */
	public function to_array(): array {
		return array(
			'status'   => $this->status,
			'fixtures' => array_map( static fn ( ContractLabFixtureRecord $record ): array => $record->symbolic_array(), $this->records ),
		);
	}
}
