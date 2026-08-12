<?php
/**
 * One executable evidence link for an Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Links an evidence record to a stable executable ID, never to a prose path.
 */
final class AuthoringCapabilityEvidence {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	private function __construct(
		private readonly string $id,
		private readonly string $capability_id,
		private readonly AuthoringCapabilityEvidenceKind $kind,
		private readonly string $executable_id
	) {
	}

	public static function new(
		string $id,
		string $capability_id,
		AuthoringCapabilityEvidenceKind $kind,
		string $executable_id
	): self {
		$id            = trim( $id );
		$capability_id = trim( $capability_id );
		$executable_id = trim( $executable_id );
		foreach ( array( 'evidence' => $id, 'capability' => $capability_id, 'executable' => $executable_id ) as $label => $value ) {
			if ( 1 !== preg_match( self::ID_PATTERN, $value ) ) {
				$suffix = 'capability' === $label ? 'stable ID' : 'stable executable ID';
				throw new InvalidArgumentException( sprintf( 'Authoring %s ID "%s" must be a %s.', $label, $value, $suffix ) );
			}
		}

		return new self( $id, $capability_id, $kind, $executable_id );
	}

	public static function positive( string $id, string $capability_id, string $executable_id ): self {
		return self::new( $id, $capability_id, AuthoringCapabilityEvidenceKind::POSITIVE, $executable_id );
	}

	public static function negative( string $id, string $capability_id, string $executable_id ): self {
		return self::new( $id, $capability_id, AuthoringCapabilityEvidenceKind::NEGATIVE, $executable_id );
	}

	public static function recipe( string $id, string $capability_id, string $executable_id ): self {
		return self::new( $id, $capability_id, AuthoringCapabilityEvidenceKind::RECIPE, $executable_id );
	}

	public static function runtime( string $id, string $capability_id, string $executable_id ): self {
		return self::new( $id, $capability_id, AuthoringCapabilityEvidenceKind::RUNTIME, $executable_id );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'capability_id', 'executable_id', 'id', 'kind' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring evidence record must contain exactly capability_id, executable_id, id, and kind.' );
		}

		$id            = $record['id'];
		$capability_id = $record['capability_id'];
		$kind_value    = $record['kind'];
		$executable_id = $record['executable_id'];
		$kind          = is_string( $kind_value ) ? AuthoringCapabilityEvidenceKind::tryFrom( $kind_value ) : null;
		if ( ! is_string( $id ) || ! is_string( $capability_id ) || null === $kind || ! is_string( $executable_id ) ) {
			throw new InvalidArgumentException( 'Authoring evidence record has invalid field shapes.' );
		}

		$evidence = self::new( $id, $capability_id, $kind, $executable_id );
		if ( $evidence->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring evidence record must be a canonical projection.' );
		}

		return $evidence;
	}

	public function id(): string {
		return $this->id;
	}

	public function capability_id(): string {
		return $this->capability_id;
	}

	public function kind(): AuthoringCapabilityEvidenceKind {
		return $this->kind;
	}

	public function executable_id(): string {
		return $this->executable_id;
	}

	/**
	 * @return array{id: string, capability_id: string, kind: string, executable_id: string}
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'capability_id' => $this->capability_id,
			'kind'          => $this->kind->value,
			'executable_id' => $this->executable_id,
		);
	}
}
