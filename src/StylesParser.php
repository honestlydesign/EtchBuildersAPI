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
	 * @param string $content CSS file content.
	 */
	private function parse_content( string $content ): void {
		foreach ( StylesParserRuleScanner::scan_style_rules( $content ) as $rule ) {
			$selector = $rule['selector'];
			if ( '' === $selector || str_starts_with( $selector, '@' ) ) {
				continue;
			}

			$id    = Style::resolve_id_for_selector( $selector, $rule['preferred_id'] );
			$style = Style::new()
				->id( $id )
				->selector( $selector )
				->css( $this->normalize_css( $rule['css'] ) );

			$this->styles[ $id ] = $style;
		}
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
