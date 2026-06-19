<?php
/**
 * CSS styles parser for Etch pattern styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use RuntimeException;

/**
 * Parses CSS files and creates Style objects.
 *
 * Expected CSS structure:
 *   /* id-here *\/
 *   .selector { css rules }
 *
 * Usage:
 *   $parser = StylesParser::new(__DIR__ . '/styles.css');
 *   foreach ($parser->get_all() as $style) {
 *       $pattern->add_style($style);
 *   }
 */
final class StylesParser {

	/**
	 * Parsed styles indexed by ID.
	 *
	 * @var array<string, Style>
	 */
	private array $styles = array();

	/**
	 * Constructor.
	 *
	 * @param string $file_path Absolute path to the CSS file.
	 * @throws RuntimeException When file does not exist or is unreadable.
	 */
	private function __construct( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( "StylesParser: File not found: {$file_path}" );
		}

		$content = self::read_file( $file_path );
		if ( null === $content ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( "StylesParser: Unable to read file: {$file_path}" );
		}

		$mode = StylesValidator::mode_from_path( $file_path );
		StylesValidator::assert_valid( $content, $mode, $file_path );

		$this->parse_content( $content );
	}

	/**
	 * Read file contents using plain PHP.
	 *
	 * @param string $path File path.
	 * @return string|null File contents or null on failure.
	 */
	private static function read_file( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pure PHP, no WP_Filesystem in the package.
		$content = file_get_contents( $path );

		return false !== $content ? $content : null;
	}

	/**
	 * Create a new StylesParser instance.
	 *
	 * @param string $file_path Absolute path to CSS file.
	 */
	public static function new( string $file_path ): self {
		return new self( $file_path );
	}

	/**
	 * Get all parsed Style objects.
	 *
	 * @return array<int, Style>
	 */
	public function get_all(): array {
		return array_values( $this->styles );
	}

	/**
	 * Get parsed style IDs in file order.
	 *
	 * @return array<int, string>
	 */
	public function get_style_ids(): array {
		return array_keys( $this->styles );
	}

	/**
	 * Get a specific Style by ID.
	 *
	 * @param string $id Style ID.
	 * @return Style|null Style object or null if not found.
	 */
	public function get_from_id( string $id ): ?Style {
		return $this->styles[ $id ] ?? null;
	}

	/**
	 * Parse CSS content and extract styles.
	 *
	 * Uses regex for bulk matching, which is significantly faster than
	 * character-by-character parsing for the common case.
	 *
	 * @param string $content CSS file content.
	 */
	private function parse_content( string $content ): void {
		$pattern = '/\/\*\s*(.*?)\s*\*\/\s*([^{]+)\{/s';

		$offset         = 0;
		$warned         = false;
		$content_length = strlen( $content );

		while ( preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$id        = trim( $matches[1][0] );
			$selector  = trim( $matches[2][0] );
			$match_end = $matches[2][1] + strlen( $matches[2][0] );
			$brace_pos = strpos( $content, '{', $match_end - 1 );

			if ( false === $brace_pos ) {
				$offset = $match_end;
				continue;
			}

			$css_result = $this->extract_brace_content( $content, $brace_pos + 1, $content_length );

			if ( null === $css_result ) {
				if ( ! $warned ) {
					$warned = true;
					$this->trigger_malformed_structure_warning();
				}
				$offset = $brace_pos + 1;
				continue;
			}

			list( $css, $new_pos ) = $css_result;
			$css                   = $this->normalize_css( $css );

			if ( ! $this->is_valid_style_id( $id ) ) {
				if ( '' !== $id && 1 === preg_match( '/\s/', $id ) ) {
					$offset = $new_pos;
					continue;
				}

				if ( ! $warned ) {
					$warned = true;
					$this->trigger_malformed_structure_warning();
				}

				$offset = $new_pos;
				continue;
			}

			if ( '' !== $selector ) {
				$style = Style::new()
					->id( $id )
					->selector( $selector )
					->css( $css );

				$this->styles[ $id ] = $style;
			}

			$offset = $new_pos;
		}
	}

	/**
	 * Check if a style ID from a comment is valid.
	 *
	 * @param string $id Style ID extracted from comment.
	 */
	private function is_valid_style_id( string $id ): bool {
		return '' !== $id && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $id );
	}

	/**
	 * Emit warning for malformed style structure.
	 */
	private function trigger_malformed_structure_warning(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Intentional warning for debugging malformed CSS
		trigger_error( 'StylesParser: CSS structure must be `/* id */ selector { css }` - skipping malformed rule.', E_USER_WARNING );
	}

	/**
	 * Extract content between braces, handling nested braces.
	 *
	 * @param string $content CSS content.
	 * @param int    $pos     Position after opening brace.
	 * @param int    $length  Content length.
	 * @return array{0: string, 1: int}|null CSS content and new position, or null on failure.
	 */
	private function extract_brace_content( string $content, int $pos, int $length ): ?array {
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

	/**
	 * Normalize CSS by collapsing whitespace.
	 *
	 * @param string $css Raw CSS content.
	 * @return string Normalized CSS.
	 */
	private function normalize_css( string $css ): string {
		$css = preg_replace( '/\s+/', ' ', $css );
		if ( null === $css ) {
			return '';
		}
		$css = trim( $css );
		$css = rtrim( $css, ';' );

		return $css;
	}
}
