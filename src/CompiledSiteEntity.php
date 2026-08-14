<?php
/**
 * One serialized Site Entity in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Immutable identity plus serialized payload for one compiled entity.
 */
final class CompiledSiteEntity {

	private function __construct(
		private readonly CompiledSiteEntityType $type,
		private readonly string $identity,
		private readonly array $payload,
		private readonly CompiledSiteEntityPersistenceIntent $persistence_intent
	) {
	}

	/**
	 * Create an entity record for a no-write compiled plan.
	 *
	 * @param CompiledSiteEntityType $type     Entity category.
	 * @param string                 $identity Stable type:key identity.
	 * @param array<string, mixed>   $payload  Serialized entity payload.
	 */
	public static function new(
		CompiledSiteEntityType $type,
		string $identity,
		array $payload,
		CompiledSiteEntityPersistenceIntent $persistence_intent = CompiledSiteEntityPersistenceIntent::MANAGED
	): self {
		$identity = self::validate_identity( $identity, $type );
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Compiled Site Entity payload must contain only scalar, null, or nested array values.' );
		if ( CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE === $persistence_intent && CompiledSiteEntityType::LOOP_PRESET !== $type ) {
			throw new InvalidArgumentException( 'Only a loop preset can currently be a verified native Site dependency.' );
		}

		return new self( $type, $identity, $payload, $persistence_intent );
	}

	public function type(): CompiledSiteEntityType {
		return $this->type;
	}

	public function identity(): string {
		return $this->identity;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	public function persistence_intent(): CompiledSiteEntityPersistenceIntent {
		return $this->persistence_intent;
	}

	/**
	 * @return array{type: string, identity: string, payload: array<string, mixed>, persistence_intent?: string}
	 */
	public function to_array(): array {
		$record = array(
			'type'     => $this->type->value,
			'identity' => $this->identity,
			'payload'  => $this->payload,
		);
		if ( CompiledSiteEntityPersistenceIntent::MANAGED !== $this->persistence_intent ) {
			$record['persistence_intent'] = $this->persistence_intent->value;
		}

		return $record;
	}

	private static function validate_identity( string $identity, CompiledSiteEntityType $type ): string {
		$identity = trim( $identity );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+$/D', $identity ) ) {
			throw new InvalidArgumentException( 'Compiled Site Entity identity must be a stable type:key value.' );
		}
		if ( ! str_starts_with( $identity, $type->value . ':' ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site Entity identity must use the "%s:" namespace.', $type->value ) );
		}

		return $identity;
	}

}
