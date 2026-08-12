<?php
/**
 * Versioned, immutable Contract Lab manifest.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Defines the safe maintainer-only input contract for Contract Lab tooling.
 */
final class ContractLabManifest {

	public const MANIFEST_VERSION = '1';

	/**
	 * @param array<int, ContractLabProfile> $profiles
	 */
	private function __construct(
		private readonly ContractLabEnvironmentConstraints $environment,
		private readonly ContractLabDeterministicSettings $settings,
		private readonly array $profiles,
		private readonly ContractLabSchema $probe_schema,
		private readonly ContractLabSchema $observation_schema
	) {
	}

	/**
	 * Create a validated manifest.
	 *
	 * The base profile is intentionally explicit: it is the only universally
	 * required prerequisite set, while capability-specific profiles remain
	 * ordered and optional.
	 *
	 * @param array<int, ContractLabProfile> $profiles
	 */
	public static function new(
		ContractLabEnvironmentConstraints $environment,
		ContractLabDeterministicSettings $settings,
		array $profiles,
		ContractLabSchema $probe_schema,
		ContractLabSchema $observation_schema
	): self {
		if ( ! array_is_list( $profiles ) || array() === $profiles ) {
			throw new InvalidArgumentException( 'Contract Lab manifest profiles must be a non-empty ordered list.' );
		}

		$seen_ids         = array();
		$has_required     = false;
		$has_required_base = false;
		foreach ( $profiles as $profile ) {
			if ( ! $profile instanceof ContractLabProfile ) {
				throw new InvalidArgumentException( 'Contract Lab manifest profiles must contain Contract Lab profile values.' );
			}
			if ( isset( $seen_ids[ $profile->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab manifest has duplicate profile ID "%s".', $profile->id() ) );
			}
			$seen_ids[ $profile->id() ] = true;
			$has_required            = $has_required || $profile->is_required();
			if ( 'base' === $profile->id() ) {
				if ( ! $profile->is_required() || array( 'etch', 'etch-theme', 'contract-probe-plugin' ) !== $profile->plugin_prerequisites() ) {
					throw new InvalidArgumentException( 'Contract Lab manifest base profile must explicitly require Etch, the Etch Theme, and the Contract Probe Plugin.' );
				}
				$has_required_base = true;
			}
		}
		if ( ! $has_required ) {
			throw new InvalidArgumentException( 'Contract Lab manifest requires at least one required profile.' );
		}
		if ( ! $has_required_base ) {
			throw new InvalidArgumentException( 'Contract Lab manifest requires an explicit required base profile.' );
		}
		if ( 'probe' !== $probe_schema->kind() || 'observation' !== $observation_schema->kind() ) {
			throw new InvalidArgumentException( 'Contract Lab manifest must pair probe and observation schemas with their matching kinds.' );
		}

		return new self( $environment, $settings, array_values( $profiles ), $probe_schema, $observation_schema );
	}

	/**
	 * Rehydrate a canonical manifest projection.
	 *
	 * The manifest version is checked before any child record is hydrated, so
	 * an unknown version cannot trigger a partial Contract Lab lifecycle.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$record = ImmutableArray::copy( $record, 'Contract Lab manifest must contain only scalar or nested array values.' );
		ContractLabManifestSafety::assert_exact_keys(
			$record,
			array( 'manifest_version', 'environment', 'settings', 'profiles', 'probe_schema', 'observation_schema' ),
			'Contract Lab manifest'
		);
		if ( ! is_string( $record['manifest_version'] ) || self::MANIFEST_VERSION !== $record['manifest_version'] ) {
			$version = is_string( $record['manifest_version'] ) ? $record['manifest_version'] : 'unknown';
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab manifest version "%s".', $version ) );
		}
		if ( ! is_array( $record['environment'] ) || ! is_array( $record['settings'] ) || ! is_array( $record['profiles'] ) || ! is_array( $record['probe_schema'] ) || ! is_array( $record['observation_schema'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab manifest has invalid child field shapes.' );
		}

		$environment = ContractLabEnvironmentConstraints::from_array( $record['environment'] );
		$settings    = ContractLabDeterministicSettings::from_array( $record['settings'] );
		$profiles    = array();
		foreach ( $record['profiles'] as $profile_record ) {
			if ( ! is_array( $profile_record ) ) {
				throw new InvalidArgumentException( 'Contract Lab manifest profiles must contain object records.' );
			}
			$profiles[] = ContractLabProfile::from_array( $profile_record );
		}
		$probe_schema       = ContractLabSchema::from_array( $record['probe_schema'] );
		$observation_schema = ContractLabSchema::from_array( $record['observation_schema'] );
		$manifest            = self::new( $environment, $settings, $profiles, $probe_schema, $observation_schema );

		if ( $manifest->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab manifest must be canonical.' );
		}

		return $manifest;
	}

	public function version(): string {
		return self::MANIFEST_VERSION;
	}

	public function environment(): ContractLabEnvironmentConstraints {
		return $this->environment;
	}

	public function settings(): ContractLabDeterministicSettings {
		return $this->settings;
	}

	/**
	 * @return array<int, ContractLabProfile>
	 */
	public function profiles(): array {
		return $this->profiles;
	}

	public function probe_schema(): ContractLabSchema {
		return $this->probe_schema;
	}

	public function observation_schema(): ContractLabSchema {
		return $this->observation_schema;
	}

	/**
	 * @return array{manifest_version: string, environment: array<string, mixed>, settings: array<string, mixed>, profiles: array<int, array<string, mixed>>, probe_schema: array<string, mixed>, observation_schema: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'manifest_version'   => self::MANIFEST_VERSION,
			'environment'        => $this->environment->to_array(),
			'settings'           => $this->settings->to_array(),
			'profiles'           => array_map( static fn ( ContractLabProfile $profile ): array => $profile->to_array(), $this->profiles ),
			'probe_schema'       => $this->probe_schema->to_array(),
			'observation_schema' => $this->observation_schema->to_array(),
		);
	}
}
