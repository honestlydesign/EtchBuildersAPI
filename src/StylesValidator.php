<?php
/**
 * Validates Etch StylesParser CSS file structure.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Validates CSS files consumed by StylesParser.
 */
final class StylesValidator {

	private const STYLE_BLOCK_PATTERN = '/\/\*\s*([A-Za-z0-9_-]+)\s*\*\/\s*([^{]+)\{/s';

	private const SINGLE_CLASS_SELECTOR_PATTERN = '/^\.[A-Za-z0-9_-]+$/';

	private const NESTED_FORBIDDEN_AT_RULE_PATTERN = '/@(?:keyframes|property|import|supports|layer|charset|font-face)\b/i';

	private const STYLESHEET_ONLY_AT_RULE_PATTERN = '/^\s*@(?:media|keyframes|property)\b/i';

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Resolve validation mode from a CSS file path.
	 *
	 * @param string $file_path Absolute CSS file path.
	 */
	public static function mode_from_path( string $file_path ): StylesParserMode {
		$basename = basename( $file_path );

		if ( 'default-styling.css' === $basename ) {
			return StylesParserMode::CLASS_PROP;
		}

		if ( 'fixed-component-styling.css' === $basename ) {
			return StylesParserMode::FIXED;
		}

		return StylesParserMode::FIXED;
	}

	/**
	 * Extract @custom-media macro references from CSS content.
	 *
	 * Finds every (--name) reference. Used by BuilderPreviewStyleGuard Rule H
	 * to cross-check against Stylesheet::declared_custom_media_names().
	 *
	 * @param string $content CSS content.
	 * @return array<int, string> Referenced names (without leading --), deduplicated.
	 */
	public static function extract_referenced_custom_media( string $content ): array {
		$names = array();
		if ( preg_match_all( '/\(--([a-zA-Z0-9_-]+)\)/', $content, $matches ) >= 1 ) {
			foreach ( $matches[1] as $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Validate CSS content for the given parser mode.
	 *
	 * @param string           $content CSS file content.
	 * @param StylesParserMode $mode    Validation mode.
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate( string $content, StylesParserMode $mode ): array {
		if ( StylesParserMode::FLEXIBLE === $mode ) {
			return array();
		}

		if ( self::is_comment_only_content( $content ) ) {
			return array();
		}

		$errors       = array();
		$offset       = 0;
		$content_len  = strlen( $content );
		$block_number = 0;

		while ( preg_match( self::STYLE_BLOCK_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			++$block_number;
			$id            = $matches[1][0];
			$selector      = trim( $matches[2][0] );
			$match_end     = $matches[2][1] + strlen( $matches[2][0] );
			$brace_pos     = strpos( $content, '{', $match_end - 1 );
			$comment_start = $matches[0][1];

			if ( false === $brace_pos ) {
				$errors[] = self::format_block_error( $block_number, 'Missing opening brace after selector.' );
				break;
			}

			if ( self::has_text_between_previous_block_and_comment( $content, $offset, $comment_start ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					'Only `/* style-id */ selector { css }` blocks are allowed. Remove root-level CSS outside style blocks.'
				);
			}

			if ( self::selector_uses_stylesheet_only_at_rule( $selector ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'StylesParser cannot register root at-rules such as `%s`. Move @media inside the relevant style block; use ->stylesheet() only for true global CSS such as @keyframes or @property.',
						$selector
					)
				);
			} elseif ( StylesParserMode::CLASS_PROP === $mode && 1 !== preg_match( self::SINGLE_CLASS_SELECTOR_PATTERN, $selector ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Class prop styles require a single root class selector, got `%s`.',
						$selector
					)
				);
			} elseif ( StylesParserMode::CLASS_PROP === $mode && self::selector_uses_forbidden_at_rule( $selector ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Class prop styles cannot use at-rule selectors such as `%s`.',
						$selector
					)
				);
			} elseif ( StylesParserMode::FIXED === $mode && ! self::is_valid_fixed_selector( $selector ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Fixed component selectors must be standard CSS selectors, got `%s`.',
						$selector
					)
				);
			}

			if ( '' === $selector ) {
				$errors[] = self::format_block_error( $block_number, 'Style block is missing a selector.' );
			}

			$css_result = self::extract_brace_content( $content, $brace_pos + 1, $content_len );
			if ( null === $css_result ) {
				$errors[] = self::format_block_error( $block_number, 'Unclosed style block.' );
				break;
			}

			list( $css, $new_pos ) = $css_result;

			if ( 1 === preg_match( self::NESTED_FORBIDDEN_AT_RULE_PATTERN, $css ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Style `%s` can only nest @media at-rules. Register @keyframes, @property, and other true global CSS with ->stylesheet() instead.',
						$id
					)
				);
			}

			$offset = $new_pos;
		}

