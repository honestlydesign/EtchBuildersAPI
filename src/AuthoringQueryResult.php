<?php
/**
 * Immutable result returned by an Authoring Query command.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use HonestlyDesign\EtchBuilders\Support\Json;
use InvalidArgumentException;

/**
 * Keeps query output JSON-safe, defensive, and stable for local agents.
 */
final class AuthoringQueryResult {

	/**
	 * @param array<string, mixed> $record
	 */
	private function __construct( private readonly array $record ) {
	}

	/**
	 * Build one result from a scalar/nested-array command projection.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_record( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$record = ImmutableArray::copy( $record, 'Authoring Query results must contain only scalar or nested array values.' );
		if ( ! isset( $record['status'] ) || ! is_string( $record['status'] ) || '' === $record['status'] ) {
			throw new InvalidArgumentException( 'Authoring Query results require a non-empty status.' );
		}

		return new self( $record );
	}

	public function status(): string {
		return $this->record['status'];
	}

	/**
	 * Return a defensive machine-readable projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy( $this->record, 'Authoring Query results must contain only scalar or nested array values.' );
	}

	/**
	 * Encode the same projection used by to_array().
	 */
	public function to_json(): string {
		return Json::encode( $this->record );
	}
}
