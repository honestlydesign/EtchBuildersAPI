<?php
/**
 * Scans CSS selector blocks for StylesParser and StylesValidator.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Shared low-level scanner for comment-free StylesParser CSS.
 */
final class StylesParserRuleScanner {

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Scan root-level style rules in file order.
	 *
	 * @param string $content CSS content.
	 * @return array<int, array{selector: string, css: string, start: int, end: int}>
	 */
	public static function scan_style_rules( string $content ): array {
		$rules  = array();
		$length = strlen( $content );
		$pos    = 0;

		while ( $pos < $length ) {
			$pos = self::skip_root_whitespace_and_comments( $content, $pos, $length );
			if ( $pos >= $length ) {
				break;
			}

			$selector_start = $pos;
			$brace_pos      = self::find_next_root_opening_brace( $content, $pos, $length );
			if ( null === $brace_pos ) {
				break;
			}

			$statement_end = self::find_next_root_statement_end( $content, $pos, $brace_pos );
			if ( null !== $statement_end ) {
				$pos = $statement_end + 1;
				continue;
			}

			$css_result = self::extract_brace_content( $content, $brace_pos + 1, $length );
			if ( null === $css_result ) {
				break;
			}

			list( $css, $new_pos ) = $css_result;
			$rules[]               = array(
				'selector' => trim( substr( $content, $selector_start, $brace_pos - $selector_start ) ),
				'css'      => $css,
				'start'    => $selector_start,
				'end'      => $new_pos,
			);
			$pos                   = $new_pos;
		}

		return $rules;
	}

	/**
	 * Normalize a selector for identity comparison.
	 *
	 * @param string $selector CSS selector.
	 */
	public static function normalize_selector_key( string $selector ): string {
		$result        = '';
		$length        = strlen( $selector );
		$pos           = 0;
		$pending_space = false;
		$in_string     = false;
		$string_char   = '';

		while ( $pos < $length ) {
			$char = $selector[ $pos ];

			if ( $in_string ) {
				$result .= $char;

				if ( '\\' === $char && $pos + 1 < $length ) {
					++$pos;
					$result .= $selector[ $pos ];
				} elseif ( $char === $string_char ) {
					$in_string = false;
				}

				++$pos;
				continue;
			}

			if ( self::starts_with_at( $selector, $pos, '/*' ) ) {
				$comment_end = strpos( $selector, '*/', $pos + 2 );
				if ( false === $comment_end ) {
					break;
				}

				$pending_space = true;
				$pos           = $comment_end + 2;
				continue;
			}

			if ( ctype_space( $char ) ) {
				$pending_space = true;
				++$pos;
				continue;
			}

			if ( ',' === $char ) {
				$result        = rtrim( $result ) . ', ';
				$pending_space = false;
				++$pos;
				continue;
			}

			if ( '>' === $char || '+' === $char || '~' === $char ) {
				$result        = rtrim( $result ) . ' ' . $char . ' ';
				$pending_space = false;
				++$pos;
				continue;
			}

			if ( $pending_space && '' !== $result && ! str_ends_with( $result, ' ' ) ) {
				$result .= ' ';
			}
			$pending_space = false;

			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
			}

			$result .= $char;
			++$pos;
		}

