<?php
/**
 * Contract Lab compatibility acceptance and classification tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedger;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityReview;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityRunContext;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityWorkflow;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Proves that only reviewed green gates can promote snapshots.
 */
final class ContractLabCompatibilityAcceptanceTest extends TestCase {

	public function test_read_only_check_does_not_write_and_green_acceptance_promotes_changed_snapshot(): void {
		$baseline = $this->snapshot( false );
		$workflow = $this->workflow( $baseline, ContractLabCompatibilityLedger::empty(), 'green-accept' );
		$check    = $workflow->check( $this->candidate( true ) );

		self::assertSame( array(), $workflow->ledger()->records() );
		self::assertTrue( $check->has_semantic_change() );

		$result = $workflow->accept( $check, $this->review( 'green', 'passed' ) );

		self::assertTrue( $result->snapshot_promoted() );
		self::assertNotSame( $baseline->digest(), $result->snapshot()?->digest() );
		$green_record = $result->ledger_record();
		self::assertNotNull( $green_record );
		self::assertSame( 'green', $green_record->classification() );
		self::assertNotNull( $green_record->review() );
		self::assertCount( 1, $result->ledger()->records() );
	}

	public function test_yellow_and_red_append_audited_records_without_snapshot_promotion(): void {
		$baseline = $this->snapshot( false );
		$check    = ContractLabCompatibilityWorkflow::new( $baseline, ContractLabCompatibilityLedger::empty(), $this->context( 'yellow-review' ) )->check( $this->candidate( true ) );
		$yellow   = ContractLabCompatibilityWorkflow::new( $baseline, ContractLabCompatibilityLedger::empty(), $this->context( 'yellow-review' ) )->classify( $check, $this->review( 'yellow', 'passed' ) );

		self::assertFalse( $yellow->snapshot_promoted() );
		self::assertSame( $baseline->digest(), $yellow->snapshot()?->digest() );
		$yellow_record = $yellow->ledger_record();
		self::assertNotNull( $yellow_record );
		self::assertSame( 'yellow', $yellow_record->classification() );
		self::assertSame( 'reviewer', $yellow_record->review()?->reviewed_by() );

		$red = ContractLabCompatibilityWorkflow::new( $baseline, $yellow->ledger(), $this->context( 'red-review' ) )->classify( $check, $this->review( 'red', 'failed' ) );

		self::assertFalse( $red->snapshot_promoted() );
		self::assertSame( $baseline->digest(), $red->snapshot()?->digest() );
		self::assertSame( array( 'yellow', 'red' ), array_map( static fn ( mixed $record ): string => $record->classification(), $red->ledger()->records() ) );
	}

	public function test_acceptance_rejects_non_green_review_and_keeps_ledger_unchanged(): void {
		$workflow = $this->workflow( $this->snapshot( false ), ContractLabCompatibilityLedger::empty(), 'reject-accept' );
		$check    = $workflow->check( $this->candidate( true ) );

		foreach ( array( 'yellow', 'red', 'inconclusive' ) as $classification ) {
			$this->assertThrows(
				fn (): mixed => $workflow->accept( $check, $this->review( $classification, 'inconclusive' === $classification ? 'inconclusive' : ( 'red' === $classification ? 'failed' : 'passed' ) ) ),
				'green'
			);
		}
		self::assertSame( array(), $workflow->ledger()->records() );
	}

	public function test_inconclusive_classification_is_audited_but_never_written_as_compatibility(): void {
		$workflow = $this->workflow( $this->snapshot( false ), ContractLabCompatibilityLedger::empty(), 'inconclusive-review' );
		$check    = $workflow->check( $this->candidate( true ) );
		$result   = $workflow->classify( $check, $this->review( 'inconclusive', 'inconclusive' ) );

		self::assertSame( 'inconclusive', $result->classification() );
		self::assertNull( $result->snapshot() );
		self::assertNull( $result->ledger_record() );
		self::assertSame( array(), $result->ledger()->records() );
		self::assertSame( 'inconclusive', $result->review()->classification() );
	}

	public function test_review_round_trips_actor_time_and_evidence(): void {
		$review = $this->review( 'green', 'passed' );

		self::assertSame( $review->to_array(), ContractLabCompatibilityReview::from_array( $review->to_array() )->to_array() );
	}

	private function assertThrows( callable $callable, string $message ): void {
		try {
			$callable();
			self::fail( 'Expected the compatibility action to be rejected.' );
		} catch ( ContractLabObservationException $error ) {
			self::assertStringContainsString( $message, $error->getMessage() );
		}
	}

	private function workflow( ContractLabSnapshot $baseline, ContractLabCompatibilityLedger $ledger, string $record_id ): ContractLabCompatibilityWorkflow {
		return ContractLabCompatibilityWorkflow::new( $baseline, $ledger, $this->context( $record_id ) );
	}

	private function review( string $classification, string $status ): ContractLabCompatibilityReview {
		return ContractLabCompatibilityReview::from_values(
			$classification,
			'reviewer',
			'2026-08-12T21:30:00+00:00',
			'Reviewed against the current maintainer compatibility gate.',
			array( array( 'kind' => 'maintainer-gate', 'status' => $status, 'summary' => 'Gate evidence was reviewed.' ) )
		);
	}

	private function context( string $record_id ): ContractLabCompatibilityRunContext {
		return ContractLabCompatibilityRunContext::from_values(
			$record_id,
			'1.0',
			str_repeat( 'b', 40 ),
			'1.5.1',
			str_repeat( 'c', 64 ),
			array(
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
			)
		);
	}

	private function candidate( bool $changed ): ContractLabCandidateObservation {
		return ContractLabCandidateObservation::from_array(
			array(
				'candidate_version'     => '1',
				'schema_version'        => '1',
				'contract_version'      => '1.0',
				'metadata'              => array( 'etch_release' => '1.5.1', 'artifact_fingerprint' => str_repeat( 'a', 64 ) ),
				'runtime_shape'         => array(
					'observation_version' => '1',
					'required_blocks'     => array( 'etch/text' ),
					'blocks'              => array( array( 'name' => 'etch/text', 'attributes' => array( array( 'name' => 'content', 'types' => array( 'string' ), 'has_default' => true, 'default' => '' ) ) ) ),
					'components'          => array(),
				),
				'integration_outcomes' => array( array( 'name' => 'frontend', 'status' => 'observed', 'observation' => array( 'ordered' => $changed ? array( 'second', 'first' ) : array( 'first', 'second' ) ) ) ),
			)
		);
	}

	private function snapshot( bool $changed ): ContractLabSnapshot {
		return ContractLabSnapshot::from_candidate( $this->candidate( $changed ) );
	}
}
