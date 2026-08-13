<?php
/**
 * Contract Lab maintainer gate tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelResult;
use HonestlyDesign\EtchBuilders\ContractLabCandidateObservation;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityLedger;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityReview;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityRunContext;
use HonestlyDesign\EtchBuilders\ContractLabCompatibilityWorkflow;
use HonestlyDesign\EtchBuilders\ContractLabCoreProbeEvidence;
use HonestlyDesign\EtchBuilders\ContractLabCoreProbeRunner;
use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabDoctorResult;
use HonestlyDesign\EtchBuilders\ContractLabEtchRuntimeResolutionObservation;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabMarker;
use HonestlyDesign\EtchBuilders\ContractLabFrontendObservation;
use HonestlyDesign\EtchBuilders\ContractLabFrontendProbeResult;
use HonestlyDesign\EtchBuilders\ContractLabHarnessMigration;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarker;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarkerResult;
use HonestlyDesign\EtchBuilders\ContractLabMaintainerGate;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabPackageGateEvidence;
use HonestlyDesign\EtchBuilders\ContractLabPackageGateSet;
use HonestlyDesign\EtchBuilders\ContractLabProfile;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use HonestlyDesign\EtchBuilders\ContractLabSiteState;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the current-Etch gate admits only complete, exact evidence.
 */
final class ContractLabMaintainerGateTest extends TestCase {
	private ContractLabPackageGateFixture $package_fixture;

	protected function setUp(): void {
		$this->package_fixture = ContractLabPackageGateFixture::create();
	}

	protected function tearDown(): void {
		$this->package_fixture->close();
	}

	public function test_complete_gate_is_read_only_and_can_feed_reviewed_snapshot_acceptance(): void {
		$gate = ContractLabMaintainerGate::run(
			$this->candidate(),
			ContractLabDoctorResult::from_findings( array() ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$this->package_gates(),
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result() ),
			$this->browser_results(),
			JavascriptMarkerResultFactory::observed(),
		);

		self::assertTrue( $gate->is_ready() );
		self::assertFalse( $gate->publication_authorized() );
		self::assertSame( $this->package_fixture->identity()->source_revision(), $gate->candidate_source_revision() );
		self::assertSame(
			array( 'package', 'source', 'catalog', 'recipe' ),
			array_map( static fn ( ContractLabPackageGateEvidence $evidence ): string => $evidence->gate_id(), $gate->package_gates()->all() )
		);
		self::assertSame( $gate->to_array(), $gate->to_array() );

		$workflow = ContractLabCompatibilityWorkflow::new( null, ContractLabCompatibilityLedger::empty(), $this->context() );
		$check    = $gate->check( null );
		self::assertSame( $check->candidate_snapshot()->digest(), $workflow->check( $gate->candidate() )->candidate_snapshot()->digest() );

		$result = $workflow->accept( $gate, $this->review() );
		self::assertSame( 'green', $result->classification() );
		self::assertCount( 1, $result->ledger()->records() );
	}

	public function test_gate_rejects_incomplete_browser_evidence_before_snapshot_comparison(): void {
		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'browser' );

