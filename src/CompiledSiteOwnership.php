<?php
/**
 * One explicit ownership edge in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Records which entity owns a compiled resource and why.
 */
final class CompiledSiteOwnership {

	private function __construct(
		private readonly string $owner_identity,
		private readonly string $resource_identity,
		private readonly string $role
	) {
	}

	/**
	 * Create one ownership edge.
	 */
	public static function new( string $owner_identity, string $resource_identity, string $role ): self {
		return new self(
			self::validate_identity( $owner_identity, 'owner' ),
			self::validate_identity( $resource_identity, 'resource' ),
			self::validate_role( $role )
		);
	}

	public function owner_identity(): string {
		return $this->owner_identity;
	}

	public function resource_identity(): string {
		return $this->resource_identity;
	}

	public function role(): string {
		return $this->role;
	}

	/**
	 * @return array{owner: string, resource: string, role: string}
	 */
	public function to_array(): array {
		return array(
			'owner'    => $this->owner_identity,
			'resource' => $this->resource_identity,
			'role'     => $this->role,
		);
	}

	private static function validate_identity( string $identity, string $label ): string {
		$identity = trim( $identity );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+$/D', $identity ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site ownership %s identity must be a stable type:key value.', $label ) );
		}

		return $identity;
	}

	private static function validate_role( string $role ): string {
		$role = trim( $role );
		if ( '' === $role || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/D', $role ) ) {
			throw new InvalidArgumentException( 'Compiled Site ownership role must be a non-empty lowercase identifier.' );
		}

		return $role;
	}
}
