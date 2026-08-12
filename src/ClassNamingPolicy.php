<?php
/**
 * Naming policy for typed site-owned presentation classes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Validates flat BEM roots without inferring project prefixes or ownership.
 */
final class ClassNamingPolicy {

	private const BEM_ROOT_PATTERN = '/^[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*(?:(?:__|--)[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*)?(?:--[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*)?$/';

	private const GENERIC_STATE_PATTERN = '/^(?:is|has|js)-[A-Za-z][A-Za-z0-9]*(?:-[A-Za-z0-9]+)*$/';

	/**
	 * Validate one site-owned presentation token and return it unchanged.
	 *
	 * @throws InvalidArgumentException When the value is not a flat BEM root.
	 */
	public static function assert_site_presentation( string $token ): string {
		if ( 1 === preg_match( self::GENERIC_STATE_PATTERN, $token ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Site presentation class "%s" is a generic state class. Use semantic BEM styling or explicit runtime provenance.', $token )
			);
		}

		if ( 1 !== preg_match( self::BEM_ROOT_PATTERN, $token ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Site presentation class "%s" must be one flat BEM root such as hero, hero__title, hero--featured, or hero__title--featured.', $token )
			);
		}

		return $token;
	}

	/**
	 * Whether a token is a valid site-owned presentation root.
	 */
	public static function is_site_presentation( string $token ): bool {
		try {
			self::assert_site_presentation( $token );
			return true;
		} catch ( InvalidArgumentException $exception ) {
			return false;
		}
	}

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}
}