		if ( self::has_trailing_css_outside_blocks( $content, $offset ) ) {
			$errors[] = 'CSS contains root-level rules outside `/* style-id */` blocks. Move @media inside the relevant style block; use ->stylesheet() only for true global CSS such as @keyframes or @property.';
		}

		if ( 0 === $block_number ) {
			$errors[] = 'CSS file must define at least one `/* style-id */` block.';
		}

		return $errors;
	}

	/**
	 * Check whether CSS content contains only comments and whitespace.
	 *
	 * @param string $content CSS content.
	 */
	private static function is_comment_only_content( string $content ): bool {
		$stripped = preg_replace( '/\/\*.*?\*\//s', '', $content );
		if ( null === $stripped ) {
			return true;
		}

		return '' === trim( $stripped );
	}

	/**
	 * Check whether a selector uses a forbidden at-rule in class prop mode.
	 *
	 * @param string $selector CSS selector.
	 */
	private static function selector_uses_forbidden_at_rule( string $selector ): bool {
		return 1 === preg_match( '/^\s*@/', $selector );
	}

	/**
	 * Check whether a selector uses an at-rule that must live in a stylesheet file.
	 *
	 * @param string $selector CSS selector.
	 */
	private static function selector_uses_stylesheet_only_at_rule( string $selector ): bool {
		return 1 === preg_match( self::STYLESHEET_ONLY_AT_RULE_PATTERN, $selector );
	}

	/**
	 * Check whether a fixed component selector is allowed.
	 *
	 * @param string $selector CSS selector.
	 */
	private static function is_valid_fixed_selector( string $selector ): bool {
		if ( '' === $selector ) {
			return false;
		}

		return 1 !== preg_match( '/^\s*@/', $selector );
	}

	/**
	 * Validate CSS content and throw when invalid.
	 *
	 * @param string           $content   CSS file content.
	 * @param StylesParserMode $mode      Validation mode.
	 * @param string           $file_path File path used in error messages.
	 * @throws \RuntimeException When CSS structure is invalid.
	 */
	public static function assert_valid( string $content, StylesParserMode $mode, string $file_path ): void {
		$errors = self::validate( $content, $mode );
		if ( array() === $errors ) {
			return;
		}

		$message = sprintf(
			'StylesValidator: Invalid CSS structure in %s:%s%s',
			$file_path,
			PHP_EOL,
			implode( PHP_EOL, $errors )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new \RuntimeException( $message );
	}

	/**
	 * Check whether non-comment text exists between blocks.
	 *
	 * @param string $content       CSS content.
	 * @param int    $previous_end  End offset of previous block.
	 * @param int    $comment_start Start offset of current style ID comment.
	 */
	private static function has_text_between_previous_block_and_comment( string $content, int $previous_end, int $comment_start ): bool {
		if ( $comment_start <= $previous_end ) {
			return false;
		}

		$between = substr( $content, $previous_end, $comment_start - $previous_end );
		$between = preg_replace( '/\/\*.*?\*\//s', '', $between );
		if ( null === $between ) {
			return false;
		}

		return '' !== trim( $between );
	}

	/**
	 * Check whether CSS remains after the final parsed style block.
	 *
	 * @param string $content CSS content.
	 * @param int    $offset  Offset after final style block.
	 */
	private static function has_trailing_css_outside_blocks( string $content, int $offset ): bool {
		$remainder = substr( $content, $offset );
		$remainder = preg_replace( '/\/\*.*?\*\//s', '', $remainder );
		if ( null === $remainder ) {
			return false;
		}

		return '' !== trim( $remainder );
	}

	/**
	 * Format a block-scoped validation error.
	 *
	 * @param int    $block_number Style block number.
	 * @param string $message      Error message.
	 */
	private static function format_block_error( int $block_number, string $message ): string {
		return sprintf( 'Style block %d: %s', $block_number, $message );
	}

	/**
	 * Extract content between braces, handling nested braces.
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

		while ( $pos < $length && $depth > 0 ) {
			$char = $content[ $pos ];

			if ( $in_string ) {
				if ( '\\' === $char && $pos + 1 < $length ) {
					$pos += 2;
					continue;
				}
				if ( $char === $string_char ) {
					$in_string = false;
				}
			} elseif ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
			} elseif ( '{' === $char ) {
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
}
