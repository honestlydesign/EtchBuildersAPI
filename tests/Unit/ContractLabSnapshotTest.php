<?php
/**
 * Contract Lab immutable semantic snapshot tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabSnapshot;
use HonestlyDesign\EtchBuilders\Support\Json;
use PHPUnit\Framework\TestCase;

/**
 * Proves that accepted semantic snapshots are canonical and content addressed.
 */
final class ContractLabSnapshotTest extends TestCase {

	public function test_snapshot_contains_only_canonical_semantics_and_digest_excludes_envelope(): void {
		$candidate = ContractLabCandidateObservation::from_array(
			$this->candidate_record(
				array(
					'observed_at' => '2026-08-12T21:00:00Z',
					'request_id'  => 'request-a',
				),
				array( 'b' => 'two', 'a' => 'one' )
			)
		);

		$snapshot = ContractLabSnapshot::from_candidate( $candidate );
		$record   = $snapshot->to_array();

		self::assertSame( array( 'digest', 'payload', 'snapshot_version' ), $this->sorted_keys( $record ) );
		self::assertSame(
			array( 'candidate_version', 'contract_version', 'integration_outcomes', 'runtime_shape', 'schema_version' ),
			$this->sorted_keys( $record['payload'] )
		);
		self::assertArrayNotHasKey( 'metadata', $record['payload'] );
		self::assertStringNotContainsString( '1.5.1', Json::encode( $record['payload'] ) );
		self::assertStringNotContainsString( 'request-a', Json::encode( $record ) );
		self::assertSame( hash( 'sha256', Json::encode( $snapshot->payload() ) ), $snapshot->digest() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $snapshot->digest() );
	}

	public function test_equivalent_candidates_reuse_the_same_digest(): void {
		$first = ContractLabSnapshot::from_candidate(
			ContractLabCandidateObservation::from_array(
				$this->candidate_record(
					array( 'observed_at' => 'first', 'request_id' => 'one' ),
					array( 'b' => 'two', 'a' => 'one' )
				)
			)
		);
		$second = ContractLabSnapshot::from_candidate(
			ContractLabCandidateObservation::from_array(
				$this->candidate_record(
					array( 'observed_at' => 'second', 'request_id' => 'two' ),
					array( 'a' => 'one', 'b' => 'two' )
				)
			)
		);

		self::assertSame( $first->digest(), $second->digest() );
		self::assertSame( $first->payload(), $second->payload() );
	}

	public function test_semantic_change_creates_a_new_digest_and_payload_is_detached(): void {
		$first_candidate = ContractLabCandidateObservation::from_array(
			$this->candidate_record( array(), array( 'ordered' => array( 'first', 'second' ) ) )
		);
		$second_record = $this->candidate_record( array(), array( 'ordered' => array( 'second', 'first' ) ) );
		$first         = ContractLabSnapshot::from_candidate( $first_candidate );
		$second        = ContractLabSnapshot::from_candidate( ContractLabCandidateObservation::from_array( $second_record ) );
		$payload       = $first->payload();
		$payload['contract_version'] = 'changed';

		self::assertNotSame( $first->digest(), $second->digest() );
		self::assertSame( '1.0', $first->payload()['contract_version'] );
		self::assertNotSame( $payload, $first->payload() );
		self::assertSame( $first->to_array(), ContractLabSnapshot::from_array( $first->to_array() )->to_array() );
	}

	public function test_tampered_snapshot_fails_digest_validation(): void {
		$snapshot                  = ContractLabSnapshot::from_candidate( ContractLabCandidateObservation::from_array( $this->candidate_record( array(), array() ) ) );
		$tampered                  = $snapshot->to_array();
		$tampered['payload']['contract_version'] = '9.0';

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'digest' );
		ContractLabSnapshot::from_array( $tampered );
	}

	/**
	 * @dataProvider forbidden_payloads
	 * @param array<string, string> $observation
	 */
	public function test_environment_and_sensitive_fields_fail_closed( array $observation ): void {
		$this->expectException( ContractLabObservationException::class );
		ContractLabSnapshot::from_candidate(
			ContractLabCandidateObservation::from_array( $this->candidate_record( array(), $observation ) )
		);
	}

	/**
	 * @return array<string, array{array<string, string>}>
	 */
	public static function forbidden_payloads(): array {
		return array(
			'machine path' => array( array( 'machine_path' => '/Users/woji/Local Sites/etch-base' ) ),
			'URL'          => array( array( 'site_url' => 'https://etch-base.local' ) ),
			'secret'      => array( array( 'secret' => 'not-for-persistence' ) ),
		);
	}

	/**
	 * @param array<string, string> $volatile
	 * @param array<string, mixed>  $observation
	 * @return array<string, mixed>
	 */
	private function candidate_record( array $volatile, array $observation ): array {
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
			'integration_outcomes' => array(
				array(
					'name'        => 'frontend',
					'status'      => 'observed',
					'observation' => $observation,
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<int, string>
	 */
	private function sorted_keys( array $record ): array {
		$keys = array_keys( $record );
		sort( $keys );

		return $keys;
	}
}
