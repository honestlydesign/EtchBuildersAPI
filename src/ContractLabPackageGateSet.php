<?php
/**
 * Complete, ordered package gate evidence for one candidate.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Prevents a maintainer gate from receiving an incomplete or mixed-commit set
 * of package execution envelopes.
 */
final class ContractLabPackageGateSet {

	/** @var array<int, string> */
	private const IDS = array( 'package', 'source', 'catalog', 'recipe' );

	/**
	 * @param array<int, ContractLabPackageGateEvidence> $evidence
	 */
	private function __construct( private readonly array $evidence ) {
	}

	/**
	 * @param array<int, ContractLabPackageGateEvidence> $evidence
	 */
	public static function from_evidence( array $evidence ): self {
		if ( ! array_is_list( $evidence ) || count( $evidence ) !== count( self::IDS ) ) {
			throw new InvalidArgumentException( 'Contract Lab package gate set must contain exactly four execution envelopes.' );
		}
		$by_id = array();
		$source_revision = null;
		$artifact_fingerprint = null;
		foreach ( $evidence as $item ) {
			if ( ! $item instanceof ContractLabPackageGateEvidence ) {
				throw new InvalidArgumentException( 'Contract Lab package gate set must contain execution evidence values.' );
			}
			if ( isset( $by_id[ $item->gate_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab package gate set has duplicate "%s" evidence.', $item->gate_id() ) );
			}
			$by_id[ $item->gate_id() ] = $item;
			$source_revision ??= $item->source_revision();
			$artifact_fingerprint ??= $item->artifact_fingerprint();
			if ( $source_revision !== $item->source_revision() || $artifact_fingerprint !== $item->artifact_fingerprint() ) {
				throw new InvalidArgumentException( 'Contract Lab package gate set must refer to one exact source revision and artifact fingerprint.' );
			}
		}
		$actual_ids = array_keys( $by_id );
		sort( $actual_ids );
		$expected_ids = self::IDS;
		sort( $expected_ids );
		if ( $actual_ids !== $expected_ids ) {
			throw new InvalidArgumentException( 'Contract Lab package gate set must contain exactly package, source, catalog, and recipe evidence.' );
		}

		$ordered = array();
		foreach ( self::IDS as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab package gate set is missing "%s" evidence.', $id ) );
			}
			$ordered[] = $by_id[ $id ];
		}

		return new self( $ordered );
	}

	/**
	 * @return array<int, ContractLabPackageGateEvidence>
	 */
	public function all(): array {
		return $this->evidence;
	}

	public function evidence( string $gate_id ): ContractLabPackageGateEvidence {
		foreach ( $this->evidence as $item ) {
			if ( $item->gate_id() === $gate_id ) {
				return $item;
			}
		}

		throw new InvalidArgumentException( sprintf( 'Contract Lab package gate set has no "%s" evidence.', $gate_id ) );
	}

	public function source_revision(): string {
		return $this->evidence[0]->source_revision();
	}

	public function artifact_fingerprint(): string {
		return $this->evidence[0]->artifact_fingerprint();
	}

	public function assert_identities_unchanged(): void {
		foreach ( $this->evidence as $item ) {
			$item->assert_identity_unchanged();
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function to_array(): array {
		return array_map( static fn ( ContractLabPackageGateEvidence $item ): array => $item->to_array(), $this->evidence );
	}
}
