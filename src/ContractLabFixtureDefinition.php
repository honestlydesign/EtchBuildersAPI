<?php
/**
 * Immutable deterministic Contract Lab fixture definition.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use HonestlyDesign\EtchBuilders\Support\Json;
use InvalidArgumentException;

/**
 * Describes only Builder-owned seed data; generated WordPress IDs and URLs
 * are deliberately not part of the definition.
 */
final class ContractLabFixtureDefinition {

	public const FIXTURE_VERSION = '1';

	private function __construct(
		private readonly string $logical_id,
		private readonly string $kind,
		private readonly array $payload,
		private readonly string $fingerprint
	) {
	}

	/**
	 * Create one deterministic, namespaced fixture definition.
	 *
	 * @param array<string, mixed> $payload Builder-owned seed values.
	 */
	public static function new( string $logical_id, string $kind, array $payload ): self {
		ContractLabManifestSafety::assert_stable_id( $logical_id, 'Contract Lab fixture logical identity' );
		ContractLabManifestSafety::assert_stable_token( $kind, 'Contract Lab fixture kind' );
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Contract Lab fixture payload must contain only scalar, null, or nested array values.' );

		return new self( $logical_id, $kind, $payload, self::make_fingerprint( $logical_id, $kind, $payload ) );
	}

	/**
	 * Rehydrate a canonical definition before it can be used for mutation.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys( $record, array( 'fixture_version', 'namespace', 'logical_id', 'kind', 'payload', 'fingerprint' ), 'Contract Lab fixture definition' );
		if ( ! is_string( $record['fixture_version'] ) || self::FIXTURE_VERSION !== $record['fixture_version'] ) {
			throw new InvalidArgumentException( 'Unknown Contract Lab fixture definition version.' );
		}
		if ( ContractLabBinding::FIXTURE_NAMESPACE !== $record['namespace'] || ! is_string( $record['logical_id'] ) || ! is_string( $record['kind'] ) || ! is_array( $record['payload'] ) || ! is_string( $record['fingerprint'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture definition has an invalid shape or namespace.' );
		}

		$definition = self::new( $record['logical_id'], $record['kind'], $record['payload'] );
		if ( $definition->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab fixture definition must be canonical.' );
		}

		return $definition;
	}

	public function logical_id(): string {
		return $this->logical_id;
	}

	public function kind(): string {
		return $this->kind;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	public function fingerprint(): string {
		return $this->fingerprint;
	}

	public function symbolic_identity(): string {
		return 'fixture:' . $this->logical_id;
	}

	/**
	 * @return array{fixture_version: string, namespace: string, logical_id: string, kind: string, payload: array<string, mixed>, fingerprint: string}
	 */
	public function to_array(): array {
		return array(
			'fixture_version' => self::FIXTURE_VERSION,
			'namespace'       => ContractLabBinding::FIXTURE_NAMESPACE,
			'logical_id'      => $this->logical_id,
			'kind'            => $this->kind,
			'payload'         => $this->payload,
			'fingerprint'     => $this->fingerprint,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function make_fingerprint( string $logical_id, string $kind, array $payload ): string {
		$encoded = Json::encode(
			array(
				'fixture_version' => self::FIXTURE_VERSION,
				'namespace'       => ContractLabBinding::FIXTURE_NAMESPACE,
				'logical_id'      => $logical_id,
				'kind'            => $kind,
				'payload'         => ImmutableArray::canonicalize( $payload ),
			)
		);
		if ( '' === $encoded ) {
			throw new InvalidArgumentException( 'Contract Lab fixture definition could not be fingerprinted.' );
		}

		return hash( 'sha256', $encoded );
	}
}
