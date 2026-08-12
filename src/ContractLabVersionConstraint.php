<?php
/**
 * Evaluates the small version-constraint language accepted by the manifest.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Provides deterministic constraint checks without a runtime package change.
 */
final class ContractLabVersionConstraint {

	private const VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+){0,3}(?:-[0-9A-Za-z.-]+)?$/D';

	private function __construct() {
	}

	/**
	 * Validate one installed runtime version.
	 */
	public static function assert_version( string $version, string $label ): void {
		if ( '' === $version || trim( $version ) !== $version || 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a machine-readable runtime version.', $label ) );
		}
	}

	/**
	 * Return whether a runtime version satisfies all AND clauses in one OR
	 * branch of the manifest constraint.
	 */
	public static function matches( string $constraint, string $version ): bool {
		ContractLabManifestSafety::assert_version_constraint( $constraint, 'Contract Lab version constraint' );
		self::assert_version( $version, 'Contract Lab runtime version' );

		$clauses = preg_split( '/\s*\|\|\s*/', $constraint, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $clauses ) {
			return false;
		}
		foreach ( $clauses as $clause ) {
			$atoms = preg_split( '/\s*,\s*|\s+/', trim( $clause ), -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $atoms || array() === $atoms ) {
				continue;
			}
			$matches = true;
			foreach ( $atoms as $atom ) {
				if ( ! self::matches_atom( $atom, $version ) ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				return true;
			}
		}

		return false;
	}

	private static function matches_atom( string $atom, string $version ): bool {
		if ( '*' === $atom ) {
			return true;
		}
		if ( 1 !== preg_match( '/^(>=|<=|!=|>|<|=|\^|~)?(.+)$/D', $atom, $matches ) ) {
			return false;
		}

		$operator = $matches[1];
		$base     = $matches[2];
		if ( 1 !== preg_match( '/^[0-9]+(?:\.(?:[0-9]+|[xX*])){0,3}(?:-[0-9A-Za-z.-]+)?$/D', $base ) ) {
			return false;
		}
		if ( str_contains( $base, '*' ) || str_contains( strtolower( $base ), 'x' ) ) {
			if ( '' !== $operator && '=' !== $operator ) {
				return false;
			}
			$prefix = rtrim( str_replace( array( 'X', 'x', '*' ), '', $base ), '.' );

			return '' !== $prefix && ( $version === $prefix || str_starts_with( $version, $prefix . '.' ) );
		}

		$normalized_version = self::normalize_version( $version );
		$normalized_base    = self::normalize_version( $base );
		if ( '^' === $operator || '~' === $operator ) {
			$upper = self::upper_bound( $normalized_base, '~' === $operator, substr_count( explode( '-', $base )[0], '.' ) + 1 );

			return version_compare( $normalized_version, $normalized_base, '>=' ) && version_compare( $normalized_version, $upper, '<' );
		}

		return match ( $operator ) {
			'>=' => version_compare( $normalized_version, $normalized_base, '>=' ),
			'<=' => version_compare( $normalized_version, $normalized_base, '<=' ),
			'>'  => version_compare( $normalized_version, $normalized_base, '>' ),
			'<'  => version_compare( $normalized_version, $normalized_base, '<' ),
			'!=' => version_compare( $normalized_version, $normalized_base, '!=' ),
			default => version_compare( $normalized_version, $normalized_base, '=' ),
		};
	}

	private static function normalize_version( string $version ): string {
		$parts       = explode( '-', $version, 2 );
		$numeric     = array_map( 'intval', explode( '.', $parts[0] ) );
		$numeric     = array_pad( $numeric, 3, 0 );
		$normalized  = implode( '.', array_slice( $numeric, 0, 3 ) );

		return isset( $parts[1] ) ? $normalized . '-' . $parts[1] : $normalized;
	}

	private static function upper_bound( string $base, bool $tilde, int $component_count ): string {
		$parts = array_map( 'intval', explode( '.', $base ) );
		if ( $tilde && $component_count >= 3 ) {
			$parts[1]++;
			$parts[2] = 0;
		} elseif ( $parts[0] > 0 || count( $parts ) <= 1 ) {
			$parts[0]++;
			$parts[1] = 0;
			$parts[2] = 0;
		} elseif ( $parts[1] > 0 ) {
			$parts[1]++;
			$parts[2] = 0;
		} else {
			$parts[2]++;
		}

		return implode( '.', array_slice( array_pad( $parts, 3, 0 ), 0, 3 ) );
	}
}
