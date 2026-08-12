<?php
/**
 * Ordered catalog of per-capability evidence requirements.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Keeps runtime applicability explicit and deterministic.
 */
final class AuthoringCapabilityEvidenceRequirementCatalog {

	/**
	 * @var array<int, AuthoringCapabilityEvidenceRequirement>
	 */
	private readonly array $requirements;

	/**
	 * @var array<string, AuthoringCapabilityEvidenceRequirement>
	 */
	private readonly array $requirements_by_capability;

	/**
	 * @param array<int, AuthoringCapabilityEvidenceRequirement> $requirements
	 */
	private function __construct( array $requirements ) {
		$by_capability = array();
		foreach ( $requirements as $requirement ) {
			if ( isset( $by_capability[ $requirement->capability_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring evidence requirements have duplicate capability ID "%s".', $requirement->capability_id() ) );
			}

			$by_capability[ $requirement->capability_id() ] = $requirement;
		}

		$this->requirements               = array_values( $requirements );
		$this->requirements_by_capability = $by_capability;
	}

	public static function from_declarations( AuthoringCapabilityEvidenceRequirement ...$requirements ): self {
		return new self( array_values( $requirements ) );
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'requirements' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement catalog must contain exactly requirements.' );
		}

		$records = $record['requirements'];
		if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement catalog requirements must be a list.' );
		}

		$requirements = array();
		foreach ( $records as $requirement_record ) {
			if ( ! is_array( $requirement_record ) ) {
				throw new InvalidArgumentException( 'Authoring evidence requirement entries must be object records.' );
			}

			$requirements[] = AuthoringCapabilityEvidenceRequirement::from_array( $requirement_record );
		}

		$catalog = self::from_declarations( ...$requirements );
		if ( $catalog->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring evidence requirement catalog must be a canonical projection.' );
		}

		return $catalog;
	}

	public function has( string $capability_id ): bool {
		return isset( $this->requirements_by_capability[ $capability_id ] );
	}

	public function for_capability( string $capability_id ): AuthoringCapabilityEvidenceRequirement {
		if ( ! isset( $this->requirements_by_capability[ $capability_id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring evidence requirements have no capability ID "%s".', $capability_id ) );
		}

		return $this->requirements_by_capability[ $capability_id ];
	}

	/**
	 * @return array<int, AuthoringCapabilityEvidenceRequirement>
	 */
	public function all(): array {
		return $this->requirements;
	}

	/**
	 * @return array{requirements: array<int, array{capability_id: string, required_evidence_kinds: array<int, string>}>}
	 */
	public function to_array(): array {
		return array(
			'requirements' => array_map(
				static fn ( AuthoringCapabilityEvidenceRequirement $requirement ): array => $requirement->to_array(),
				$this->requirements
			),
		);
	}
}
