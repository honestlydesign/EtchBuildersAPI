<?php
/**
 * Contract Lab compatibility acceptance and classification tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabCoreProbeEvidence;
use HonestlyDesign\EtchBuilders\ContractLabCoreProbeRunner;
use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabMarker;
use HonestlyDesign\EtchBuilders\ContractLabProfile;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use HonestlyDesign\EtchBuilders\ContractLabSiteState;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelResult;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedger;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityReview;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityRunContext;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityWorkflow;
use HonestlyDesign\EtchBuilders\ContractLabDoctorResult;
use HonestlyDesign\EtchBuilders\ContractLabFrontendProbeResult;
use HonestlyDesign\EtchBuilders\ContractLabHarnessMigration;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarkerResult;
use HonestlyDesign\EtchBuilders\ContractLabMaintainerGate;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabEtchRuntimeResolutionObservation;
use HonestlyDesign\EtchBuilders\ContractLabPackageGateSet;
use HonestlyDesign\EtchBuilders\ContractLabSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Proves that only reviewed green gates can promote snapshots.
 */
final class ContractLabCompatibilityAcceptanceTest extends TestCase {
	private ContractLabPackageGateFixture $package_fixture;

	protected function setUp(): void {
		$this->package_fixture = ContractLabPackageGateFixture::create();
	}

	protected function tearDown(): void {
		$this->package_fixture->close();
	}

	public function test_read_only_check_does_not_write_and_green_acceptance_promotes_changed_snapshot(): void {
		$baseline = $this->snapshot( false );
		$workflow = $this->workflow( $baseline, ContractLabCompatibilityLedger::empty(), 'green-accept' );
		$check    = $workflow->check( $this->candidate( true ) );

		self::assertSame( array(), $workflow->ledger()->records() );
		self::assertTrue( $check->has_semantic_change() );

		$result = $workflow->accept( $this->gate( true ), $this->review( 'green', 'passed' ) );

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
		$gate     = $this->gate( true );
		$yellow   = ContractLabCompatibilityWorkflow::new( $baseline, ContractLabCompatibilityLedger::empty(), $this->context( 'yellow-review' ) )->classify( $gate, $this->review( 'yellow', 'passed' ) );

		self::assertFalse( $yellow->snapshot_promoted() );
		self::assertSame( $baseline->digest(), $yellow->snapshot()?->digest() );
		$yellow_record = $yellow->ledger_record();
		self::assertNotNull( $yellow_record );
		self::assertSame( 'yellow', $yellow_record->classification() );
		self::assertSame( 'reviewer', $yellow_record->review()?->reviewed_by() );

		$red_gate = $this->gate_with_package_status( true, 'failed' );
		$red = ContractLabCompatibilityWorkflow::new( $baseline, $yellow->ledger(), $this->context( 'red-review' ) )->classify( $red_gate, $this->review( 'red', 'failed' ) );

		self::assertFalse( $red->snapshot_promoted() );
		self::assertSame( $baseline->digest(), $red->snapshot()?->digest() );
		self::assertSame( array( 'yellow', 'red' ), array_map( static fn ( mixed $record ): string => $record->classification(), $red->ledger()->records() ) );
	}

	public function test_acceptance_rejects_non_green_review_and_keeps_ledger_unchanged(): void {
		$workflow = $this->workflow( $this->snapshot( false ), ContractLabCompatibilityLedger::empty(), 'reject-accept' );
		$gate     = $this->gate( true );

		foreach ( array( 'yellow', 'red', 'inconclusive' ) as $classification ) {
			$this->assertThrows(
				fn (): mixed => $workflow->accept( $gate, $this->review( $classification, 'inconclusive' === $classification ? 'inconclusive' : ( 'red' === $classification ? 'failed' : 'passed' ) ) ),
				'yellow' === $classification ? 'green' : 'ready'
			);
		}
		self::assertSame( array(), $workflow->ledger()->records() );
	}

