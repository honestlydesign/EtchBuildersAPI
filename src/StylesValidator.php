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

	private const NESTED_FORBIDDEN_AT_RULE_PATTERN = '/@(?:keyframes|property|import|supports|layer|charset|font-face)\b/i';

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

		$errors        = array();
		$selector_keys = array();
		$rules         = StylesParserRuleScanner::scan_style_rules( $content );

		foreach ( $rules as $index => $rule ) {
			$block_number = $index + 1;
			$selector     = $rule['selector'];

			if ( '' === $selector ) {
				$errors[] = self::format_block_error( $block_number, 'Style block is missing a selector.' );
				continue;
			}

			$root_at_rule = self::root_at_rule_name( $selector );
			if ( null !== $root_at_rule ) {
				$errors[] = self::format_block_error( $block_number, self::format_root_at_rule_error( $root_at_rule ) );
				continue;
			}

			$selector_key = StylesParserRuleScanner::normalize_selector_key( $selector );
			if ( isset( $selector_keys[ $selector_key ] ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf( 'Duplicate selector `%s`.', $selector_key )
				);
			}
			$selector_keys[ $selector_key ] = true;

			if ( StylesParserMode::CLASS_PROP === $mode && null === StylesParserRuleScanner::single_class_token( $selector ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Class prop styles require a single root class selector, got `%s`.',
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

			if ( 1 === preg_match( self::NESTED_FORBIDDEN_AT_RULE_PATTERN, self::strip_css_comments_and_strings( $rule['css'] ), $matches ) ) {
				$errors[] = self::format_block_error(
					$block_number,
					sprintf(
						'Style `%s` cannot nest global at-rules such as %s. Use Stylesheet or ->stylesheet() for true global CSS.',
						$selector_key,
						$matches[0]
					)
				);
			}
		}

		return array_merge( $errors, self::validate_unparsed_root_content( $content, $rules ) );
	}

	/**
	 * Validate a CSS ruleset body owned by one style selector.
	 *
	 * The body is deliberately validated separately from a selector because
	 * Etch persists the selector and ruleset body as different fields. Native
	 * nested pseudo/state, descendant, media, and container rules are valid;
	 * global at-rules and Sass-style BEM synthesis remain explicit escapes or
	 * global stylesheet concerns.
	 *
	 * @param string $owner_selector Selector that owns the ruleset body.
	 * @param string $css            Ruleset body without selector or braces.
	 * @return array<int, string> Validation errors.
	 */
	public static function validate_owner_local_css( string $owner_selector, string $css ): array {
		$owner_selector = StylesParserRuleScanner::normalize_selector_key( trim( $owner_selector ) );
		$css            = trim( $css );

		if ( '' === $owner_selector ) {
			return array( 'Owner-local CSS requires a non-empty owning selector.' );
		}

		if ( '' === $css || self::is_comment_only_content( $css ) ) {
			return array();
		}

		$errors = array();
		$clean  = self::strip_css_comments_and_strings( $css );

		if ( 0 !== self::brace_balance( $clean ) ) {
			$errors[] = 'Owner-local CSS must have balanced braces.';
		}

		if ( preg_match( '/&\s*(?:__|--)/', $clean ) ) {
			$errors[] = 'Owner-local CSS cannot synthesize flat BEM roots with &__ or &--; register the exact element or modifier style instead.';
		}

		if ( preg_match( '/(?:^|[;{}])\s*' . preg_quote( $owner_selector, '/' ) . '\s*\{/', $clean ) ) {
			$errors[] = sprintf(
				'Owner-local CSS for `%s` must contain the ruleset body only; do not repeat the owning selector.',
				$owner_selector
			);
		}

		$depth = 0;
		$length = strlen( $clean );
		for ( $position = 0; $position < $length; ++$position ) {
			$character = $clean[ $position ];

			if ( '{' === $character ) {
				++$depth;
				continue;
			}

			if ( '}' === $character ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}

			if ( 0 !== $depth || '@' !== $character ) {
				continue;
			}

			if ( 1 !== preg_match( '/@([A-Za-z-]+)/A', substr( $clean, $position ), $matches ) ) {
				continue;
			}

			$at_rule = '@' . strtolower( $matches[1] );
			if ( '@media' === $at_rule || '@container' === $at_rule ) {
				continue;
			}

			$errors[] = sprintf(
				'Owner-local CSS cannot contain global at-rule %s. Use Stylesheet or PortalStyle for explicit global CSS.',
				$at_rule
			);
		}

		// Reuse the parser grammar for nested global at-rules and malformed
		// selector blocks without changing the persisted selector/body shape.
		$wrapped_errors = self::validate( $owner_selector . ' { ' . $css . ' }', StylesParserMode::FIXED );

		return array_values( array_unique( array_merge( $errors, $wrapped_errors ) ) );
	}

	/**
	 * Validate one explicitly classified global stylesheet fragment.
	 *
	 * Global categories are intentionally small. Framework CSS is allowed to
	 * carry its own selectors; tokens, base rules, and fonts must not become a
	 * second home for entity presentation classes. Utilities are the explicit
	 * class-based global exception, while portal presentation enters through
	 * PortalStyle rather than a raw category string.
	 *
	 * @param GlobalStyleCategory $category Fragment category.
	 * @param string              $css      Fragment CSS.
	 * @return array<int, string> Validation errors.
	 */
	public static function validate_global_fragment( GlobalStyleCategory $category, string $css ): array {
		$css = trim( $css );
		if ( '' === $css ) {
			return array( 'Global stylesheet fragment CSS must be non-empty.' );
		}

		$clean = self::strip_css_comments_and_strings( $css );
		if ( preg_match( '/@custom-media\b/i', $clean ) ) {
			return array( 'Global stylesheet fragments cannot declare @custom-media. Use Stylesheet::register_custom_media().' );
		}

		if ( GlobalStyleCategory::PORTAL === $category ) {
			return array( 'Portal fragments must be created through PortalStyle::new().' );
		}

		$has_class_selector = self::contains_class_selector( $clean );
		if (
			$has_class_selector
			&& in_array( $category, array( GlobalStyleCategory::TOKENS, GlobalStyleCategory::BASE, GlobalStyleCategory::FONT ), true )
		) {
			return array(
				'Entity presentation CSS belongs in an owner-local Style; use Stylesheet only for genuinely global concerns or an explicit utility/portal fragment.',
			);
		}

		if ( GlobalStyleCategory::UTILITY === $category && ! $has_class_selector ) {
			return array( 'The utility category requires at least one class selector.' );
		}

		if ( GlobalStyleCategory::FONT === $category && ! preg_match( '/@font-face\b/i', $clean ) ) {
			return array( 'The font category requires an @font-face rule.' );
		}

		return array();
	}

	/**
	 * Return a root at-rule when a caller supplies one where a selector is
	 * expected. This small public seam is shared by checked selector escapes.
	 *
	 * @param string $selector Selector candidate.
	 */
	public static function root_at_rule_for_escape( string $selector ): ?string {
		return self::root_at_rule_name( trim( $selector ) );
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
	 * Calculate brace balance for comment/string-stripped CSS.
	 *
	 * @param string $content CSS content with comments and strings removed.
	 */
	private static function brace_balance( string $content ): int {
		$balance = 0;
		$length  = strlen( $content );

		for ( $position = 0; $position < $length; ++$position ) {
			if ( '{' === $content[ $position ] ) {
				++$balance;
			} elseif ( '}' === $content[ $position ] ) {
				--$balance;
			}
		}

		return $balance;
	}

	/**
	 * Determine whether CSS contains a class selector before a rule block.
	 *
	 * This deliberately ignores declaration values and quoted strings so a
	 * token such as `--icon: .5rem` cannot be mistaken for entity CSS.
	 *
	 * @param string $content Comment/string-stripped CSS.
	 */
	private static function contains_class_selector( string $content ): bool {
		$segment_start = 0;
		$length        = strlen( $content );

		for ( $position = 0; $position < $length; ++$position ) {
			if ( '{' !== $content[ $position ] ) {
				continue;
			}

			$prelude = substr( $content, $segment_start, $position - $segment_start );
			$last_semicolon = strrpos( $prelude, ';' );
			if ( false !== $last_semicolon ) {
				$prelude = substr( $prelude, $last_semicolon + 1 );
			}

			if ( preg_match( '/\.[A-Za-z][A-Za-z0-9_-]*/', trim( $prelude ) ) ) {
				return true;
			}

			$segment_start = $position + 1;
		}

		return false;
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

		return null === self::root_at_rule_name( $selector );
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
	 * Format a block-scoped validation error.
	 *
	 * @param int    $block_number Style block number.
	 * @param string $message      Error message.
	 */
	private static function format_block_error( int $block_number, string $message ): string {
		return sprintf( 'Style block %d: %s', $block_number, $message );
	}

	/**
	 * Find a root at-rule name for a selector-like token.
	 *
	 * @param string $selector CSS selector or root at-rule.
	 */
	private static function root_at_rule_name( string $selector ): ?string {
		if ( 1 !== preg_match( '/^\s*@([A-Za-z-]+)/', $selector, $matches ) ) {
			return null;
		}

		return '@' . strtolower( $matches[1] );
	}

	/**
	 * Validate content not consumed as selector blocks.
	 *
	 * @param string $content CSS content.
	 * @param array<int, array{selector: string, css: string, start: int, end: int}> $rules Scanned root blocks.
	 * @return array<int, string>
	 */
	private static function validate_unparsed_root_content( string $content, array $rules ): array {
		$errors       = array();
		$segment_start = 0;

		foreach ( $rules as $rule ) {
			if ( $rule['start'] > $segment_start ) {
				$errors = array_merge( $errors, self::validate_root_segment( substr( $content, $segment_start, $rule['start'] - $segment_start ) ) );
			}

			$segment_start = max( $segment_start, $rule['end'] );
		}

		if ( $segment_start < strlen( $content ) ) {
			$errors = array_merge( $errors, self::validate_root_segment( substr( $content, $segment_start ) ) );
		}

		return $errors;
	}

	/**
	 * Validate a root segment outside parsed selector blocks.
	 *
	 * @param string $segment CSS content outside scanned blocks.
	 * @return array<int, string>
	 */
	private static function validate_root_segment( string $segment ): array {
		$segment = preg_replace( '/\/\*.*?\*\//s', ' ', $segment );
		if ( null === $segment ) {
			return array();
		}

		$segment = trim( $segment );
		if ( '' === $segment ) {
			return array();
		}

		$errors = array();
		if ( preg_match_all( '/@([A-Za-z-]+)/', $segment, $matches ) >= 1 ) {
			foreach ( $matches[1] as $name ) {
				$errors[] = self::format_root_at_rule_error( '@' . strtolower( $name ) );
			}

			return array_values( array_unique( $errors ) );
		}

		return array(
			sprintf(
				'CSS contains root-level content outside selector blocks near `%s`.',
				self::shorten_for_error( $segment )
			),
		);
	}

	/**
	 * Strip comments and strings before semantic at-rule matching.
	 *
	 * @param string $css CSS block content.
	 */
	private static function strip_css_comments_and_strings( string $css ): string {
		$result      = '';
		$length      = strlen( $css );
		$pos         = 0;
		$in_string   = false;
		$string_char = '';
		$in_comment  = false;

		while ( $pos < $length ) {
			$char = $css[ $pos ];

			if ( $in_comment ) {
				if ( substr( $css, $pos, 2 ) === '*/' ) {
					$in_comment = false;
					$result    .= '  ';
					$pos       += 2;
					continue;
				}

				$result .= ' ';
				++$pos;
				continue;
			}

			if ( $in_string ) {
				if ( '\\' === $char && $pos + 1 < $length ) {
					$result .= '  ';
					$pos    += 2;
					continue;
				}

				if ( $char === $string_char ) {
					$in_string = false;
				}

				$result .= ' ';
				++$pos;
				continue;
			}

			if ( substr( $css, $pos, 2 ) === '/*' ) {
				$in_comment = true;
				$result    .= '  ';
				$pos       += 2;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$in_string   = true;
				$string_char = $char;
				$result     .= ' ';
				++$pos;
				continue;
			}

			$result .= $char;
			++$pos;
		}

		return $result;
	}

	/**
	 * Format root at-rule guidance.
	 *
	 * @param string $at_rule At-rule including @.
	 */
	private static function format_root_at_rule_error( string $at_rule ): string {
		if ( '@media' === $at_rule || '@container' === $at_rule ) {
			return sprintf(
				'StylesParser cannot parse root-level %1$s. Wrong: .foo { color: red; } %1$s (...) { .foo { color: blue; } } Right: .foo { color: red; %1$s (...) { color: blue; } }',
				$at_rule
			);
		}

		return sprintf(
			'StylesParser cannot parse root-level %s. Use Stylesheet or ->stylesheet() for global CSS such as @keyframes, @property, @font-face, @import, @supports, @layer, and @charset.',
			$at_rule
		);
	}

	/**
	 * Shorten root content shown in diagnostics.
	 *
	 * @param string $content Root content.
	 */
	private static function shorten_for_error( string $content ): string {
		$content = preg_replace( '/\s+/', ' ', $content );
		if ( null === $content ) {
			return '';
		}

		$content = trim( $content );

		return strlen( $content ) > 80 ? substr( $content, 0, 77 ) . '...' : $content;
	}
}
