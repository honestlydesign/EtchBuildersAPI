<?php
/**
 * Blocks input path guard helpers.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

/**
 * Determines whether a blocks input string is safe to probe as a local path.
 */
final class BlocksInputPathGuard {

	/**
	 * Maximum path length we will probe with filesystem checks.
	 */
	private const MAX_PATH_LENGTH = 4096;

	/**
	 * Check whether the provided blocks input is a plausible local file path.
	 *
	 * @param string $blocks_or_path Raw Gutenberg HTML or local file path.
	 */
	public static function is_path_candidate( string $blocks_or_path ): bool {
		$blocks_or_path = trim( $blocks_or_path );

		if ( '' === $blocks_or_path ) {
			return false;
		}

		if ( strlen( $blocks_or_path ) >= self::MAX_PATH_LENGTH ) {
			return false;
		}

		if ( str_contains( $blocks_or_path, '<!-- wp:' ) || str_contains( $blocks_or_path, '<!-- /wp:' ) ) {
			return false;
		}

		if ( str_contains( $blocks_or_path, '<' ) || str_contains( $blocks_or_path, '>' ) ) {
			return false;
		}

		if ( str_contains( $blocks_or_path, "\n" ) || str_contains( $blocks_or_path, "\r" ) ) {
			return false;
		}

		return true;
	}
}
