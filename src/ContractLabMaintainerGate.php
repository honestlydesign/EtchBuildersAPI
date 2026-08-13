<?php
/**
 * Fail-closed current-Etch maintainer gate.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Admits one exact Builder candidate only when every required evidence layer
 * is complete. This class performs no release, publication, or persistence.
 */
final class ContractLabMaintainerGate {

	private const GATE_VERSION = '2';

	private const STATUS_READY = 'ready';

	private const STATUS_CONTRACT_FAILED = 'contract_failed';

	private const STATUS_INCONCLUSIVE = 'inconclusive';

	/**
	 * @param array<int, ContractLabFrontendProbeResult>                                  $frontend_results
	 * @param array<int, ContractLabBrowserSentinelResult>                                $browser_results
	 */
	private function __construct(
		private readonly ContractLabCandidateObservation $candidate,
		private readonly ContractLabCoreProbeEvidence $core_evidence,
		private readonly ?ContractLabBindingResolution $binding,
		private readonly ContractLabDoctorResult $doctor,
		private readonly ContractLabHarnessMigration $migration,
		private readonly ContractLabPackageGateSet $package_gates,
		private readonly string $candidate_source_revision,
		private readonly array $frontend_results,
		private readonly array $browser_results,
		private readonly ContractLabJavascriptMarkerResult $javascript_result,
		private readonly string $status,
		private readonly ?string $status_reason
	) {
	}

	/**
	 * Validate one complete current-Etch maintainer run.
	 *
	 * Binding material is intentionally resolved inside this boundary. A
	 * caller-supplied ContractLabBindingResolution is not accepted because that
	 * value is a report, not independent proof of the target site identity.
	 *
	 * @param array<int, ContractLabFrontendProbeResult>                                  $frontend_results
	 * @param array<int, ContractLabBrowserSentinelResult>                                $browser_results
	 */
	public static function run(
		ContractLabCandidateObservation $candidate,
		ContractLabDoctorResult $doctor,
		ContractLabBinding $binding,
		ContractLabSiteState $site,
		ContractLabManifest $manifest,
		ContractLabHarnessMigration $migration,
		ContractLabCoreProbeEvidence $core_evidence,
		ContractLabPackageGateSet $package_gates,
		string $candidate_source_revision,
		array $frontend_results,
		array $browser_results,
		ContractLabJavascriptMarkerResult $javascript_result
	): self {
		self::assert_source_revision( $candidate_source_revision );
		$status = self::STATUS_READY;
		$reason = null;

		if ( 'ready' !== $doctor->status() ) {
			self::promote_status(
				$status,
				$reason,
				'environment_failure' === $doctor->status() ? self::STATUS_INCONCLUSIVE : self::STATUS_CONTRACT_FAILED,
				sprintf( 'Contract Lab maintainer gate requires a doctor-ready environment; received "%s".', $doctor->status() )
			);
		}

		$binding_resolution = null;
		try {
			$binding_resolution = ContractLabBindingVerifier::resolve( $binding, $site, $manifest );
		} catch ( InvalidArgumentException $error ) {
			self::promote_status( $status, $reason, self::STATUS_INCONCLUSIVE, 'Contract Lab binding verification was unavailable: ' . $error->getMessage() );
		}

		if ( $migration->to_array() !== ContractLabHarnessMigration::current()->to_array() ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab maintainer gate requires the current migrated harness inventory.' );
		}

		self::validate_package_gates( $package_gates, $candidate, $candidate_source_revision, $status, $reason );
		self::assert_migration_surface( $migration );
		self::validate_core_evidence( $core_evidence, $status, $reason );
		self::validate_frontend_results( $frontend_results, $migration, $status, $reason );
		self::validate_browser_results( $browser_results, $migration, $status, $reason );
		self::validate_javascript_result( $javascript_result, $migration, $status, $reason );
		self::validate_candidate_outcomes( $candidate, $migration, $core_evidence, $frontend_results, $browser_results, $javascript_result, $status, $reason );

		return new self(
			$candidate,
			$core_evidence,
			$binding_resolution,
			$doctor,
			$migration,
			$package_gates,
			$candidate_source_revision,
			$frontend_results,
			$browser_results,
			$javascript_result,
			$status,
			$reason
		);
	}