		ContractLabMaintainerGate::run(
			$this->candidate(),
			ContractLabDoctorResult::from_findings( array() ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$this->package_gates(),
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result() ),
			array( ContractLabBrowserSentinelResult::skipped( $this->sentinel( 'document', 'document-preservation' ), null, 'Editor unavailable.' ) ),
			JavascriptMarkerResultFactory::observed(),
		);
	}

	public function test_gate_rejects_a_package_gate_for_a_different_candidate_commit(): void {
		$other_fixture = ContractLabPackageGateFixture::create();
		$other_fixture->diverge();
		$gates = $this->package_gates( $other_fixture );

		$gate = ContractLabMaintainerGate::run(
			$this->candidate(),
			ContractLabDoctorResult::from_findings( array() ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$gates,
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result() ),
			$this->browser_results(),
			JavascriptMarkerResultFactory::observed(),
		);

		self::assertTrue( $gate->is_contract_failed() );
		self::assertStringContainsString( 'exact candidate source revision', (string) $gate->status_reason() );
		$other_fixture->close();
	}

	public function test_gate_rejects_environment_failure_and_does_not_classify_it_as_contract_red(): void {
		$gate = ContractLabMaintainerGate::run(
			$this->candidate(),
			ContractLabDoctorResult::from_findings( array( array( 'category' => 'environment', 'code' => 'runtime', 'message' => 'LocalWP unavailable.' ) ) ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$this->package_gates(),
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result() ),
			$this->browser_results(),
			JavascriptMarkerResultFactory::observed(),
		);

		self::assertTrue( $gate->is_inconclusive() );
		self::assertStringContainsString( 'doctor', (string) $gate->status_reason() );
	}

	public function test_gate_rejects_a_candidate_that_omits_one_migrated_outcome(): void {
		$record = $this->candidate()->to_array();
		/** @var array<int, array<string, mixed>> $outcomes */
		$outcomes                     = $record['integration_outcomes'];
		$record['integration_outcomes'] = array_slice( $outcomes, 0, -1 );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'outcomes' );

		$this->run_gate( ContractLabCandidateObservation::from_array( $record ) );
	}

	public function test_gate_rejects_a_javascript_marker_outside_the_migrated_inventory(): void {
		$gate = $this->run_gate(
			$this->candidate(),
			null,
			null,
			null,
			ContractLabJavascriptMarkerResult::observed(
				ContractLabJavascriptMarker::new( 'other-marker', 'marketing-home', 'other-script', 'otherMarker', 'true' ),
				'true'
			)
		);

		self::assertTrue( $gate->is_contract_failed() );
		self::assertStringContainsString( 'marker', (string) $gate->status_reason() );
	}

	public function test_gate_rejects_forged_frontend_marker_statuses(): void {
		$forged = ContractLabFrontendObservation::observed(
			'marketing-home',
			'/contract-fixtures/marketing-home/',
			200,
			array( array( 'type' => 'element', 'name' => 'main', 'attributes' => array(), 'children' => array() ) ),
			array(),
			array(
				array( 'capability' => 'dom', 'marker' => 'marketing-home', 'status' => 'observed' ),
				array( 'capability' => 'stylesheet', 'marker' => '.marketing-card', 'status' => 'observed' ),
				array( 'capability' => 'class', 'marker' => 'marketing-card', 'status' => 'observed' ),
				array( 'capability' => 'slot', 'marker' => 'headline', 'status' => 'observed' ),
				array( 'capability' => 'loop', 'marker' => 'item-1', 'status' => 'observed' ),
				array( 'capability' => 'dynamic', 'marker' => 'title', 'status' => 'observed' ),
			)
		);
		$gate = $this->run_gate( $this->candidate(), null, null, null, null, ContractLabFrontendProbeResult::observed( $forged ) );

		self::assertTrue( $gate->is_contract_failed() );
		self::assertStringContainsString( 'provenance', (string) $gate->status_reason() );
	}

	public function test_gate_rejects_a_matched_browser_result_with_different_before_and_after(): void {
		$results = $this->browser_results();
		$sentinel = $this->sentinel( 'document', 'document-preservation' );
		$before = $this->frontend_result()->observation();
		$after  = $this->frontend_result( true )->observation();
		if ( null === $before || null === $after ) {
			self::fail( 'The executed frontend fixture must produce observations for this test.' );
		}
		$results[0] = ContractLabBrowserSentinelResult::matched( $sentinel, $before, $after );
		$gate = $this->run_gate( $this->candidate(), null, null, $results );

		self::assertTrue( $gate->is_contract_failed() );
		self::assertStringContainsString( 'provenance', (string) $gate->status_reason() );
	}

	public function test_gate_never_admits_block_wire_drift_as_ready(): void {
		$core = $this->core_evidence_with_block_drift();
		$gate = $this->run_gate( $this->candidate( false, $core ), null, null, null, null, null, $core );

		self::assertTrue( $gate->is_contract_failed() );
		self::assertStringContainsString( 'wire round-trip drifted', (string) $gate->status_reason() );
	}

	public function test_gate_keeps_inconclusive_runtime_resolution_out_of_ready_state(): void {
		$core = $this->core_evidence( false, true );
		$gate = $this->run_gate( $this->candidate( false, $core ), null, null, null, null, null, $core );

		self::assertTrue( $gate->is_inconclusive() );
		self::assertStringContainsString( 'runtime resolution', (string) $gate->status_reason() );
	}

	public function test_gate_resolves_raw_binding_against_live_site_state(): void {
		$site = $this->site();
		$bad_binding = ContractLabBinding::new( 'aLDSbEdOG', 'other-contract-lab', 'http://etch-builders-contract-lab.local', $site->web_root(), 'contract-lab-marker' );
		$gate = ContractLabMaintainerGate::run(
			$this->candidate(),
			ContractLabDoctorResult::from_findings( array() ),
			$bad_binding,
			$site,
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$this->core_evidence(),
			$this->package_gates(),
			$this->package_fixture->identity()->source_revision(),
			array( $this->frontend_result() ),
			$this->browser_results(),
			JavascriptMarkerResultFactory::observed()
		);

		self::assertTrue( $gate->is_inconclusive() );
		self::assertStringContainsString( 'binding verification', (string) $gate->status_reason() );
	}

	/**
	 * @return ContractLabPackageGateSet
	 */
	private function package_gates( ?ContractLabPackageGateFixture $fixture = null ): ContractLabPackageGateSet {
		$fixture ??= $this->package_fixture;
		$evidence = array();
		foreach ( array( 'package', 'source', 'catalog', 'recipe' ) as $gate_id ) {
			$evidence[] = $fixture->evidence( $gate_id );
		}

		return ContractLabPackageGateSet::from_evidence( $evidence );
	}

	private function core_evidence( bool $drift = false, bool $inconclusive = false ): ContractLabCoreProbeEvidence {
		$adapter = new class( $drift ) implements \HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface {
			public function __construct( private readonly bool $drift ) {
			}

			public function parse( string $markup ): array {
				$content = $this->drift && 'contract-lab-serialized' === $markup ? 'different' : 'marketing-card';

				return array( array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => $content ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
			}

			public function serialize( array $blocks ): string {
				return 'contract-lab-serialized';
			}
		};
		$runtime = $inconclusive
			? ContractLabEtchRuntimeResolutionObservation::inconclusive( 'Etch runtime endpoint unavailable.' )->to_array()
			: ContractLabEtchRuntimeResolutionObservation::observed(
				array( array( 'opaque_id' => 'contract-lab-style', 'selector' => '.marketing-card' ) ),
				array( array( 'component_key' => 'ContractLabCard', 'property_paths' => array(), 'slots' => array() ) )
			)->to_array();
		$source = new class( $runtime ) implements \HonestlyDesign\EtchBuilders\Contracts\ContractLabCoreProbeSourceInterface {
			public function __construct( private readonly array $runtime ) {
			}

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
				return $this->runtime;
			}
		};

		return ContractLabCoreProbeRunner::run(
			$source,
			ComponentContractCatalog::from_contracts(),
			'contract-lab-wire',
			$adapter
		);
	}

	private function core_evidence_with_block_drift(): ContractLabCoreProbeEvidence {
		return $this->core_evidence( true );
	}

	private function candidate( bool $changed = false, ?ContractLabCoreProbeEvidence $core = null ): ContractLabCandidateObservation {
		$core = $core ?? $this->core_evidence();
		$frontend_observation = $this->frontend_result( $changed )->observation();
		if ( null === $frontend_observation ) {
			throw new \LogicException( 'The executed frontend fixture must produce an observation.' );
		}
		$frontend = $frontend_observation->to_array();
		unset( $frontend['fixture_path'] );
		$outcomes = array(
			array( 'name' => 'runtime-shape-core', 'status' => 'observed', 'observation' => $core->runtime_shape()->to_array() ),
			array( 'name' => 'block-wire-round-trip-core', 'status' => $core->block_round_trip()->status(), 'observation' => $core->block_round_trip()->to_array() ),
			array( 'name' => 'component-style-handoff', 'status' => $core->runtime_resolution()->is_observed() ? 'observed' : 'inconclusive', 'observation' => array( 'probe_version' => '1.0', 'observation_schema_version' => '1.0', 'status' => $core->runtime_resolution()->status(), 'observations' => array( 'persistence_handoff' => $core->persistence_handoff()->to_array(), 'etch_runtime_resolution' => $core->runtime_resolution()->to_array() ) ) ),
			array( 'name' => 'frontend-core-composite', 'status' => 'observed', 'observation' => $frontend ),
		);
		foreach ( $this->browser_results( $changed ) as $result ) {
			$outcomes[] = array( 'name' => 'browser-save-' . $result->sentinel()->entity_type(), 'status' => $result->status(), 'observation' => $result->semantic_projection() );
		}
		$javascript = JavascriptMarkerResultFactory::observed();
		$outcomes[] = array( 'name' => 'javascript-marketing-ready', 'status' => $javascript->status(), 'observation' => $javascript->semantic_projection() );

		return ContractLabCandidateObservation::from_array(
			array(
				'candidate_version'     => '1',
				'schema_version'        => '1',
				'contract_version'      => '1.0',
				'metadata'              => array( 'etch_release' => '1.6.0', 'artifact_fingerprint' => $this->package_fixture->identity()->artifact_fingerprint() ),
				'runtime_shape'         => $core->runtime_shape()->to_array(),
				'integration_outcomes' => $outcomes,
			)
		);
	}

	private function binding(): ContractLabBinding {
		return ContractLabBinding::new( 'aLDSbEdOG', 'etch-builders-contract-lab', 'http://etch-builders-contract-lab.local', '/Users/woji/Local Sites/etch-builders-contract-lab/app/public', 'contract-lab-marker' );
	}

	private function site(): ContractLabSiteState {
		return ContractLabSiteState::new(
			'aLDSbEdOG',
			'etch-builders-contract-lab',
			'http://etch-builders-contract-lab.local',
			'/Users/woji/Local Sites/etch-builders-contract-lab/app/public',
			'wp',
			'local',
			true,
			array( 'etch', 'etch-theme', 'contract-probe-plugin' ),
			ContractLabMarker::new( 'contract-lab-marker', 'aLDSbEdOG', 'wp' )
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
	 * @return array<int, ContractLabBrowserSentinelResult>
	 */
	private function browser_results( bool $changed = false ): array {
		return array_map(
			fn ( string $entity ): ContractLabBrowserSentinelResult => $this->browser_result( $entity, $changed ),
			ContractLabBrowserSentinel::ENTITY_TYPES
		);
	}

	private function browser_result( string $entity, bool $changed ): ContractLabBrowserSentinelResult {
		$sentinel = $this->sentinel( $entity, $entity . '-preservation' );
		$observation = $this->frontend_result( $changed )->observation();
		if ( null === $observation ) {
			throw new \LogicException( 'The executed frontend fixture must produce an observation.' );
		}

		return ContractLabExecutedEvidenceFixture::browser( $sentinel, $observation, $observation );
	}

	private function sentinel( string $entity, string $logical_id ): ContractLabBrowserSentinel {
		return ContractLabBrowserSentinel::new( $logical_id, $entity, 'marketing-home', '/editor/' . ( 'global-asset' === $entity ? 'assets' : $entity . 's' ), 'save-' . $entity );
	}

	private function frontend_result( bool $changed = false ): ContractLabFrontendProbeResult {
		return ContractLabExecutedEvidenceFixture::frontend( $changed );
	}

	private function context(): ContractLabCompatibilityRunContext {
		return ContractLabCompatibilityRunContext::from_values(
			'current-etch-maintainer-gate',
			'1.0',
			$this->package_fixture->identity()->source_revision(),
			'1.6.0',
			$this->package_fixture->identity()->artifact_fingerprint(),
			array(
				'environment_version'        => '1',
				'lab_id'                     => 'etch-builders-contract-lab',
				'site_id'                    => 'aLDSbEdOG',
				'wordpress_version'          => '7.0.4',
				'php_version'                => '8.2.29',
				'localwp_version'            => '10.1.1',
				'probe_schema_version'       => '1.0',
				'observation_schema_version' => '1.0',
				'doctor_status'              => 'ready',
				'marker_verified'            => true,
			)
		);
	}

	private function review(): ContractLabCompatibilityReview {
		return ContractLabCompatibilityReview::from_values(
			'green',
			'reviewer',
			'2026-08-13T00:00:00+00:00',
			'Current-Etch package and Contract Lab evidence reviewed.',
			array(
				array( 'kind' => 'maintainer-gate', 'status' => 'passed', 'summary' => 'All current-Etch maintainer gate evidence passed.' ),
				array( 'kind' => 'standards-spec', 'status' => 'passed', 'summary' => 'The candidate matches the reviewed Builder and Etch contract.' ),
				array( 'kind' => 'release-readiness', 'status' => 'passed', 'summary' => 'Readiness was reviewed without authorizing publication.' ),
			)
		);
	}

	/**
		 * @param ContractLabPackageGateSet|null              $package_gates
		 * @param array<int, ContractLabBrowserSentinelResult>|null $browser_results
		 */
	private function run_gate(
		ContractLabCandidateObservation $candidate,
		?ContractLabDoctorResult $doctor = null,
		?ContractLabPackageGateSet $package_gates = null,
		?array $browser_results = null,
		?ContractLabJavascriptMarkerResult $javascript_result = null,
		?ContractLabFrontendProbeResult $frontend_result = null,
		?ContractLabCoreProbeEvidence $core_evidence = null
	): ContractLabMaintainerGate {
		return ContractLabMaintainerGate::run(
			$candidate,
			$doctor ?? ContractLabDoctorResult::from_findings( array() ),
			$this->binding(),
			$this->site(),
			$this->manifest(),
			ContractLabHarnessMigration::current(),
			$core_evidence ?? $this->core_evidence(),
			$package_gates ?? $this->package_gates(),
			$this->package_fixture->identity()->source_revision(),
			array( $frontend_result ?? $this->frontend_result() ),
			$browser_results ?? $this->browser_results(),
			$javascript_result ?? JavascriptMarkerResultFactory::observed()
		);
	}
}

/**
 * Keeps the PHPUnit fixture compact while preserving the production result type.
 */
final class JavascriptMarkerResultFactory {

	public static function observed(): ContractLabJavascriptMarkerResult {
		return ContractLabExecutedEvidenceFixture::javascript();
	}
}
