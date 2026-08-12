<?php
/**
 * Shared validation rules for the Contract Lab manifest.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Keeps the manifest limited to stable, machine-readable maintainer data.
 */
final class ContractLabManifestSafety {

	private const STABLE_ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	private const STABLE_TOKEN_PATTERN = '/^[a-z][a-z0-9-]*$/D';

	private const VERSION_ATOM_PATTERN = '/^(?:[!<>=~^]{1,2})?(?:\*|[0-9]+(?:\.(?:[0-9]+|[xX*])){0,3}(?:-[0-9A-Za-z.-]+)?)$/D';

	private const FORBIDDEN_CONTENT_PATTERN = '/(?:license|secret|password|credential|token|api[\s_-]*key|private[\s_-]*key)/i';

	private function __construct() {
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @param array<int, string>      $expected
	 */
	public static function assert_exact_keys( array $record, array $expected, string $label ): void {
		$actual = array_keys( $record );
		$left   = $actual;
		$right  = $expected;
		sort( $left );
		sort( $right );
		if ( $left !== $right ) {
			throw new InvalidArgumentException( sprintf( '%s must contain exactly its canonical fields.', $label ) );
		}
	}

	/**
	 * Validate a Composer-like version constraint without allowing executable
	 * text, paths, URLs, or other opaque payloads.
	 */
	public static function assert_version_constraint( string $value, string $label ): void {
		if ( '' === $value || trim( $value ) !== $value ) {
			throw new InvalidArgumentException( sprintf( '%s must be a machine-checkable version constraint.', $label ) );
		}

		$clauses = preg_split( '/\s*\|\|\s*/', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $clauses || array() === $clauses ) {
			throw new InvalidArgumentException( sprintf( '%s must be a machine-checkable version constraint.', $label ) );
		}
		foreach ( $clauses as $clause ) {
			$atoms = preg_split( '/\s*,\s*|\s+/', trim( $clause ), -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $atoms || array() === $atoms ) {
				throw new InvalidArgumentException( sprintf( '%s must be a machine-checkable version constraint.', $label ) );
			}
			foreach ( $atoms as $atom ) {
				if ( 1 !== preg_match( self::VERSION_ATOM_PATTERN, $atom ) ) {
					throw new InvalidArgumentException( sprintf( '%s must be a machine-checkable version constraint.', $label ) );
				}
			}
		}
	}

	/**
	 * Validate a stable machine-readable identifier.
	 */
	public static function assert_stable_id( string $value, string $label ): void {
		self::assert_not_forbidden( $value );
		if ( '' === $value || trim( $value ) !== $value || 1 !== preg_match( self::STABLE_ID_PATTERN, $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a stable identifier.', $label ) );
		}
	}

	/**
	 * Validate one short token that cannot encode a site path or URL.
	 */
	public static function assert_stable_token( string $value, string $label ): void {
		self::assert_not_forbidden( $value );
		if ( '' === $value || trim( $value ) !== $value || 1 !== preg_match( self::STABLE_TOKEN_PATTERN, $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a stable token and must not contain a site path.', $label ) );
		}
	}

	/**
	 * Reject sensitive or proprietary material before it reaches a manifest
	 * projection. Typed fields provide the main boundary; this is a final
	 * content guard for identifiers and constraints.
	 */
	public static function assert_not_forbidden( string $value ): void {
		if ( 1 === preg_match( self::FORBIDDEN_CONTENT_PATTERN, $value ) ) {
			throw new InvalidArgumentException( 'Contract Lab manifest contains forbidden license or secret content.' );
		}
	}
}
