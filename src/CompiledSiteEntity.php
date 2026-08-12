<?php
/**
 * One serialized Site Entity in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Immutable identity plus serialized payload for one compiled entity.
 */
final class CompiledSiteEntity {

	private function __construct(
		private readonly CompiledSiteEntityType $type,
		private readonly string $identity,
		private readonly array $payload
	) {
	}

	/**
	 * Create an entity record for a no-write compiled plan.
	 *
	 * @param CompiledSiteEntityType $type     Entity category.
	 * @param string                 $identity Stable type:key identity.
	 * @param array<string, mixed>   $payload  Serialized entity payload.
	 */
	public static function new( CompiledSiteEntityType $type, string $identity, array $payload ): self {
		$identity = self::validate_identity( $identity, $type );
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = self::freeze_payload( $payload );

		return new self( $type, $identity, $payload );
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

	/**
	 * @return array{type: string, identity: string, payload: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'type'     => $this->type->value,
			'identity' => $this->identity,
			'payload'  => $this->payload,
		);
	}

	private static function validate_identity( string $identity, CompiledSiteEntityType $type ): string {
		$identity = trim( $identity );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $identity ) ) {
			throw new InvalidArgumentException( 'Compiled Site Entity identity must be a stable type:key value.' );
		}
		if ( ! str_starts_with( $identity, $type->value . ':' ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site Entity identity must use the "%s:" namespace.', $type->value ) );
		}

		return $identity;
	}

	/**
	 * Copy only immutable JSON-like values into the plan snapshot.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function freeze_payload( array $payload ): array {
		$frozen = array();
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$frozen[ $key ] = self::freeze_payload( $value );
				continue;
			}
			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
				$frozen[ $key ] = $value;
				continue;
			}

			throw new InvalidArgumentException( 'Compiled Site Entity payload must contain only scalar, null, or nested array values.' );
		}

		return $frozen;
	}
}
