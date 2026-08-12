<?php
/**
 * Derived Contract Lab compatibility metadata tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedger;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedgerRecord;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityMetadata;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityReview;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Proves that release metadata is a projection of reviewed ledger history.
 */
final class ContractLabCompatibilityMetadataTest extends TestCase {

	public function test_metadata_uses_the_last_certified_record_for_the_current_builder_contract(): void {
		$old_contract = $this->record( 'old-contract', 'green', '0.9', '1.5.0', str_repeat( 'a', 64 ), $this->snapshot( false ) );
		$green       = $this->record( 'current-green', 'green', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );
		$yellow      = $this->record( 'current-yellow', 'yellow', '1.0', '1.5.2', str_repeat( 'c', 64 ), $this->snapshot( true ) );
		$red         = $this->record( 'current-red', 'red', '1.0', '1.5.3', str_repeat( 'd', 64 ), $this->snapshot( false ) );
		$ledger      = ContractLabCompatibilityLedger::empty()->append( $old_contract )->append( $green )->append( $yellow )->append( $red );

		$metadata = ContractLabCompatibilityMetadata::from_ledger( $ledger, '1.0' );

		self::assertSame(
			array(
				'metadata_version'            => '1',
				'builder_contract_version'    => '1.0',
				'builder_source_revision'     => str_repeat( 'b', 40 ),
				'compatibility_classification' => 'yellow',
				'etch_release'                => '1.5.2',
				'artifact_fingerprint'        => str_repeat( 'c', 64 ),
				'accepted_snapshot_version'   => '1',
				'accepted_snapshot_digest'    => $this->snapshot( true )->digest(),
				'source_record_id'            => 'current-yellow',
			),
			$metadata->to_array()
		);
		self::assertSame( $metadata->to_array(), ContractLabCompatibilityMetadata::from_array( $metadata->to_array() )->to_array() );
	}

	public function test_red_history_does_not_advance_certified_metadata(): void {
		$certified = $this->record( 'certified', 'green', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );
		$red       = $this->record( 'failed', 'red', '1.0', '1.5.2', str_repeat( 'c', 64 ), $this->snapshot( true ) );

		$metadata = ContractLabCompatibilityMetadata::from_ledger( ContractLabCompatibilityLedger::empty()->append( $certified )->append( $red ), '1.0' );

		self::assertSame( 'certified', $metadata->source_record_id() );
		self::assertSame( '1.5.1', $metadata->etch_release() );
		self::assertSame( str_repeat( 'b', 64 ), $metadata->artifact_fingerprint() );
	}

	public function test_generation_rejects_a_contract_without_a_certified_record(): void {
		$red = $this->record( 'only-red', 'red', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'certified' );
		ContractLabCompatibilityMetadata::from_ledger( ContractLabCompatibilityLedger::empty()->append( $red ), '1.0' );
	}

	public function test_generation_rejects_conflicting_snapshot_history_for_one_runtime_identity(): void {
		$first  = $this->record( 'first', 'green', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );
		$second = $this->record( 'second', 'green', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( true ) );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'conflicting' );
		ContractLabCompatibilityMetadata::from_ledger( ContractLabCompatibilityLedger::empty()->append( $first )->append( $second ), '1.0' );
	}

	public function test_generation_rejects_reclassification_without_explicit_supersession(): void {
		$first  = $this->record( 'first', 'green', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );
		$second = $this->record( 'second', 'yellow', '1.0', '1.5.1', str_repeat( 'b', 64 ), $this->snapshot( false ) );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'conflicting' );
		ContractLabCompatibilityMetadata::from_ledger( ContractLabCompatibilityLedger::empty()->append( $first )->append( $second ), '1.0' );
	}

	private function record(
		string $record_id,
		string $classification,
		string $builder_contract_version,
		string $etch_release,
		string $artifact_fingerprint,
		ContractLabSnapshot $snapshot
	): ContractLabCompatibilityLedgerRecord {
		return ContractLabCompatibilityLedgerRecord::from_snapshot(
			$record_id,
			$builder_contract_version,
			str_repeat( 'b', 40 ),
			$etch_release,
			$artifact_fingerprint,
			$this->environment(),
			$classification,
			$snapshot,
			array(
				array( 'kind' => 'pure-gate', 'status' => 'red' === $classification ? 'failed' : 'passed', 'summary' => 'Maintainer gate was reviewed.' ),
			),
			ContractLabCompatibilityReview::from_values(
				$classification,
				'reviewer',
				'2026-08-12T21:30:00+00:00',
				'Reviewed ledger evidence.',
				array( array( 'kind' => 'maintainer-gate', 'status' => 'red' === $classification ? 'failed' : 'passed', 'summary' => 'Gate evidence was reviewed.' ) )
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function environment(): array {
		return array(
			'environment_version'        => '1',
			'lab_id'                     => 'etch-builders-contract-lab',
			'site_id'                    => 'd_SZmmF83',
			'wordpress_version'          => '6.6.2',
			'php_version'                => '8.2.29',
			'localwp_version'            => '10.1.0',
			'probe_schema_version'       => '1.0',
			'observation_schema_version' => '1.0',
			'doctor_status'              => 'ready',
			'marker_verified'            => true,
		);
	}

	private function snapshot( bool $changed ): ContractLabSnapshot {
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
					'integration_outcomes' => array( array( 'name' => 'frontend', 'status' => 'observed', 'observation' => array( 'ordered' => $changed ? array( 'second', 'first' ) : array( 'first', 'second' ) ) ) ),
				)
			)
		);
	}
}
