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
use InvalidArgumentException;

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
		private readonly array $ownership,
		private readonly string $fingerprint_hash
	) {
	}

	/**
	 * Normalize one compiled entity for persistence.
	 */
	public static function from_entity( CompiledSiteEntity $entity, bool $owned = true, array $ownership = array() ): self {
		return self::from_values( $entity->identity(), $entity->type()->value, $entity->payload(), $owned, $ownership );
	}

	/**
	 * Normalize one compiled resource for persistence.
	 */
	public static function from_resource( CompiledSiteResource $resource, bool $owned = true, array $ownership = array() ): self {
		return self::from_values( $resource->identity(), $resource->type()->value, $resource->payload(), $owned, $ownership );
	}

	/**
	 * Normalize the explicit front-page policy section for persistence.
	 */
	public static function from_home_policy( SiteHomePolicy $policy, bool $owned = true ): self {
		return self::from_values( 'home_policy:front_page', 'home_policy', $policy->to_array(), $owned );
	}

	/**
	 * Rehydrate one adapter-owned serialized record.
	 *
	 * @param array<int, CompiledSiteOwnership> $ownership
	 */
	public static function from_serialized( string $identity, string $kind, array $payload, bool $owned, array $ownership = array() ): self {
		return self::from_values( $identity, $kind, $payload, $owned, $ownership );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function from_values( string $identity, string $kind, array $payload, bool $owned, array $ownership = array() ): self {
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Site persistence payload must contain only scalar, null, or nested array values.' );
		$ownership = self::normalize_ownership( $identity, $ownership );

		return new self( $identity, $kind, $payload, $owned, $ownership, self::make_fingerprint( $kind, $payload, $ownership ) );
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

	/**
	 * Return the exact plan ownership edges attached to this resource.
	 *
	 * @return array<int, CompiledSiteOwnership>
	 */
	public function ownership(): array {
		return $this->ownership;
	}

	public function fingerprint(): string {
		return $this->fingerprint_hash;
	}

	/**
	 * @return array{identity: string, kind: string, payload: array<string, mixed>, owned: bool, ownership: array<int, array{owner: string, resource: string, role: string}>, fingerprint: string}
	 */
	public function to_array(): array {
		return array(
			'identity'   => $this->identity,
			'kind'       => $this->kind,
			'payload'    => $this->payload,
			'owned'      => $this->owned,
			'ownership'  => array_map( static fn ( CompiledSiteOwnership $edge ): array => $edge->to_array(), $this->ownership ),
			'fingerprint' => $this->fingerprint_hash,
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function make_fingerprint( string $kind, array $payload, array $ownership ): string {
		$canonical = ImmutableArray::canonicalize( $payload );
		$ownership_projection = array_map(
			static fn ( CompiledSiteOwnership $edge ): array => $edge->to_array(),
			$ownership
		);
		$encoded = json_encode(
			array(
				'kind'       => $kind,
				'payload'    => $canonical,
				'ownership'  => ImmutableArray::canonicalize( $ownership_projection ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		);

		return hash( 'sha256', $encoded );
	}

	/**
	 * @param array<int, mixed> $ownership
	 * @return array<int, CompiledSiteOwnership>
	 */
	private static function normalize_ownership( string $identity, array $ownership ): array {
		if ( ! array_is_list( $ownership ) ) {
			throw new InvalidArgumentException( 'Site persistence ownership must be a list.' );
		}

		$normalized = array();
		$seen       = array();
		foreach ( $ownership as $edge ) {
			if ( ! $edge instanceof CompiledSiteOwnership ) {
				throw new InvalidArgumentException( 'Site persistence ownership must contain CompiledSiteOwnership values.' );
			}
			if ( $edge->resource_identity() !== $identity ) {
				throw new InvalidArgumentException( 'Site persistence ownership must reference its record identity.' );
			}

			$key = $edge->owner_identity() . '>' . $edge->resource_identity() . ':' . $edge->role();
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'Site persistence ownership contains a duplicate edge.' );
			}
			$seen[ $key ] = true;
			$normalized[]  = $edge;
		}

		return $normalized;
	}

}
