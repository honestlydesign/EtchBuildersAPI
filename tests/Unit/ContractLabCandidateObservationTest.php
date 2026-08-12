<?php
/**
 * Contract Lab candidate observation and semantic diff tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabSemanticDiff;
use PHPUnit\Framework\TestCase;

/**
 * Proves candidate normalization is explicit and comparison is read-only.
 */
final class ContractLabCandidateObservationTest extends TestCase {

	public function test_normalization_removes_only_declared_volatile_metadata_and_excludes_release_identity_from_semantics(): void {
		$raw = $this->candidate_record(
			array(
				'observed_at' => '2026-08-12T20:40:00Z',
				'request_id'  => 'request-a',
			),
			array(
				array(
					'name'         => 'frontend',
					'status'       => 'observed',
					'observation'  => array( 'ordered' => array( 'first', 'second' ) ),
				),
			)
		);

		$candidate = ContractLabCandidateObservation::from_array( $raw );

		self::assertSame(
			array(
				'etch_release'          => '1.5.1',
				'artifact_fingerprint'  => str_repeat( 'a', 64 ),
			),
			$candidate->to_array()['metadata']
		);
		self::assertSame(
			array( 'schema_version', 'candidate_version', 'contract_version', 'runtime_shape', 'integration_outcomes' ),
			array_keys( $candidate->semantic_projection() )
		);
		self::assertArrayNotHasKey( 'metadata', $candidate->semantic_projection() );
		self::assertStringNotContainsString( 'request-a', json_encode( $candidate->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_equivalent_candidates_ignore_volatile_fields_and_associative_map_order(): void {
		$first = ContractLabCandidateObservation::from_array(
			$this->candidate_record(
				array( 'observed_at' => 'first', 'request_id' => 'one' ),
				array(
					array(
						'name'        => 'frontend',
						'status'      => 'observed',
						'observation' => array( 'b' => 'two', 'a' => 'one' ),
					),
				)
			)
		);
		$second = ContractLabCandidateObservation::from_array(
			$this->candidate_record(
				array( 'observed_at' => 'second', 'request_id' => 'two' ),
				array(
					array(
						'name'        => 'frontend',
						'status'      => 'observed',
						'observation' => array( 'a' => 'one', 'b' => 'two' ),
					),
				)
			)
		);

		$diff = ContractLabSemanticDiff::compare( $first, $second );

		self::assertSame( 'unchanged', $diff->status() );
		self::assertTrue( $diff->is_unchanged() );
		self::assertSame( array(), $diff->changes() );
	}

	public function test_semantic_diff_preserves_list_order_and_duplicate_meaning(): void {
		$first_record = $this->candidate_record(
			array(),
			array(
				array(
					'name'        => 'frontend',
					'status'      => 'observed',
					'observation' => array( 'ordered' => array( 'first', 'second' ), 'classes' => array( 'card', 'card' ) ),
				),
			)
		);
		$second_record = $first_record;
		$second_record['integration_outcomes'][0]['observation']['ordered'] = array( 'second', 'first' );
		$second_record['integration_outcomes'][0]['observation']['classes'] = array( 'card' );

		$first  = ContractLabCandidateObservation::from_array( $first_record );
		$second = ContractLabCandidateObservation::from_array( $second_record );
		$before = $first->to_array();
		$diff   = ContractLabSemanticDiff::compare( $first, $second );

		self::assertSame( 'changed', $diff->status() );
		self::assertFalse( $diff->is_unchanged() );
		self::assertSame( 'integration_outcomes[0].observation.classes[1]', $diff->changes()[0]['path'] );
		self::assertSame( 'removed', $diff->changes()[0]['kind'] );
		self::assertSame( 'integration_outcomes[0].observation.ordered[0]', $diff->changes()[1]['path'] );
		self::assertSame( 'changed', $diff->changes()[1]['kind'] );
		self::assertSame( $before, $first->to_array(), 'semantic comparison must not mutate the candidate' );
	}

	public function test_unknown_fields_missing_fields_and_schema_drift_fail_closed(): void {
		$unknown = $this->candidate_record( array( 'unknown' => 'not-allowed' ), array() );
		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'metadata' );
		ContractLabCandidateObservation::from_array( $unknown );
	}

	public function test_missing_required_fields_fail_closed(): void {
		$record = $this->candidate_record(
			array(),
			array(
				array(
					'name'        => 'frontend',
					'status'      => 'observed',
					'observation' => array(),
				),
			)
		);
		unset( $record['runtime_shape'] );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'unknown or missing' );
		ContractLabCandidateObservation::from_array( $record );
	}

	public function test_schema_drift_fails_closed_before_payload_interpretation(): void {
		$record                  = $this->candidate_record( array(), array() );
		$record['schema_version'] = '9';

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'unsupported' );
		ContractLabCandidateObservation::from_array( $record );
	}

	/**
	 * @param array<string, string> $volatile
	 * @param array<int, array<string, mixed>> $outcomes
	 * @return array<string, mixed>
	 */
	private function candidate_record( array $volatile, array $outcomes ): array {
		$metadata = array(
			'etch_release'         => '1.5.1',
			'artifact_fingerprint' => str_repeat( 'a', 64 ),
		);
		foreach ( $volatile as $key => $value ) {
			$metadata[ $key ] = $value;
		}

		return array(
			'candidate_version'     => '1',
			'schema_version'        => '1',
			'contract_version'      => '1.0',
			'metadata'              => $metadata,
			'runtime_shape'         => array(
				'observation_version' => '1',
				'required_blocks'     => array( 'etch/text' ),
				'blocks'              => array(
					array(
						'name'       => 'etch/text',
						'attributes' => array(
							array( 'name' => 'content', 'types' => array( 'string' ), 'has_default' => true, 'default' => '' ),
						),
					),
				),
				'components'          => array(),
			),
			'integration_outcomes' => $outcomes,
		);
	}
}
