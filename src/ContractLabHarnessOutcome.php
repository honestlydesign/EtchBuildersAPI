<?php
/**
 * Named semantic outcome imported from the legacy rendering harness.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Connects one retained intent to existing Contract Lab probes and evidence.
 *
 * This is an intent-level mapping. It deliberately does not copy the old
 * test's HTML, CSS, WordPress IDs, or private Etch assertions.
 */
final class ContractLabHarnessOutcome {

	public const OUTCOME_VERSION = '1';

	/** @var array<int, string> */
	private const PROBE_IDS = array(
		'runtime-shape',
		'block-wire-round-trip',
		'persistence-handoff',
		'frontend-composite',
		'browser-preservation',
		'javascript-marker',
	);

	/**
	 * @param array<int, string> $probe_ids
	 * @param array<int, string> $fixture_ids
	 * @param array<int, string> $sentinel_ids
	 */
	private function __construct(
		private readonly string $id,
		private readonly string $profile_id,
		private readonly array $probe_ids,
		private readonly array $fixture_ids,
		private readonly array $sentinel_ids,
		private readonly ?string $javascript_marker_id
	) {
	}

	/**
	 * Declare one reusable Contract Lab outcome.
	 *
	 * @param array<int, string> $probe_ids
	 * @param array<int, string> $fixture_ids
	 * @param array<int, string> $sentinel_ids
	 */
	public static function new(
		string $id,
		string $profile_id,
		array $probe_ids,
		array $fixture_ids = array(),
		array $sentinel_ids = array(),
		?string $javascript_marker_id = null
	): self {
		ContractLabManifestSafety::assert_stable_id( $id, 'Contract Lab harness outcome ID' );
		ContractLabManifestSafety::assert_stable_id( $profile_id, 'Contract Lab harness outcome profile ID' );
		$probe_ids           = self::validate_ids( $probe_ids, 'probe', self::PROBE_IDS );
		$fixture_ids         = self::validate_ids( $fixture_ids, 'fixture' );
		$sentinel_ids        = self::validate_ids( $sentinel_ids, 'browser sentinel' );
		$javascript_marker_id = self::validate_optional_id( $javascript_marker_id, 'JavaScript marker' );

		if ( array() === $probe_ids ) {
			throw new InvalidArgumentException( 'Contract Lab harness outcome must reference at least one probe.' );
		}
		if ( in_array( 'frontend-composite', $probe_ids, true ) && array() === $fixture_ids ) {
			throw new InvalidArgumentException( 'Frontend Contract Lab outcomes must reference a composite fixture.' );
		}
		if ( in_array( 'browser-preservation', $probe_ids, true ) && array() === $sentinel_ids ) {
			throw new InvalidArgumentException( 'Browser Contract Lab outcomes must reference a preservation sentinel.' );
		}
		if ( in_array( 'javascript-marker', $probe_ids, true ) && null === $javascript_marker_id ) {
			throw new InvalidArgumentException( 'JavaScript Contract Lab outcomes must reference a marker.' );
		}
		if ( in_array( 'javascript-marker', $probe_ids, true ) && array() === $fixture_ids ) {
			throw new InvalidArgumentException( 'JavaScript Contract Lab outcomes must reference the marker fixture.' );
		}
		if ( null !== $javascript_marker_id && ! in_array( 'javascript-marker', $probe_ids, true ) ) {
			throw new InvalidArgumentException( 'A JavaScript marker requires the JavaScript Contract Lab probe.' );
		}

		return new self( $id, $profile_id, $probe_ids, $fixture_ids, $sentinel_ids, $javascript_marker_id );
	}

	/**
	 * Rehydrate one canonical outcome declaration.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$expected = array( 'fixture_ids', 'id', 'javascript_marker_id', 'outcome_version', 'probe_ids', 'profile_id', 'sentinel_ids' );
		$actual   = array_keys( $record );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( 'Contract Lab harness outcome has an unknown or missing field.' );
		}
		if ( self::OUTCOME_VERSION !== ( $record['outcome_version'] ?? null ) || ! is_string( $record['id'] ?? null ) || ! is_string( $record['profile_id'] ?? null ) || ! is_array( $record['probe_ids'] ?? null ) || ! is_array( $record['fixture_ids'] ?? null ) || ! is_array( $record['sentinel_ids'] ?? null ) || ( null !== $record['javascript_marker_id'] && ! is_string( $record['javascript_marker_id'] ) ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness outcome has an invalid field shape or version.' );
		}

		$outcome = self::new( $record['id'], $record['profile_id'], $record['probe_ids'], $record['fixture_ids'], $record['sentinel_ids'], $record['javascript_marker_id'] );
		if ( $outcome->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab harness outcome must be canonical.' );
		}

		return $outcome;
	}

	public function id(): string {
		return $this->id;
	}

	public function profile_id(): string {
		return $this->profile_id;
	}

	/**
	 * @return array<int, string>
	 */
	public function probe_ids(): array {
		return $this->probe_ids;
	}

