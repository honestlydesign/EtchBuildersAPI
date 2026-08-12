<?php
/**
 * Runtime record for one explicitly owned Contract Lab fixture.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Separates generated runtime identities from their stable symbolic identity.
 */
final class ContractLabFixtureRecord {

	public const RECORD_VERSION = '1';

	private function __construct(
		private readonly string $namespace,
		private readonly string $owner,
		private readonly string $marker_id,
		private readonly string $logical_id,
		private readonly string $kind,
		private readonly string $resource_id,
		private readonly ?string $resource_url,
		private readonly string $payload_fingerprint
	) {
	}

	/**
	 * Create one runtime record from a deterministic definition.
	 */
	public static function new(
		ContractLabFixtureDefinition $definition,
		string $marker_id,
		string $resource_id,
		?string $resource_url,
		string $payload_fingerprint
	): self {
		if ( '' === trim( $resource_id ) || preg_match( '/[\x00-\x1F\x7F]/', $resource_id ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture resource ID must be a non-empty runtime identity.' );
		}
		ContractLabManifestSafety::assert_stable_id( $marker_id, 'Contract Lab fixture marker ID' );
		self::assert_url( $resource_url );
		ContractLabManifestSafety::assert_digest( $payload_fingerprint, 'Contract Lab fixture payload fingerprint' );

		return new self(
			ContractLabBinding::FIXTURE_NAMESPACE,
			ContractLabBinding::FIXTURE_NAMESPACE,
			$marker_id,
			$definition->logical_id(),
			$definition->kind(),
			$resource_id,
			$resource_url,
			$payload_fingerprint
		);
	}

	/**
	 * Construct a record returned by a storage adapter after it has verified
	 * its explicit ownership metadata. This is public for adapter boundaries
	 * and tests; lifecycle code still validates the ownership again.
	 */
	public static function from_values(
		string $namespace,
		string $owner,
		string $marker_id,
		string $logical_id,
		string $kind,
		string $resource_id,
		?string $resource_url,
		string $payload_fingerprint
	): self {
		ContractLabManifestSafety::assert_stable_id( $namespace, 'Contract Lab fixture namespace' );
		ContractLabManifestSafety::assert_stable_id( $owner, 'Contract Lab fixture owner' );
		ContractLabManifestSafety::assert_stable_id( $marker_id, 'Contract Lab fixture marker ID' );
		ContractLabManifestSafety::assert_stable_id( $logical_id, 'Contract Lab fixture logical identity' );
		ContractLabManifestSafety::assert_stable_token( $kind, 'Contract Lab fixture kind' );
		if ( '' === trim( $resource_id ) || preg_match( '/[\x00-\x1F\x7F]/', $resource_id ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture resource ID must be a non-empty runtime identity.' );
		}
		self::assert_url( $resource_url );
		ContractLabManifestSafety::assert_digest( $payload_fingerprint, 'Contract Lab fixture payload fingerprint' );

		return new self( $namespace, $owner, $marker_id, $logical_id, $kind, $resource_id, $resource_url, $payload_fingerprint );
	}

	public function namespace(): string {
		return $this->namespace;
	}

	public function owner(): string {
		return $this->owner;
	}

	public function marker_id(): string {
		return $this->marker_id;
	}

	public function logical_id(): string {
		return $this->logical_id;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function resource_id(): string {
		return $this->resource_id;
	}

	public function resource_url(): ?string {
		return $this->resource_url;
	}

	public function payload_fingerprint(): string {
		return $this->payload_fingerprint;
	}

	public function is_explicitly_owned( string $expected_marker_id ): bool {
		return ContractLabBinding::FIXTURE_NAMESPACE === $this->namespace
			&& ContractLabBinding::FIXTURE_NAMESPACE === $this->owner
			&& $this->marker_id === $expected_marker_id;
	}

	public function symbolic_id(): string {
		return 'fixture:' . $this->logical_id;
	}

	public function symbolic_url(): string {
		return 'fixture-url:' . $this->logical_id;
	}

	public function matches_definition( ContractLabFixtureDefinition $definition, string $expected_marker_id ): bool {
		return $this->is_explicitly_owned( $expected_marker_id )
			&& $this->logical_id === $definition->logical_id()
			&& $this->kind === $definition->kind()
			&& $this->payload_fingerprint === $definition->fingerprint();
	}

	/**
	 * Runtime projection for trusted adapter bookkeeping. Do not use this in
	 * semantic observations; use symbolic_array() instead.
	 *
	 * @return array{record_version: string, namespace: string, owner: string, marker_id: string, logical_id: string, kind: string, resource_id: string, resource_url: ?string, payload_fingerprint: string}
	 */
	public function to_array(): array {
		return array(
			'record_version'       => self::RECORD_VERSION,
			'namespace'            => $this->namespace,
			'owner'                => $this->owner,
			'marker_id'           => $this->marker_id,
			'logical_id'           => $this->logical_id,
			'kind'                 => $this->kind,
			'resource_id'          => $this->resource_id,
			'resource_url'         => $this->resource_url,
			'payload_fingerprint'  => $this->payload_fingerprint,
		);
	}

	/**
	 * Stable projection for observations and diagnostics.
	 *
	 * @return array{record_version: string, namespace: string, owner: string, logical_id: string, kind: string, symbolic_id: string, symbolic_url: string, payload_digest: string}
	 */
	public function symbolic_array(): array {
		return array(
			'record_version' => self::RECORD_VERSION,
			'namespace'      => $this->namespace,
			'owner'          => $this->owner,
			'logical_id'     => $this->logical_id,
			'kind'           => $this->kind,
			'symbolic_id'    => $this->symbolic_id(),
			'symbolic_url'   => $this->symbolic_url(),
			'payload_digest' => $this->payload_fingerprint,
		);
	}

	private static function assert_url( ?string $resource_url ): void {
		if ( null === $resource_url ) {
			return;
		}
		if ( '' === trim( $resource_url ) || preg_match( '/[\x00-\x1F\x7F]/', $resource_url ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture resource URL must be a safe runtime URL.' );
		}
		$parsed = parse_url( $resource_url );
		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) || isset( $parsed['user'], $parsed['pass'] ) || ! in_array( strtolower( (string) $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture resource URL must be a credential-free HTTP(S) URL.' );
		}
	}
}
