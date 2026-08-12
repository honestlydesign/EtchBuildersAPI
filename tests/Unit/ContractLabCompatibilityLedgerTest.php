<?php
/**
 * Contract Lab compatibility ledger tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedger;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedgerRecord;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Proves that compatibility history is append-only and fail-closed.
 */
final class ContractLabCompatibilityLedgerTest extends TestCase {

	public function test_record_keeps_release_identity_fingerprint_and_snapshot_reference_distinct(): void {
		$snapshot = $this->snapshot();
		$record   = $this->record( 'green', $snapshot );
		$payload  = $record->to_array();

		self::assertSame( '1.5.1', $payload['etch_release'] );
		self::assertSame( str_repeat( 'c', 64 ), $payload['artifact_fingerprint'] );
		self::assertSame( $snapshot->snapshot_version(), $payload['accepted_snapshot_version'] );
		self::assertSame( $snapshot->digest(), $payload['accepted_snapshot_digest'] );
		self::assertTrue( $record->is_compatible() );
		self::assertSame( $payload, ContractLabCompatibilityLedgerRecord::from_array( $payload )->to_array() );
	}

	public function test_ledger_append_is_immutable_and_round_trips_in_order(): void {
		$snapshot = $this->snapshot();
		$first    = $this->record( 'green', $snapshot, 'release-one-green' );
		$second   = $this->record( 'yellow', $snapshot, 'release-two-yellow' );
		$empty    = ContractLabCompatibilityLedger::empty();
		$ledger   = $empty->append( $first )->append( $second );

		self::assertSame( array(), $empty->records() );
		self::assertSame( array( 'release-one-green', 'release-two-yellow' ), array_map( static fn ( ContractLabCompatibilityLedgerRecord $entry ): string => $entry->record_id(), $ledger->records() ) );
		self::assertSame( $ledger->to_array(), ContractLabCompatibilityLedger::from_array( $ledger->to_array() )->to_array() );

		$this->expectException( ContractLabObservationException::class );
		$ledger->append( $first );
	}

	public function test_red_record_references_baseline_without_becoming_compatible(): void {
		$record = $this->record( 'red', $this->snapshot(), 'release-three-red' );

		self::assertFalse( $record->is_compatible() );
		self::assertSame( 'red', $record->classification() );
	}

	public function test_inconclusive_classification_and_environment_cannot_enter_ledger(): void {
		$snapshot = $this->snapshot();
		$record   = $this->record( 'green', $snapshot );

		$inconclusive = $record->to_array();
		$inconclusive['classification'] = 'inconclusive';
		$this->assertRejected( $inconclusive, 'inconclusive' );

		$environment_failure = $record->to_array();
		$environment_failure['environment']['doctor_status'] = 'environment_failure';
		$this->assertRejected( $environment_failure, 'ready' );

		$inconclusive_evidence = $record->to_array();
		$inconclusive_evidence['evidence'][0]['status'] = 'inconclusive';
		$this->assertRejected( $inconclusive_evidence, 'inconclusive' );
	}

	public function test_referenced_digest_and_versions_fail_closed(): void {
		$record = $this->record( 'green', $this->snapshot() )->to_array();

		$record['accepted_snapshot_digest'] = 'not-a-digest';
		$this->assertRejected( $record, 'digest' );

		$record = $this->record( 'green', $this->snapshot() )->to_array();
		$record['accepted_snapshot_version'] = '9';
		$this->assertRejected( $record, 'version' );
}

	private function assertRejected( array $record, string $message ): void {
		try {
			ContractLabCompatibilityLedgerRecord::from_array( $record );
			self::fail( 'Expected the ledger record to be rejected.' );
		} catch ( ContractLabObservationException $error ) {
			self::assertStringContainsString( $message, $error->getMessage() );
		}
	}

	private function record( string $classification, ContractLabSnapshot $snapshot, string $record_id = 'release-one-green' ): ContractLabCompatibilityLedgerRecord {
		return ContractLabCompatibilityLedgerRecord::from_snapshot(
			$record_id,
			'1.0',
			str_repeat( 'b', 40 ),
			'1.5.1',
			str_repeat( 'c', 64 ),
			$this->environment(),
			$classification,
			$snapshot,
			array(
				array( 'kind' => 'pure-gate', 'status' => 'passed', 'summary' => 'PHPUnit passed.' ),
				array( 'kind' => 'contract-lab', 'status' => 'passed', 'summary' => 'Marker and doctor passed.' ),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function environment(): array {
		return array(
			'environment_version'      => '1',
			'lab_id'                   => 'etch-builders-contract-lab',
			'site_id'                  => 'd_SZmmF83',
			'wordpress_version'        => '6.6.2',
			'php_version'              => '8.2.29',
			'localwp_version'          => '10.1.0',
			'probe_schema_version'     => '1.0',
			'observation_schema_version' => '1.0',
			'doctor_status'            => 'ready',
			'marker_verified'          => true,
		);
	}

	private function snapshot(): ContractLabSnapshot {
		return ContractLabSnapshot::from_candidate(
			ContractLabCandidateObservation::from_array(
				array(
					'candidate_version'     => '1',
					'schema_version'        => '1',
					'contract_version'      => '1.0',
					'metadata'              => array( 'etch_release' => '1.5.1', 'artifact_fingerprint' => str_repeat( 'a', 64 ) ),
					'runtime_shape'         => array(
						'observation_version' => '1',
						'required_blocks'     => array( 'etch/text' ),
						'blocks'              => array(
							array(
								'name'       => 'etch/text',
								'attributes' => array( array( 'name' => 'content', 'types' => array( 'string' ), 'has_default' => true, 'default' => '' ) ),
							),
						),
						'components'          => array(),
					),
					'integration_outcomes' => array( array( 'name' => 'frontend', 'status' => 'observed', 'observation' => array( 'ready' => true ) ) ),
				)
			)
		);
	}
}
