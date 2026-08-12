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

	private const LOCAL_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

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
		self::assert_not_forbidden( $value );
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
	 * Validate a LocalWP identifier while preserving its case.
	 */
	public static function assert_local_identifier( string $value, string $label ): void {
		self::assert_not_forbidden( $value );
		if ( '' === $value || trim( $value ) !== $value || 1 !== preg_match( self::LOCAL_IDENTIFIER_PATTERN, $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a stable LocalWP identifier.', $label ) );
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
	 * Normalize one credential-free HTTP(S) origin.
	 */
	public static function normalize_origin( string $value, string $label ): string {
		if ( '' === $value || trim( $value ) !== $value || 1 === preg_match( '/[\x00-\x20]/', $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a credential-free HTTP(S) origin.', $label ) );
		}
		$parsed = parse_url( $value );
		if ( ! is_array( $parsed ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a credential-free HTTP(S) origin.', $label ) );
		}
		$scheme = $parsed['scheme'] ?? null;
		$host   = $parsed['host'] ?? null;
		if ( ! is_string( $scheme ) || ! is_string( $host ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a credential-free HTTP(S) origin.', $label ) );
		}
		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			throw new InvalidArgumentException( sprintf( '%s must not contain credentials.', $label ) );
		}
		if ( isset( $parsed['path'] ) && '/' !== $parsed['path'] && '' !== $parsed['path'] ) {
			throw new InvalidArgumentException( sprintf( '%s must be an origin without a site path.', $label ) );
		}
		if ( isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be an origin without a query or fragment.', $label ) );
		}

		return rtrim( $value, '/' );
	}

	/**
	 * Normalize one absolute path without traversal or URL syntax.
	 */
	public static function normalize_absolute_path( string $value, string $label ): string {
		self::assert_not_forbidden( $value );
		if ( '' === $value || trim( $value ) !== $value || ! str_starts_with( $value, '/' ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || str_contains( $value, '://' ) || 1 === preg_match( '#(?:^|/)\.\.?(?:/|$)#', $value ) || str_contains( $value, '//' ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be an absolute normalized path without traversal.', $label ) );
		}

		$normalized = rtrim( $value, '/' );

		return '' === $normalized ? '/' : $normalized;
	}

	/**
	 * Validate a content fingerprint without accepting a path or opaque blob.
	 */
	public static function assert_digest( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^(?:sha256:)?[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( sprintf( '%s must be a lowercase SHA-256 digest.', $label ) );
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
