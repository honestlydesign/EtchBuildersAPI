<?php
/**
 * Pattern builder for Etch wp_block registrations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Support\BlocksInputPathGuard;
use RuntimeException;

/**
 * Fluent builder for Etch patterns backed by wp_block posts.
 *
 * Patterns are always non-synced (unsynced) by design.
 *
 * Example:
 *   Pattern::new('Example', 'Description...')
 *     ->key('ExamplePattern')
 *     ->category('oh-my-idetch')
 *     ->blocks($markup)
 *     ->register();
 */
final class Pattern {
	/**
	 * Pattern display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Unique pattern key saved in plugin meta.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Pattern description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Serialized Gutenberg HTML.
	 *
	 * @var string
	 */
	private string $blocks = '';

	/**
	 * Pattern category slugs/names.
	 *
	 * @var array<int, string>
	 */
	private array $categories = array();

	/**
	 * Global stylesheet references declared by this pattern.
	 *
	 * @var array<int, StylesheetReference>
	 */
	private array $stylesheet_references = array();

	/**
	 * Constructor.
	 *
	 * Patterns are always non-synced (unsynced).
	 *
	 * @param string $name        Pattern display name.
	 * @param string $description Pattern description.
	 * @throws InvalidArgumentException When name or description is invalid.
	 */
	private function __construct( string $name, string $description ) {
		$this->name        = $this->validate_name( $name );
		$this->description = $this->validate_description( $description );
		$this->key         = $this->derive_key( $name );
	}

	/**
	 * Create a new Pattern builder.
	 *
	 * @param string $name        Pattern display name.
	 * @param string $description Pattern description.
	 * @throws InvalidArgumentException When name or description is invalid.
	 */
	public static function new( string $name, string $description ): self {
		return new self( $name, $description );
	}

	/**
	 * Set the pattern key.
	 *
	 * @param string $key Pattern key (overrides auto-derived key).
	 */
	public function key( string $key ): self {
		$this->key = $this->validate_key( $key );
		return $this;
	}

	/**
	 * Add a single category.
	 *
	 * @param string $category Category name/slug.
	 */
	public function category( string $category ): self {
		$category = trim( $category );
		if ( '' !== $category && ! in_array( $category, $this->categories, true ) ) {
			$this->categories[] = $category;
		}
		return $this;
	}

	/**
	 * Add multiple categories.
	 *
	 * @param array<int, string> $categories Category names/slugs.
	 */
	public function categories( array $categories ): self {
		foreach ( $categories as $category ) {
			if ( is_string( $category ) ) {
				$this->category( $category );
			}
		}
		return $this;
	}

	/**
	 * Set the blocks markup.
	 *
	 * @param string $blocks Raw Gutenberg HTML or local file path.
	 * @throws RuntimeException When the local file cannot be read.
	 */
	public function blocks( string $blocks ): self {
		$this->blocks = $this->resolve_blocks_input( $blocks );
		return $this;
	}

	/**
	 * Alias for blocks().
	 *
	 * @param string $blocks_or_path Raw Gutenberg HTML or local file path.
	 * @throws RuntimeException When the local file cannot be read.
	 */
	public function add_blocks( string $blocks_or_path ): self {
		return $this->blocks( $blocks_or_path );
	}

	/**
	 * Gets the pattern name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Gets the pattern key.
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Gets the pattern description.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Gets serialized Gutenberg blocks markup.
	 */
	public function get_blocks(): string {
		return $this->blocks;
	}

	/**
	 * Gets pattern categories.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return $this->categories;
	}

	/**
	 * Add a style scoped to this pattern.
	 *
	 * Pattern styles are plugin-owned and overwrite DB state on sync, but they
	 * are not marked readonly in persisted Etch style data.
	 *
	 * @param Style $style Style builder instance.
	 * @return string Registered style id.
	 */
	public function add_style( Style $style ): string {
		return $style->overwrite_on_register( true )->add();
	}

