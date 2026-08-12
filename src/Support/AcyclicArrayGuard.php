<?php
/**
 * Cycle and depth guard for accepted PHP array graphs.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use InvalidArgumentException;
use ReflectionReference;

/**
 * Rejects recursive arrays before public hydrators perform recursive work.
 */
final class AcyclicArrayGuard {

	private function __construct() {
	}

	/**
	 * @param array<mixed> $value Candidate array graph.
	 */
	public static function assert_acyclic( array $value ): void {
		self::walk( $value, array(), 0 );
	}

	/**
	 * @param array<mixed>        $value Array node.
	 * @param array<string, true> $active_references Reference IDs on the active path.
	 */
	private static function walk( array $value, array $active_references, int $depth ): void {
		if ( $depth > 512 ) {
			throw new InvalidArgumentException( 'Accepted catalogs must contain finite, non-recursive arrays.' );
		}

		foreach ( array_keys( $value ) as $key ) {
			$child_references = $active_references;
			$reference        = ReflectionReference::fromArrayElement( $value, $key );
			if ( null !== $reference ) {
				$reference_id = bin2hex( $reference->getId() );
				if ( isset( $active_references[ $reference_id ] ) ) {
					throw new InvalidArgumentException( 'Accepted catalogs must contain finite, non-recursive arrays.' );
				}
				$child_references[ $reference_id ] = true;
			}

			if ( is_array( $value[ $key ] ) ) {
				self::walk( $value[ $key ], $child_references, $depth + 1 );
			}
		}
	}
}
