<?php
/**
 * Immutable normalized record accepted by the persistence store.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * A JSON-like record with stable identity and content fingerprint.
 */
final class SitePersistenceRecord {

	/**
	 * @param array<string, mixed> $payload
	 */
	private function __construct(
		private readonly string $identity,
		private readonly string $kind,
		private readonly array $payload,
		private readonly bool $owned,
		private readonly string $fingerprint_hash
	) {
	}

	/**
	 * Normalize one compiled entity for persistence.
	 */
	public static function from_entity( CompiledSiteEntity $entity, bool $owned = true ): self {
		return self::from_values( $entity->identity(), $entity->type()->value, $entity->payload(), $owned );
	}

	/**
	 * Normalize one compiled resource for persistence.
	 */
	public static function from_resource( CompiledSiteResource $resource, bool $owned = true ): self {
		return self::from_values( $resource->identity(), $resource->type()->value, $resource->payload(), $owned );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function from_values( string $identity, string $kind, array $payload, bool $owned ): self {
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Site persistence payload must contain only scalar, null, or nested array values.' );

		return new self( $identity, $kind, $payload, $owned, self::make_fingerprint( $kind, $payload ) );
	}

	public function identity(): string {
		return $this->identity;
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

	public function is_owned(): bool {
		return $this->owned;
	}

	public function fingerprint(): string {
		return $this->fingerprint_hash;
	}

	/**
	 * @return array{identity: string, kind: string, payload: array<string, mixed>, owned: bool, fingerprint: string}
	 */
	public function to_array(): array {
		return array(
			'identity'   => $this->identity,
			'kind'       => $this->kind,
			'payload'    => $this->payload,
			'owned'      => $this->owned,
			'fingerprint' => $this->fingerprint_hash,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function make_fingerprint( string $kind, array $payload ): string {
		$canonical = ImmutableArray::canonicalize( $payload );
		$encoded   = json_encode( array( 'kind' => $kind, 'payload' => $canonical ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );

		return hash( 'sha256', $encoded );
	}

}