	/**
	 * @return array<int, string>
	 */
	public function fixture_ids(): array {
		return $this->fixture_ids;
	}

	/**
	 * @return array<int, string>
	 */
	public function sentinel_ids(): array {
		return $this->sentinel_ids;
	}

	public function javascript_marker_id(): ?string {
		return $this->javascript_marker_id;
	}

	/**
	 * Verify that this declaration points at the existing composite and
	 * canonical observation seams instead of merely naming imagined fixtures.
	 *
	 * @param array<string, ContractLabIntegrationOutcome> $outcomes
	 */
	public function assert_contract_surface(
		ContractLabFrontendFixtureCatalog $fixtures,
		ContractLabBrowserSentinelCatalog $sentinels,
		array $outcomes,
		ContractLabJavascriptMarker $javascript_marker
	): void {
		$fixture_ids = array_map( static fn ( ContractLabFrontendFixture $fixture ): string => $fixture->logical_id(), $fixtures->all() );
		foreach ( $this->fixture_ids as $fixture_id ) {
			if ( ! in_array( $fixture_id, $fixture_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness outcome "%s" references unknown composite fixture "%s".', $this->id, $fixture_id ) );
			}
		}

		$sentinel_ids    = array_map( static fn ( ContractLabBrowserSentinel $sentinel ): string => $sentinel->logical_id(), $sentinels->all() );
		$sentinels_by_id = array();
		foreach ( $sentinels->all() as $sentinel ) {
			$sentinels_by_id[ $sentinel->logical_id() ] = $sentinel;
		}
		foreach ( $this->sentinel_ids as $sentinel_id ) {
			if ( ! in_array( $sentinel_id, $sentinel_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness outcome "%s" references unknown browser sentinel "%s".', $this->id, $sentinel_id ) );
			}
			$sentinel_fixture_id = $sentinels_by_id[ $sentinel_id ]->fixture_id();
			if ( ! in_array( $sentinel_fixture_id, $fixture_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab browser sentinel "%s" references unknown composite fixture "%s".', $sentinel_id, $sentinel_fixture_id ) );
			}
		}

		$outcome_names = array();
		foreach ( $outcomes as $outcome ) {
			if ( ! $outcome instanceof ContractLabIntegrationOutcome ) {
				throw new InvalidArgumentException( 'Contract Lab harness canonical outcomes must contain integration outcome values.' );
			}
			$outcome_names[ $outcome->name() ] = true;
		}
		if ( ! isset( $outcome_names[ $this->id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab harness outcome "%s" has no canonical integration observation.', $this->id ) );
		}
		if ( null !== $this->javascript_marker_id ) {
			if ( $javascript_marker->logical_id() !== $this->javascript_marker_id ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness outcome "%s" references the wrong JavaScript marker.', $this->id ) );
			}
			if ( ! in_array( $javascript_marker->fixture_id(), $this->fixture_ids, true ) || ! in_array( $javascript_marker->fixture_id(), $fixture_ids, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab JavaScript marker "%s" references an unbound composite fixture "%s".', $javascript_marker->logical_id(), $javascript_marker->fixture_id() ) );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy(
			array(
				'outcome_version'       => self::OUTCOME_VERSION,
				'id'                    => $this->id,
				'profile_id'            => $this->profile_id,
				'probe_ids'             => $this->probe_ids,
				'fixture_ids'           => $this->fixture_ids,
				'sentinel_ids'          => $this->sentinel_ids,
				'javascript_marker_id'  => $this->javascript_marker_id,
			),
			'Contract Lab harness outcomes must contain only persisted data.'
		);
	}

	/**
	 * @param array<int, mixed>  $ids
	 * @param array<int, string> $allowed
	 * @return array<int, string>
	 */
	private static function validate_ids( array $ids, string $label, array $allowed = array() ): array {
		if ( ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab harness %s IDs must be an ordered list.', $label ) );
		}
		$validated = array();
		$seen      = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness %s IDs must be strings.', $label ) );
			}
			ContractLabManifestSafety::assert_stable_id( $id, sprintf( 'Contract Lab harness %s ID', $label ) );
			if ( array() !== $allowed && ! in_array( $id, $allowed, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness %s ID "%s" is unsupported.', $label, $id ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab harness %s IDs contain duplicate "%s".', $label, $id ) );
			}
			$seen[ $id ] = true;
			$validated[] = $id;
		}

		return $validated;
	}

	private static function validate_optional_id( ?string $id, string $label ): ?string {
		if ( null !== $id ) {
			ContractLabManifestSafety::assert_stable_id( $id, 'Contract Lab harness ' . $label . ' ID' );
		}

		return $id;
	}
}
