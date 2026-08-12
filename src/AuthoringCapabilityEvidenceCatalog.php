<?php
/**
 * Ordered catalog of executable Authoring Capability evidence links.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Detects duplicate evidence identities before capability-level validation.
 */
final class AuthoringCapabilityEvidenceCatalog {

	/**
	 * @var array<int, AuthoringCapabilityEvidence>
	 */
	private readonly array $evidence;

	/**
	 * @var array<string, AuthoringCapabilityEvidence>
	 */
	private readonly array $evidence_by_id;

	/**
	 * @param array<int, AuthoringCapabilityEvidence> $evidence
	 */
	private function __construct( array $evidence ) {
		$by_id             = array();
		$executable_id_map = array();
		foreach ( $evidence as $record ) {
			if ( isset( $by_id[ $record->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring evidence catalog has duplicate evidence ID "%s".', $record->id() ) );
			}

			$duplicate_key = $record->capability_id() . ':' . $record->kind()->value . ':' . $record->executable_id();
			if ( isset( $executable_id_map[ $duplicate_key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring evidence catalog has duplicate executable evidence "%s".', $record->executable_id() ) );
			}

			$by_id[ $record->id() ]             = $record;
			$executable_id_map[ $duplicate_key ] = true;
		}

		$this->evidence       = array_values( $evidence );
		$this->evidence_by_id = $by_id;
	}

	public static function from_declarations( AuthoringCapabilityEvidence ...$evidence ): self {
		return new self( array_values( $evidence ) );
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
		if ( array( 'evidence' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring evidence catalog must contain exactly evidence.' );
		}

		$records = $record['evidence'];
		if ( ! is_array( $records ) || ! array_is_list( $records ) ) {
			throw new InvalidArgumentException( 'Authoring evidence catalog evidence must be a list.' );
		}

		$evidence = array();
		foreach ( $records as $evidence_record ) {
			if ( ! is_array( $evidence_record ) ) {
				throw new InvalidArgumentException( 'Authoring evidence catalog entries must be object records.' );
			}

			$evidence[] = AuthoringCapabilityEvidence::from_array( $evidence_record );
		}

		$catalog = self::from_declarations( ...$evidence );
		if ( $catalog->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring evidence catalog must be a canonical projection.' );
		}

		return $catalog;
	}

	public function has( string $id ): bool {
		return isset( $this->evidence_by_id[ $id ] );
	}

	public function evidence( string $id ): AuthoringCapabilityEvidence {
		if ( ! isset( $this->evidence_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring evidence catalog has no evidence ID "%s".', $id ) );
		}

		return $this->evidence_by_id[ $id ];
	}

	/**
	 * @return array<int, AuthoringCapabilityEvidence>
	 */
	public function all(): array {
		return $this->evidence;
	}

	/**
	 * @return array{evidence: array<int, array{id: string, capability_id: string, kind: string, executable_id: string}>}
	 */
	public function to_array(): array {
		return array(
			'evidence' => array_map(
				static fn ( AuthoringCapabilityEvidence $record ): array => $record->to_array(),
				$this->evidence
			),
		);
	}
}