	public function status(): string {
		return $this->status;
	}

	public function status_reason(): ?string {
		return $this->status_reason;
	}

	public function is_ready(): bool {
		return self::STATUS_READY === $this->status;
	}

	public function is_contract_failed(): bool {
		return self::STATUS_CONTRACT_FAILED === $this->status;
	}

	public function is_inconclusive(): bool {
		return self::STATUS_INCONCLUSIVE === $this->status;
	}

	public function publication_authorized(): bool {
		return false;
	}

	public function candidate(): ContractLabCandidateObservation {
		return $this->candidate;
	}

	public function candidate_source_revision(): string {
		return $this->candidate_source_revision;
	}

	/**
	 * @return ContractLabPackageGateSet
	 */
	public function package_gates(): ContractLabPackageGateSet {
		return $this->package_gates;
	}

	/**
	 * Compare this admitted candidate without writing compatibility state.
	 */
	public function check( ?ContractLabSnapshot $accepted_snapshot ): ContractLabCompatibilityCheck {
		return ContractLabCompatibilityCheck::from_candidate( $this->candidate, $accepted_snapshot );
	}

	/**
	 * Require the independently authored Standards/Spec and release-readiness
	 * review evidence before the workflow can write a compatibility record.
	 */
	public function assert_review( ContractLabCompatibilityReview $review ): void {
		$evidence = array();
		foreach ( $review->to_array()['evidence'] as $item ) {
			$evidence[ $item['kind'] ] = $item['status'];
		}
		foreach ( array( 'maintainer-gate', 'standards-spec', 'release-readiness' ) as $kind ) {
			if ( ! array_key_exists( $kind, $evidence ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Current-Etch maintainer gate review requires "%s" evidence.', $kind ) );
			}
		}

		if ( $this->is_inconclusive() ) {
			if ( ! $review->is_inconclusive() || 'inconclusive' !== $evidence['maintainer-gate'] ) {
				throw new ContractLabObservationException( 'unsupported', 'An inconclusive current-Etch gate requires an inconclusive review and explicit inconclusive maintainer evidence.' );
			}

			return;
		}
		if ( $this->is_contract_failed() ) {
			if ( ! $review->is_red() || 'failed' !== $evidence['maintainer-gate'] || 'passed' !== $evidence['standards-spec'] || 'passed' !== $evidence['release-readiness'] ) {
				throw new ContractLabObservationException( 'unsupported', 'A contract-failed current-Etch gate requires a red review, failed maintainer evidence, and passed Standards/Spec and release-readiness evidence.' );
			}

			return;
		}

		if ( ! in_array( $review->classification(), array( 'green', 'yellow' ), true ) ) {
			throw new ContractLabObservationException( 'unsupported', 'A ready current-Etch gate accepts only green or yellow review evidence.' );
		}
		foreach ( array( 'maintainer-gate', 'standards-spec', 'release-readiness' ) as $kind ) {
			if ( 'passed' !== $evidence[ $kind ] ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Current-Etch maintainer gate review requires passed "%s" evidence.', $kind ) );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'gate_version'              => self::GATE_VERSION,
			'status'                    => $this->status,
			'status_reason'             => $this->status_reason,
			'publication_authorized'    => false,
			'candidate_source_revision' => $this->candidate_source_revision,
			'candidate'                 => $this->candidate->to_array(),
			'binding'                   => null !== $this->binding ? array(
				'status'    => $this->binding->status(),
				'site_id'   => $this->binding->site_id(),
				'marker_id' => $this->binding->marker_id(),
			) : null,
			'doctor'            => $this->doctor->to_array(),
			'migration'         => $this->migration->to_array(),
			'package_gates'     => $this->package_gates->to_array(),
			'core_evidence'     => array(
				'runtime_shape'       => $this->core_evidence->runtime_shape()->to_array(),
				'block_round_trip'    => $this->core_evidence->block_round_trip()->to_array(),
				'persistence_handoff' => $this->core_evidence->persistence_handoff()->to_array(),
				'runtime_resolution'   => $this->core_evidence->runtime_resolution()->to_array(),
				'execution_digest'     => $this->core_evidence->execution_digest(),
			),
			'frontend_results'  => array_map( static fn ( ContractLabFrontendProbeResult $result ): array => $result->to_array(), $this->frontend_results ),
			'browser_results'   => array_map( static fn ( ContractLabBrowserSentinelResult $result ): array => $result->to_array(), $this->browser_results ),
			'javascript_result' => $this->javascript_result->to_array(),
		);
	}

	private static function validate_package_gates( ContractLabPackageGateSet $gates, ContractLabCandidateObservation $candidate, string $candidate_source_revision, string &$status, ?string &$reason ): void {
		try {
			$gates->assert_identities_unchanged();
		} catch ( \Throwable $error ) {
			self::promote_status( $status, $reason, self::STATUS_INCONCLUSIVE, 'Contract Lab package gate identity could not be revalidated: ' . $error->getMessage() );
		}
		$metadata = $candidate->to_array()['metadata'];
		if ( $gates->source_revision() !== $candidate_source_revision ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab package gate set does not refer to the exact candidate source revision.' );
		}
		if ( $gates->artifact_fingerprint() !== $metadata['artifact_fingerprint'] ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab package gate set does not refer to the exact candidate artifact fingerprint.' );
		}
		foreach ( $gates->all() as $gate ) {
			if ( 'passed' === $gate->status() ) {
				continue;
			}
			self::promote_status( $status, $reason, 'inconclusive' === $gate->status() ? self::STATUS_INCONCLUSIVE : self::STATUS_CONTRACT_FAILED, sprintf( 'Contract Lab package gate "%s" is %s.', $gate->gate_id(), $gate->status() ) );
		}
	}

	private static function validate_core_evidence( ContractLabCoreProbeEvidence $evidence, string &$status, ?string &$reason ): void {
		if ( ! $evidence->has_execution_binding() ) {
			$status = self::STATUS_CONTRACT_FAILED;
			$reason = 'Contract Lab core evidence does not carry a valid executable-producer receipt.';

			return;
		}
		if ( ! $evidence->block_round_trip()->matches() ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab block wire round-trip drifted.' );
		}

		if ( $evidence->runtime_resolution()->is_observed() ) {
			return;
		}

		self::promote_status(
			$status,
			$reason,
			'inconclusive' === $evidence->runtime_resolution()->status() ? self::STATUS_INCONCLUSIVE : self::STATUS_CONTRACT_FAILED,
			'inconclusive' === $evidence->runtime_resolution()->status()
				? 'Contract Lab Etch runtime resolution evidence is inconclusive.'
				: sprintf( 'Contract Lab Etch runtime resolution evidence is %s.', $evidence->runtime_resolution()->status() )
		);
	}

	private static function assert_migration_surface( ContractLabHarnessMigration $migration ): void {
		$fixtures = ContractLabFrontendFixtureCatalog::current();
		$sentinels = ContractLabBrowserSentinelCatalog::current();
		$marker = ContractLabJavascriptMarker::marketing_reference();
		$outcomes = array();
		foreach ( $migration->outcomes() as $outcome ) {
			$outcomes[ $outcome->id() ] = ContractLabIntegrationOutcome::from_array(
				array(
					'name'        => $outcome->id(),
					'status'      => 'observed',
					'observation' => array( 'contract_surface' => true ),
				)
			);
		}
		try {
			$migration->assert_contract_surface( $fixtures, $sentinels, $outcomes, $marker );
		} catch ( InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
	}

	/**
	 * @param array<int, ContractLabFrontendProbeResult> $results
	 */
	private static function validate_frontend_results( array $results, ContractLabHarnessMigration $migration, string &$status, ?string &$reason ): void {
		if ( ! array_is_list( $results ) || array() === $results ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab maintainer gate requires a non-empty frontend result list.' );
		}
		$expected = self::migration_fixture_ids( $migration );
		$actual   = array();
		$fixtures = ContractLabFrontendFixtureCatalog::current();
		foreach ( $results as $result ) {
			if ( ! $result instanceof ContractLabFrontendProbeResult ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab frontend results must use ContractLabFrontendProbeResult.' );
			}
			if ( null === $result->execution_provenance() || ! $result->execution_provenance()->matches_frontend( $result->fixture_id(), $result->to_array() ) ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Contract Lab frontend fixture "%s" has no matching executable probe provenance.', $result->fixture_id() ) );
			}
			if ( in_array( $result->fixture_id(), $actual, true ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab frontend results contain duplicate fixture "%s".', $result->fixture_id() ) );
			}
			$actual[] = $result->fixture_id();
			if ( ! in_array( $result->status(), array( 'observed', 'failed', 'skipped', 'inconclusive' ), true ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab frontend fixture "%s" has unsupported status "%s".', $result->fixture_id(), $result->status() ) );
			}
			if ( 'observed' === $result->status() ) {
				$observation = $result->observation();
				if ( null === $observation ) {
					self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Contract Lab frontend fixture "%s" is observed without an observation.', $result->fixture_id() ) );
					continue;
				}
				try {
					ContractLabFrontendProbe::assert_observation( $fixtures->fixture( $result->fixture_id() ), $observation );
				} catch ( ContractLabObservationException $error ) {
					self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, $error->getMessage() );
				}
			} elseif ( in_array( $result->status(), array( 'skipped', 'inconclusive' ), true ) ) {
				self::promote_status( $status, $reason, self::STATUS_INCONCLUSIVE, sprintf( 'Contract Lab frontend fixture "%s" is %s.', $result->fixture_id(), $result->status() ) );
			} else {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Contract Lab frontend fixture "%s" is failed.', $result->fixture_id() ) );
			}
		}
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab frontend results do not exactly cover the migrated composite fixtures.' );
		}
	}

	/**
	 * @param array<int, ContractLabBrowserSentinelResult> $results
	 */
	private static function validate_browser_results( array $results, ContractLabHarnessMigration $migration, string &$status, ?string &$reason ): void {
		$catalog = ContractLabBrowserSentinelCatalog::current();
		if ( ! array_is_list( $results ) || count( $results ) !== count( $catalog->all() ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab maintainer gate requires exactly one browser sentinel result for each current-Etch save path.' );
		}
		$expected = self::migration_sentinel_ids( $migration );
		$actual = array();
		foreach ( $results as $result ) {
			if ( ! $result instanceof ContractLabBrowserSentinelResult ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab browser results must use ContractLabBrowserSentinelResult.' );
			}
			$sentinel = $result->sentinel();
			if ( null === $result->execution_provenance() || ! $result->execution_provenance()->matches_browser( $sentinel, $result->to_array() ) ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s" has no matching capture/save/reload provenance.', $sentinel->logical_id() ) );
			}
			if ( in_array( $sentinel->logical_id(), $actual, true ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab browser results contain duplicate sentinel "%s".', $sentinel->logical_id() ) );
			}
			$actual[] = $sentinel->logical_id();
			try {
				$expected_sentinel = $catalog->sentinel( $sentinel->logical_id() );
			} catch ( InvalidArgumentException $error ) {
				throw new ContractLabObservationException( 'malformed', $error->getMessage() );
			}
			if ( $expected_sentinel->to_array() !== $sentinel->to_array() ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s" does not match the current-Etch editor catalog.', $sentinel->logical_id() ) );
			}

			switch ( $result->status() ) {
				case 'matched':
					if ( null === $result->before() || null === $result->after() ) {
						self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s" is matched without before/after observations.', $sentinel->logical_id() ) );
						break;
					}
					self::validate_browser_observation_pair( $sentinel, $result->before(), $result->after(), $status, $reason );
					if ( $result->before()->to_array() !== $result->after()->to_array() ) {
						self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s" claims matched but its semantic observations differ.', $sentinel->logical_id() ) );
					}
					break;
				case 'drift':
				case 'failed':
					if ( null !== $result->before() && null !== $result->after() ) {
						self::validate_browser_observation_pair( $sentinel, $result->before(), $result->after(), $status, $reason );
					}
					self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s" is %s.', $sentinel->logical_id(), $result->status() ) );
					break;
				case 'skipped':
				case 'inconclusive':
					self::promote_status( $status, $reason, self::STATUS_INCONCLUSIVE, sprintf( 'Browser sentinel "%s" is %s.', $sentinel->logical_id(), $result->status() ) );
					break;
				default:
					throw new ContractLabObservationException( 'unsupported', sprintf( 'Browser sentinel "%s" has unsupported status "%s".', $sentinel->logical_id(), $result->status() ) );
			}
		}
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab browser results do not exactly cover the migrated preservation sentinels.' );
		}
	}

	private static function validate_browser_observation_pair( ContractLabBrowserSentinel $sentinel, ContractLabFrontendObservation $before, ContractLabFrontendObservation $after, string &$status, ?string &$reason ): void {
		$fixture = ContractLabFrontendFixtureCatalog::current()->fixture( $sentinel->fixture_id() );
		foreach ( array( $before, $after ) as $observation ) {
			try {
				ContractLabFrontendProbe::assert_observation( $fixture, $observation );
			} catch ( ContractLabObservationException $error ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Browser sentinel "%s": %s', $sentinel->logical_id(), $error->getMessage() ) );
			}
		}
	}

	private static function validate_javascript_result( ContractLabJavascriptMarkerResult $result, ContractLabHarnessMigration $migration, string &$status, ?string &$reason ): void {
		$expected_ids = self::migration_javascript_marker_ids( $migration );
		$expected = ContractLabJavascriptMarker::marketing_reference();
		if ( null === $result->execution_provenance() || ! $result->execution_provenance()->matches_javascript( $result->marker(), $result->to_array() ) ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab JavaScript marker has no matching executable marker provenance.' );
		}
		if ( count( $expected_ids ) !== 1 || $expected_ids[0] !== $expected->logical_id() || $result->marker()->to_array() !== $expected->to_array() ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab JavaScript marker evidence does not match the current migrated marker inventory.' );
		}
		if ( 'observed' === $result->status() ) {
			if ( $result->observed_value() !== $expected->expected_value() || ! $result->assertions_passed() ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab JavaScript marker value was not independently observed.' );
			}
		} elseif ( in_array( $result->status(), array( 'skipped', 'inconclusive' ), true ) ) {
			self::promote_status( $status, $reason, self::STATUS_INCONCLUSIVE, sprintf( 'Contract Lab JavaScript marker evidence is %s.', $result->status() ) );
		} elseif ( 'failed' === $result->status() ) {
			self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, 'Contract Lab JavaScript marker evidence is failed.' );
		} else {
			throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab JavaScript marker has unsupported status "%s".', $result->status() ) );
		}
	}

	/**
	 * @param array<int, ContractLabFrontendProbeResult>   $frontend_results
	 * @param array<int, ContractLabBrowserSentinelResult> $browser_results
	 */
	private static function validate_candidate_outcomes( ContractLabCandidateObservation $candidate, ContractLabHarnessMigration $migration, ContractLabCoreProbeEvidence $core_evidence, array $frontend_results, array $browser_results, ContractLabJavascriptMarkerResult $javascript_result, string &$status, ?string &$reason ): void {
		$outcomes = $candidate->integration_outcomes();
		$expected = self::migration_outcome_ids( $migration );
		$actual = array();
		$by_name = array();
		foreach ( $outcomes as $outcome ) {
			$actual[] = $outcome->name();
			$by_name[ $outcome->name() ] = $outcome;
		}
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate outcomes do not exactly cover the migrated outcome inventory.' );
		}

		$fixtures = ContractLabFrontendFixtureCatalog::current();
		$sentinels = ContractLabBrowserSentinelCatalog::current();
		$marker = ContractLabJavascriptMarker::marketing_reference();
		try {
			$migration->assert_contract_surface( $fixtures, $sentinels, $by_name, $marker );
		} catch ( InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		$expected = array(
			'runtime-shape-core'       => array( 'status' => 'observed', 'observation' => $core_evidence->runtime_shape()->to_array() ),
			'block-wire-round-trip-core' => array( 'status' => $core_evidence->block_round_trip()->status(), 'observation' => $core_evidence->block_round_trip()->to_array() ),
			'component-style-handoff'  => array(
				'status'      => $core_evidence->runtime_resolution()->is_observed() ? 'observed' : 'inconclusive',
				'observation' => array(
					'probe_version'              => '1.0',
					'observation_schema_version' => '1.0',
					'status'                    => $core_evidence->runtime_resolution()->status(),
					'observations'               => array(
						'persistence_handoff'      => $core_evidence->persistence_handoff()->to_array(),
						'etch_runtime_resolution' => $core_evidence->runtime_resolution()->to_array(),
					),
				),
			),
		);
		foreach ( $frontend_results as $result ) {
			if ( $result instanceof ContractLabFrontendProbeResult && 'marketing-home' === $result->fixture_id() ) {
				$observation = $result->observation();
				$projection = null !== $observation ? $observation->to_array() : null;
				if ( is_array( $projection ) ) {
					unset( $projection['fixture_path'] );
				}
				$expected['frontend-core-composite'] = array( 'status' => $result->status(), 'observation' => $projection );
			}
		}
		foreach ( $browser_results as $result ) {
			if ( $result instanceof ContractLabBrowserSentinelResult ) {
				$expected['browser-save-' . $result->sentinel()->entity_type()] = array( 'status' => $result->status(), 'observation' => $result->semantic_projection() );
			}
		}
		$expected['javascript-marketing-ready'] = array( 'status' => $javascript_result->status(), 'observation' => $javascript_result->semantic_projection() );

		foreach ( $expected as $name => $evidence ) {
			$outcome = $by_name[ $name ] ?? null;
			if ( ! $outcome instanceof ContractLabIntegrationOutcome ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab candidate outcome "%s" is missing.', $name ) );
			}
			if ( $outcome->status() !== $evidence['status'] || $outcome->observation() !== self::canonical_observation( $name, $evidence['observation'] ) ) {
				self::promote_status( $status, $reason, self::STATUS_CONTRACT_FAILED, sprintf( 'Contract Lab candidate outcome "%s" does not match its raw probe result.', $name ) );
			}
		}
	}

	/**
	 * @param array<string, mixed>|null $observation
	 * @return array<string, mixed>|null
	 */
	private static function canonical_observation( string $name, ?array $observation ): ?array {
		if ( null === $observation ) {
			return null;
		}

		return ContractLabIntegrationOutcome::from_array(
			array( 'name' => $name, 'status' => 'observed', 'observation' => $observation )
		)->observation();
	}

	/**
	 * @return array<int, string>
	 */
	private static function migration_outcome_ids( ContractLabHarnessMigration $migration ): array {
		return array_map( static fn ( ContractLabHarnessOutcome $outcome ): string => $outcome->id(), $migration->outcomes() );
	}

	/**
	 * @return array<int, string>
	 */
	private static function migration_fixture_ids( ContractLabHarnessMigration $migration ): array {
		$ids = array();
		foreach ( $migration->outcomes() as $outcome ) {
			$ids = array_merge( $ids, $outcome->fixture_ids() );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function migration_sentinel_ids( ContractLabHarnessMigration $migration ): array {
		$ids = array();
		foreach ( $migration->outcomes() as $outcome ) {
			$ids = array_merge( $ids, $outcome->sentinel_ids() );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function migration_javascript_marker_ids( ContractLabHarnessMigration $migration ): array {
		$ids = array();
		foreach ( $migration->outcomes() as $outcome ) {
			if ( null !== $outcome->javascript_marker_id() ) {
				$ids[] = $outcome->javascript_marker_id();
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function assert_source_revision( string $source_revision ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/D', $source_revision ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab maintainer gate candidate source revision must be a hexadecimal commit identifier.' );
		}
	}

	private static function promote_status( string &$status, ?string &$reason, string $incoming, string $incoming_reason ): void {
		$priority = array( self::STATUS_READY => 0, self::STATUS_INCONCLUSIVE => 1, self::STATUS_CONTRACT_FAILED => 2 );
		if ( ( $priority[ $incoming ] ?? -1 ) > ( $priority[ $status ] ?? -1 ) ) {
			$status = $incoming;
			$reason = $incoming_reason;
		}
	}

}