		return rtrim( trim( $result ), ',' );
	}

	/**
	 * Return the class token when a selector is exactly one class selector.
	 *
	 * @param string $selector CSS selector.
	 */
	public static function single_class_token( string $selector ): ?string {
		$selector = self::normalize_selector_key( $selector );

		if ( 1 !== preg_match( '/^\.([A-Za-z][A-Za-z0-9_-]*)$/', $selector, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Generate a deterministic code-owned style ID for a non-single-class selector.
	 *
	 * @param string $selector CSS selector.
	 */
	public static function generated_style_id_for_selector( string $selector ): string {
		return 'omide-style-' . substr( sha1( self::normalize_selector_key( $selector ) ), 0, 12 );
	}

	/**
	 * Skip whitespace and complete block comments at root level.
	 *
	 * @param string $content CSS content.
	 * @param int    $pos     Current offset.
	 * @param int    $length  Content length.
	 */
	private static function skip_root_whitespace_and_comments( string $content, int $pos, int $length ): int {
		while ( $pos < $length ) {
			if ( ctype_space( $content[ $pos ] ) ) {
				++$pos;
				continue;
			}

			if ( self::starts_with_at( $content, $pos, '/*' ) ) {
				$comment_end = strpos( $content, '*/', $pos + 2 );
				if ( false === $comment_end ) {
					return $length;
				}

				$pos = $comment_end + 2;
				continue;
			}

			break;
		}

		return $pos;
	}

	/**
	 * Find the next root-level opening brace after a selector.
	 *
	 * @param string $content CSS content.
	 * @param int    $pos     Starting offset.
	 * @param int    $length  Content length.
	 */
	private static function find_next_root_opening_brace( string $content, int $pos, int $length ): ?int {
		$in_string   = false;
		$string_char = '';
		$in_comment  = false;

		while ( $pos < $length ) {
			$char = $content[ $pos ];

			if ( $in_comment ) {
				if ( self::starts_with_at( $content, $pos, '*/' ) ) {
					$in_comment = false;
					$pos       += 2;
					continue;
				}

				++$pos;
				continue;
			}

			if ( $in_string ) {
				if ( '\\' === $char && $pos + 1 < $length ) {
					$pos += 2;
					continue;
				}

				if ( $char === $string_char ) {
					$in_string = false;
				}

				++$pos;
				continue;
			}

			if ( self::starts_with_at( $content, $pos, '/*' ) ) {
				$in_comment = true;
				$pos       += 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
				++$pos;
				continue;
			}

			if ( '{' === $char ) {
				return $pos;
			}

			++$pos;
		}

		return null;
	}

	/**
	 * Find a root statement semicolon before a style block opening brace.
	 *
	 * @param string $content   CSS content.
	 * @param int    $pos       Starting offset.
	 * @param int    $brace_pos Offset of the next opening brace.
	 */
	private static function find_next_root_statement_end( string $content, int $pos, int $brace_pos ): ?int {
		$in_string   = false;
		$string_char = '';
		$in_comment  = false;

		while ( $pos < $brace_pos ) {
			$char = $content[ $pos ];

			if ( $in_comment ) {
				if ( self::starts_with_at( $content, $pos, '*/' ) ) {
					$in_comment = false;
					$pos       += 2;
					continue;
				}

				++$pos;
				continue;
			}

			if ( $in_string ) {
				if ( '\\' === $char && $pos + 1 < $brace_pos ) {
					$pos += 2;
					continue;
				}

				if ( $char === $string_char ) {
					$in_string = false;
				}

				++$pos;
				continue;
			}

			if ( self::starts_with_at( $content, $pos, '/*' ) ) {
				$in_comment = true;
				$pos       += 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
				++$pos;
				continue;
			}

			if ( ';' === $char ) {
				return $pos;
			}

			++$pos;
		}

		return null;
	}

	/**
	 * Extract content between braces, handling nested braces, strings, and comments.
	 *
	 * @param string $content CSS content.
	 * @param int    $pos     Position after opening brace.
	 * @param int    $length  Content length.
	 * @return array{0: string, 1: int}|null CSS content and new position, or null on failure.
	 */
	private static function extract_brace_content( string $content, int $pos, int $length ): ?array {
		$depth       = 1;
		$start       = $pos;
		$in_string   = false;
		$string_char = '';
		$in_comment  = false;

		while ( $pos < $length && $depth > 0 ) {
			$char = $content[ $pos ];

			if ( $in_comment ) {
				if ( self::starts_with_at( $content, $pos, '*/' ) ) {
					$in_comment = false;
					$pos       += 2;
					continue;
				}

				++$pos;
				continue;
			}

			if ( $in_string ) {
				if ( '\\' === $char && $pos + 1 < $length ) {
					$pos += 2;
					continue;
				}

				if ( $char === $string_char ) {
					$in_string = false;
				}

				++$pos;
				continue;
			}

			if ( self::starts_with_at( $content, $pos, '/*' ) ) {
				$in_comment = true;
				$pos       += 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
				++$pos;
				continue;
			}

			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
			}

			++$pos;
		}

		if ( 0 !== $depth ) {
			return null;
		}

		$css = substr( $content, $start, $pos - $start - 1 );

		return array( $css, $pos );
	}

	/**
	 * Determine whether a token starts at an offset.
	 *
	 * @param string $content CSS content.
	 * @param int    $pos     Offset.
	 * @param string $token   Token to check.
	 */
	private static function starts_with_at( string $content, int $pos, string $token ): bool {
		return substr( $content, $pos, strlen( $token ) ) === $token;
	}
}