	/**
	 * Attach a CSS file to an Etch global stylesheet.
	 *
	 * Multiple builders can target the same stylesheet ID; their CSS is stacked
	 * into the same Etch global stylesheet.
	 *
	 * @param string $id Stylesheet ID and display name.
	 * @param string $file_path CSS file path.
	 */
	public function stylesheet( string $id, string $file_path ): self {
		$this->stylesheet_references[] = StylesheetReference::new( $id, $file_path );

		return $this;
	}

	/**
	 * Register global stylesheet references declared by this pattern.
	 */
	public function register_stylesheets(): bool|RegistrationResult {
		return Stylesheet::register_references( 'pattern:' . $this->key, $this->stylesheet_references );
	}

	/**
	 * Reset pattern styles.
	 *
	 * Pattern styles are plugin-owned and reapplied on every sync, so there is
	 * currently no separate reset behavior. The method remains for API
	 * compatibility and fluent chaining.
	 */
	public function reset_styles(): self {
		return $this;
	}

	/**
	/**
	 * Persists the pattern to wp_block and Etch pattern meta keys.
	 *
	 * Concrete persistence is handled by the consumer's registrar (e.g. the
	 * WordPress starter's PatternRegistrar), which consumes get_blocks() and
	 * get_properties().
	 */
	public function register(): void {
		// No-op in the package context. Consumers call their own registrar.
	}

	/**
	 * Derives a CapitalCase key from a pattern name.
	 *
	 * "Example Pattern" → "ExamplePattern"
	 *
	 * @param string $name Pattern name.
	 */
	private function derive_key( string $name ): string {
		// Split on non-alphanumeric characters.
		$words = preg_split( '/[^A-Za-z0-9]+/', trim( $name ) );
		if ( false === $words || array() === $words ) {
			return 'Pattern';
		}

		// Filter out empty strings and convert each word to CapitalCase.
		$key_parts = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( '' !== $word ) {
				$key_parts[] = ucfirst( strtolower( $word ) );
			}
		}

		if ( array() === $key_parts ) {
			return 'Pattern';
		}

		return implode( '', $key_parts );
	}

	/**
	 * Validates description.
	 *
	 * @param string $description Raw description value.
	 * @throws InvalidArgumentException When description is invalid.
	 */
	private function validate_description( string $description ): string {
		$description = trim( $description );
		if ( '' === $description ) {
			throw new InvalidArgumentException( 'Pattern "description" must be non-empty.' );
		}
		return $description;
	}

	/**
	 * Validates and normalizes pattern name.
	 *
	 * @param mixed $raw_name Raw pattern name value.
	 * @throws InvalidArgumentException When name is invalid.
	 */
	private function validate_name( mixed $raw_name ): string {
		if ( ! is_string( $raw_name ) ) {
			throw new InvalidArgumentException( 'Pattern "name" is required and must be a string.' );
		}

		$name = trim( $raw_name );
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Pattern "name" must be non-empty.' );
		}

		return $name;
	}

	/**
	 * Validates and normalizes pattern key.
	 *
	 * @param mixed $raw_key Raw pattern key value.
	 * @throws InvalidArgumentException When key is invalid.
	 */
	private function validate_key( mixed $raw_key ): string {
		if ( ! is_string( $raw_key ) ) {
			throw new InvalidArgumentException( 'Pattern "key" is required and must be a string.' );
		}

		$key = trim( $raw_key );
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Pattern "key" must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $key ) ) {
			throw new InvalidArgumentException( 'Pattern "key" must match /^[A-Za-z][A-Za-z0-9_-]*$/.' );
		}

		return $key;
	}

	/**
	 * Resolves blocks input as either raw HTML or a readable local file path.
	 *
	 * @param string $blocks_or_path Raw Gutenberg HTML or local file path.
	 * @throws RuntimeException When the local file cannot be read.
	 */
	private function resolve_blocks_input( string $blocks_or_path ): string {
		if ( ! BlocksInputPathGuard::is_path_candidate( $blocks_or_path ) ) {
			return $blocks_or_path;
		}

		if ( is_readable( $blocks_or_path ) && is_file( $blocks_or_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $blocks_or_path );
			if ( false === $contents ) {
				throw new RuntimeException( 'Unable to read blocks file.' );
			}
			return $contents;
		}

		return $blocks_or_path;
	}
}