	public function test_inconclusive_classification_is_audited_but_never_written_as_compatibility(): void {
		$workflow = $this->workflow( $this->snapshot( false ), ContractLabCompatibilityLedger::empty(), 'inconclusive-review' );
		$result   = $workflow->classify( $this->gate_with_package_status( true, 'inconclusive' ), $this->review( 'inconclusive', 'inconclusive' ) );

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

	public function test_raw_check_cannot_bypass_the_current_etch_maintainer_gate(): void {
		$workflow = $this->workflow( $this->snapshot( false ), ContractLabCompatibilityLedger::empty(), 'raw-check' );
		$check = $workflow->check( $this->candidate( true ) );

		$this->assertThrows(
			fn (): mixed => $workflow->accept( $check, $this->review( 'green', 'passed' ) ),
			'Raw Contract Lab compatibility checks'
		);
		self::assertSame( array(), $workflow->ledger()->records() );
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
		$maintainer_status = 'red' === $classification ? 'failed' : $status;
		$review_status     = in_array( $classification, array( 'red', 'inconclusive' ), true ) ? 'passed' : $status;
		return ContractLabCompatibilityReview::from_values(
			$classification,
			'reviewer',
			'2026-08-12T21:30:00+00:00',
			'Reviewed against the current maintainer compatibility gate.',
			array(
				array( 'kind' => 'maintainer-gate', 'status' => $maintainer_status, 'summary' => 'Gate evidence was reviewed.' ),
				array( 'kind' => 'standards-spec', 'status' => $review_status, 'summary' => 'Builder and Etch contract evidence was reviewed.' ),
				array( 'kind' => 'release-readiness', 'status' => $review_status, 'summary' => 'Readiness was reviewed without authorizing publication.' ),
			)
		);
	}

	private function gate( bool $changed ): ContractLabMaintainerGate {
		return $this->gate_with_package_status( $changed, 'passed' );
	}

	private function gate_with_package_status( bool $changed, string $package_status ): ContractLabMaintainerGate {
		return ContractLabMaintainerGate::run(
			$this->candidate( $changed ),
			ContractLabDoctorResult::from_findings( array() ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$this->package_gates( $package_status ),
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result( $changed ) ),
			$this->browser_results( $changed ),
			$this->javascript_result()
		);
	}

	private function binding(): ContractLabBinding {
		return ContractLabBinding::new( 'd_SZmmF83', 'etch-builders-contract-lab', 'http://etch-builders-contract-lab.local', '/tmp/contract-lab', 'contract-lab-marker' );
	}

	private function javascript_result(): ContractLabJavascriptMarkerResult {
		return ContractLabExecutedEvidenceFixture::javascript();
	}

	private function site(): ContractLabSiteState {
		return ContractLabSiteState::new(
			'd_SZmmF83',
			'etch-builders-contract-lab',
			'http://etch-builders-contract-lab.local',
			'/tmp/contract-lab',
			'wp',
			'local',
			true,
			array( 'etch', 'etch-theme', 'contract-probe-plugin' ),
			ContractLabMarker::new( 'contract-lab-marker', 'd_SZmmF83', 'wp' )
		);
	}

	private function manifest(): ContractLabManifest {
		return ContractLabManifest::new(
			ContractLabEnvironmentConstraints::new( '>=6.6 <8.0', '>=8.1 <8.5', '>=10.0' ),
			ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', 'etch', false ),
			array( ContractLabProfile::required( 'base', array( 'etch', 'etch-theme', 'contract-probe-plugin' ) ) ),
			ContractLabSchema::probe( '1.0', array( 'probe_version' ) ),
			ContractLabSchema::observation( '1.0', array( 'observation_version' ) )
		);
	}

	/**
	 * @return ContractLabPackageGateSet
	 */
	private function package_gates( string $status = 'passed' ): ContractLabPackageGateSet {
		$evidence = array();
		foreach ( array( 'package', 'source', 'catalog', 'recipe' ) as $id ) {
			$evidence[] = 'inconclusive' === $status
				? $this->package_fixture->evidence( $id, null, '', 'Package gate infrastructure unavailable.' )
				: $this->package_fixture->evidence( $id, 'failed' === $status ? 1 : 0, 'test output', 'Candidate gate executed.' );
		}

		return ContractLabPackageGateSet::from_evidence( $evidence );
	}

	private function frontend_result( bool $changed = false ): ContractLabFrontendProbeResult {
		return ContractLabExecutedEvidenceFixture::frontend( $changed );
	}

	/**
	 * @return array<int, ContractLabBrowserSentinelResult>
	 */
	private function browser_results( bool $changed = false ): array {
		return array_map(
			fn ( string $entity ): ContractLabBrowserSentinelResult => $this->browser_result( $entity, $changed ),
			ContractLabBrowserSentinel::ENTITY_TYPES
		);
	}

	private function browser_result( string $entity, bool $changed ): ContractLabBrowserSentinelResult {
		$sentinel = ContractLabBrowserSentinel::new( $entity . '-preservation', $entity, 'marketing-home', '/editor/' . ( 'global-asset' === $entity ? 'assets' : $entity . 's' ), 'save-' . $entity );
		$observation = $this->frontend_result( $changed )->observation();
		if ( null === $observation ) {
			throw new \LogicException( 'The executed frontend fixture must produce an observation.' );
		}

		return ContractLabExecutedEvidenceFixture::browser( $sentinel, $observation, $observation );
	}

	private function core_evidence(): ContractLabCoreProbeEvidence {
		$adapter = new class implements \HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface {
			public function parse( string $markup ): array {
				return array( array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'marketing-card' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
			}

			public function serialize( array $blocks ): string {
				return 'contract-lab-serialized';
			}
		};
		$source = new class implements \HonestlyDesign\EtchBuilders\Contracts\ContractLabCoreProbeSourceInterface {
			public function required_block_names(): array {
				return array( 'etch/text' );
			}

			public function registry_records(): array {
				return array( array( 'name' => 'etch/text', 'attributes' => array( 'content' => array( 'type' => 'string', 'default' => '' ) ) ) );
			}

			public function required_component_keys(): array {
				return array();
			}

			public function persistence_handoff_surfaces(): array {
				return array(
					'styles'     => array( array( 'opaque_id' => 'contract-lab-style', 'type' => 'class', 'selector' => '.marketing-card' ) ),
					'components' => array( array( 'component_key' => 'ContractLabCard', 'properties' => array(), 'slots' => array(), 'instances' => array( array( 'attributes' => array(), 'slots' => array() ) ) ) ),
				);
			}

			public function runtime_resolution_record(): array {
				return ContractLabEtchRuntimeResolutionObservation::observed(
					array( array( 'opaque_id' => 'contract-lab-style', 'selector' => '.marketing-card' ) ),
					array( array( 'component_key' => 'ContractLabCard', 'property_paths' => array(), 'slots' => array() ) )
				)->to_array();
			}
		};

		return ContractLabCoreProbeRunner::run(
			$source,
			ComponentContractCatalog::from_contracts(),
			'contract-lab-wire',
			$adapter
		);
	}

	private function context( string $record_id ): ContractLabCompatibilityRunContext {
		return ContractLabCompatibilityRunContext::from_values(
			$record_id,
			'1.0',
			$this->package_fixture->identity()->source_revision(),
			'1.5.1',
			$this->package_fixture->identity()->artifact_fingerprint(),
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
		$core = $this->core_evidence();
		$frontend_observation = $this->frontend_result( $changed )->observation();
		if ( null === $frontend_observation ) {
			throw new \LogicException( 'The executed frontend fixture must produce an observation.' );
		}
		$frontend = $frontend_observation->to_array();
		unset( $frontend['fixture_path'] );
		$outcomes = array(
			array( 'name' => 'runtime-shape-core', 'status' => 'observed', 'observation' => $core->runtime_shape()->to_array() ),
			array( 'name' => 'block-wire-round-trip-core', 'status' => 'matched', 'observation' => $core->block_round_trip()->to_array() ),
			array( 'name' => 'component-style-handoff', 'status' => 'observed', 'observation' => array( 'probe_version' => '1.0', 'observation_schema_version' => '1.0', 'status' => 'observed', 'observations' => array( 'persistence_handoff' => $core->persistence_handoff()->to_array(), 'etch_runtime_resolution' => $core->runtime_resolution()->to_array() ) ) ),
			array( 'name' => 'frontend-core-composite', 'status' => 'observed', 'observation' => $frontend ),
		);
		foreach ( $this->browser_results( $changed ) as $result ) {
			$outcomes[] = array( 'name' => 'browser-save-' . $result->sentinel()->entity_type(), 'status' => $result->status(), 'observation' => $result->semantic_projection() );
		}
		$javascript = $this->javascript_result();
		$outcomes[] = array( 'name' => 'javascript-marketing-ready', 'status' => $javascript->status(), 'observation' => $javascript->semantic_projection() );

		return ContractLabCandidateObservation::from_array(
			array(
				'candidate_version'     => '1',
				'schema_version'        => '1',
				'contract_version'      => '1.0',
				'metadata'              => array( 'etch_release' => '1.5.1', 'artifact_fingerprint' => $this->package_fixture->identity()->artifact_fingerprint() ),
				'runtime_shape'         => $core->runtime_shape()->to_array(),
				'integration_outcomes' => $outcomes,
			)
		);
	}

	private function snapshot( bool $changed ): ContractLabSnapshot {
		return ContractLabSnapshot::from_candidate( $this->candidate( $changed ) );
	}
}
