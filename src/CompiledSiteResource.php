<?php
/**
 * One compiled style or asset resource.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Immutable resource identity plus serialized resource payload.
 */
final class CompiledSiteResource {

	private function __construct(
		private readonly CompiledSiteResourceType $type,
		private readonly string $identity,
		private readonly array $payload
	) {
	}

	/**
	 * Create one compiled resource record.
	 *
	 * @param CompiledSiteResourceType $type Resource category.
	 * @param string                   $identity Stable resource identity.
	 * @param array<string, mixed>     $payload Serialized resource payload.
	 */
	public static function new( CompiledSiteResourceType $type, string $identity, array $payload ): self {
		$identity = trim( $identity );
		if ( '' === $identity || 1 !== preg_match( '/^[a-z][a-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+$/D', $identity ) ) {
			throw new InvalidArgumentException( 'Compiled Site resource identity must be a stable type:key value.' );
		}
		if ( ! str_starts_with( $identity, $type->value . ':' ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site resource identity must use the "%s:" namespace.', $type->value ) );
		}
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Compiled Site resource payload must contain only scalar, null, or nested array values.' );

		return new self( $type, $identity, $payload );
	}

	public function type(): CompiledSiteResourceType {
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

}
