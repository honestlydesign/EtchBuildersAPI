<?php
/**
 * WordPress-compatible slug identity normalization boundary.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use InvalidArgumentException;

/**
 * Preserves WordPress normalization at runtime while allowing executable
 * Composer-only contracts to use identities that are already normalized.
 *
 * @internal
 */
final class SlugIdentityNormalizer {

	/**
	 * Normalize through WordPress or fail closed on non-normalized input.
	 */
	public static function normalize( string $slug, string $builder_name ): string {
		if ( \function_exists( 'sanitize_title' ) ) {
			return \sanitize_title( $slug );
		}

		if ( '' === $slug ) {
			return '';
		}

		if ( 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'%s slug must already be normalized when WordPress sanitize_title() is unavailable.',
					$builder_name
				)
			);
		}

		return $slug;
	}
}
