<?php
/**
 * Evidence classes required for one Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Makes runtime evidence applicability explicit while keeping the admission
 * baseline positive, negative, and recipe evidence for every intent.
 */
final class AuthoringCapabilityEvidenceRequirement {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	/**
	 * @param array<int, AuthoringCapabilityEvidenceKind> $required_kinds
	 */
	private function __construct(
		private readonly string $capability_id,
		private readonly array $required_kinds
	) {
	}

	public static function for_capability( string $capability_id, bool $runtime_required = false ): self {
		$capability_id = trim( $capability_id );
		if ( 1 !== preg_match( self::ID_PATTERN, $capability_id ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring evidence requirement capability ID "%s" must be stable.', $capability_id ) );
		}

		$required_kinds = array(
			AuthoringCapabilityEvidenceKind::POSITIVE,
			AuthoringCapabilityEvidenceKind::NEGATIVE,
			AuthoringCapabilityEvidenceKind::RECIPE,
		);
		if ( $runtime_required ) {
			$required_kinds[] = AuthoringCapabilityEvidenceKind::RUNTIME;
		}

		return new self( $capability_id, $required_kinds );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'capability_id', 'required_evidence_kinds' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement must contain exactly capability_id and required_evidence_kinds.' );
		}

		$capability_id = $record['capability_id'];
		$kind_values   = $record['required_evidence_kinds'];
		if ( ! is_string( $capability_id ) || ! is_array( $kind_values ) || ! array_is_list( $kind_values ) ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement has invalid field shapes.' );
		}

		$kinds = array();
		foreach ( $kind_values as $kind_value ) {
			$kind = is_string( $kind_value ) ? AuthoringCapabilityEvidenceKind::tryFrom( $kind_value ) : null;
			if ( null === $kind ) {
				throw new InvalidArgumentException( 'Authoring evidence requirement has an unknown evidence kind.' );
			}

			$kinds[] = $kind;
		}

		$runtime_required = in_array( AuthoringCapabilityEvidenceKind::RUNTIME, $kinds, true );
		$requirement      = self::for_capability( $capability_id, $runtime_required );
		if ( $requirement->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement must declare the canonical evidence classes.' );
		}

		return $requirement;
	}

	public function capability_id(): string {
		return $this->capability_id;
	}

	/**
	 * @return array<int, AuthoringCapabilityEvidenceKind>
	 */
	public function required_kinds(): array {
		return $this->required_kinds;
	}

	/**
	 * @return array{capability_id: string, required_evidence_kinds: array<int, string>}
	 */
	public function to_array(): array {
		return array(
			'capability_id'          => $this->capability_id,
			'required_evidence_kinds' => array_map(
				static fn ( AuthoringCapabilityEvidenceKind $kind ): string => $kind->value,
				$this->required_kinds
			),
		);
	}
}
