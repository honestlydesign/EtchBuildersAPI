<?php
/**
 * Resolves HTML class tokens to Etch style IDs and auto-registers missing class styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Ensures every static class token used in builder markup can resolve to etch_styles.
 */
final class ClassStyleRegistry {

	private const STYLES_OPTION_NAME = 'etch_styles';

	private const RUNTIME_CLASS_PATTERN = '/^rt-/';

	private const STYLE_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

	private const DYNAMIC_CLASS_PATTERN = '/[{}]/';

	private const COMPOUND_CLASS_SEGMENT_PATTERN = '/^\.([A-Za-z][A-Za-z0-9_-]*)$/';

	/**
	 * Cached selector => style ID map for the current resolution pass.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $selector_to_id = null;

	/**
	 * Cached compound-selector presence checks per class token.
	 *
	 * @var array<string, bool>
	 */
	private static array $compound_contains_cache = array();

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Collect class tokens from serialized block markup.
	 *
	 * @param string $blocks_markup Serialized blocks.
	 * @return array<int, string>
	 */
	public static function collect_class_tokens_from_blocks_markup( string $blocks_markup ): array {
		if ( '' === trim( $blocks_markup ) ) {
			return array();
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return self::collect_class_tokens_from_markup_regex( $blocks_markup );
		}

		$tokens = array();
		self::walk_parsed_blocks_for_classes( parse_blocks( $blocks_markup ), $tokens );

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Runtime-managed class-token skip patterns.
	 *
	 * Sync from the Etch runtime when new runtime-managed class namespaces appear.
	 * Etch source: /Users/woji/Dev/temp/etch (currently only `rt-*` is used by the
	 * visual builder for state/runtime classes).
	 *
	 * @return array<int, string>
	 */
	public static function runtime_class_skip_patterns(): array {
		return array( self::RUNTIME_CLASS_PATTERN );
	}

	/**
	 * Whether a class token should skip Etch style registration checks.
	 *
	 * @param string $class_token HTML class token.
	 */
	public static function should_skip_class_token( string $class_token ): bool {
		if ( '' === $class_token || 1 === preg_match( self::DYNAMIC_CLASS_PATTERN, $class_token ) ) {
			return true;
		}

		foreach ( self::runtime_class_skip_patterns() as $pattern ) {
			if ( 1 === preg_match( $pattern, $class_token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a class token to an existing Etch style ID, if any.
	 *
	 * @param string                            $class_token       HTML class token.
	 * @param array<string, array<string, mixed>>|null $persisted_styles Optional etch_styles option payload.
	 */
	public static function resolve_style_id_for_class( string $class_token, ?array $persisted_styles = null ): ?string {
		if ( self::should_skip_class_token( $class_token ) ) {
			return null;
		}

		$standalone_id = self::resolve_standalone_class_style_id( $class_token, $persisted_styles );
		if ( null !== $standalone_id ) {
			self::ensure_style_id_in_memory_registry( $standalone_id, $class_token );

			return $standalone_id;
		}

		if ( self::selector_map_contains_class_token( $class_token, $persisted_styles ) ) {
			return self::ensure_registered_for_class( $class_token );
		}

		return null;
	}

	/**
	 * Resolve a standalone class style ID (selector exactly .{token}, type class).
	 *
	 * @param string                            $class_token       HTML class token.
	 * @param array<string, array<string, mixed>>|null $persisted_styles Optional etch_styles option payload.
	 */
	public static function resolve_standalone_class_style_id( string $class_token, ?array $persisted_styles = null ): ?string {
		if ( self::should_skip_class_token( $class_token ) ) {
			return null;
		}

		$selector = self::selector_for_class( $class_token );

		foreach ( self::iter_registered_style_entries( $persisted_styles ) as $style_id => $style ) {
			if ( ! self::is_standalone_class_style_for_selector( $style, $selector ) ) {
				continue;
			}

			return $style_id;
		}

		return null;
	}

	/**
	 * Guarantee a type=class style with selector .{token} exists and return its ID.
	 *
	 * @param string $class_token HTML class token.
	 */
	public static function ensure_standalone_class_style_for_token( string $class_token ): string {
		$existing = self::resolve_standalone_class_style_id( $class_token );
		if ( null !== $existing ) {
			self::ensure_style_id_in_memory_registry( $existing, $class_token );

			return $existing;
		}

		return self::ensure_registered_for_class( $class_token );
	}

	/**
	 * Extract class tokens from a CSS selector (including simple compound chains).
	 *
	 * @param string $selector CSS selector.
	 * @return array<int, string>
	 */
	public static function extract_class_tokens_from_selector( string $selector ): array {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			return array();
		}

		$tokens = array();

		foreach ( preg_split( '/\s+/', $selector ) ?: array() as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}

			if ( 1 === preg_match( self::COMPOUND_CLASS_SEGMENT_PATTERN, $segment, $matches ) ) {
				$tokens[] = $matches[1];
			}
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Append standalone class style IDs for markup class tokens (builder parity).
	 *
	 * @param array<int, string> $class_tokens HTML class tokens on the block.
	 * @param array<int, string> $styles       Mutable attrs.styles list on the block builder.
	 */
	public static function append_standalone_style_ids_for_block_classes( array $class_tokens, array &$styles ): void {
		foreach ( array_unique( $class_tokens ) as $class_token ) {
			if ( ! is_string( $class_token ) || self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			try {
				$style_id = self::ensure_standalone_class_style_for_token( $class_token );
			} catch ( \InvalidArgumentException $exception ) {
				continue;
			}

			if ( ! in_array( $style_id, $styles, true ) ) {
				$styles[] = $style_id;
			}
		}
	}

	/**
	 * When compound/custom styles are linked, also link standalone class styles for matching tokens.
	 *
	 * @param array<int, string> $style_ids    Linked style IDs on the block.
	 * @param array<int, string> $class_tokens HTML class tokens on the block.
	 * @param array<int, string> $styles       Mutable attrs.styles list on the block builder.
	 */
	public static function append_standalone_style_ids_from_linked_styles( array $style_ids, array $class_tokens, array &$styles ): void {
		$class_lookup = array();
		foreach ( $class_tokens as $class_token ) {
			if ( is_string( $class_token ) && '' !== $class_token ) {
				$class_lookup[ $class_token ] = true;
			}
		}

		if ( array() === $class_lookup ) {
			return;
		}

		foreach ( array_unique( $style_ids ) as $style_id ) {
			if ( ! is_string( $style_id ) || '' === $style_id ) {
				continue;
			}

			$selector = self::selector_for_style_id( $style_id );
			if ( null === $selector || self::is_standalone_class_selector( $selector ) ) {
				continue;
			}

			foreach ( self::extract_class_tokens_from_selector( $selector ) as $token ) {
				if ( ! isset( $class_lookup[ $token ] ) ) {
					continue;
				}

				try {
					$standalone_id = self::ensure_standalone_class_style_for_token( $token );
				} catch ( \InvalidArgumentException $exception ) {
					continue;
				}

				if ( ! in_array( $standalone_id, $styles, true ) ) {
					$styles[] = $standalone_id;
				}
			}
		}
	}

	/**
	 * Keep attrs.styles aligned with Etch builder class preservation rules.
	 *
	 * @param array<int, string> $class_tokens HTML class tokens on the block.
	 * @param array<int, string> $styles       Mutable attrs.styles list on the block builder.
	 */
	public static function sync_block_class_style_linkage( array $class_tokens, array &$styles ): void {
		self::append_resolved_style_ids_for_classes( $class_tokens, $styles );
		self::append_standalone_style_ids_from_linked_styles( $styles, $class_tokens, $styles );
	}

	/**
	 * Register a class token when missing and return its Etch style ID.
	 *
	 * @param string $class_token HTML class token.
	 */
	public static function ensure_registered_for_class( string $class_token ): string {
		if ( self::should_skip_class_token( $class_token ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Class token "%s" cannot be auto-registered as an Etch style.', $class_token )
			);
		}

		$existing = self::resolve_standalone_class_style_id( $class_token );
		if ( null !== $existing ) {
			self::ensure_style_id_in_memory_registry( $existing, $class_token );

			return $existing;
		}

		if ( ! self::is_valid_style_id( $class_token ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Class token "%s" cannot be used as an Etch style ID.', $class_token )
			);
		}

		$selector = self::selector_for_class( $class_token );

		$style_id = Style::new()
			->id( $class_token )
			->selector( $selector )
			->css( '/* class style registered for builder preview */' )
			->type( 'class' )
			->collection( 'OhMyIDEtch' )
			->readonly( true )
			->add();

		self::reset_cache();

		return $style_id;
	}

	/**
	 * Ensure all class tokens are registered in the in-memory Style registry.
	 *
	 * @param array<int, string> $class_tokens HTML class tokens.
	 */
	public static function ensure_registered_for_classes( array $class_tokens ): void {
		foreach ( array_unique( $class_tokens ) as $class_token ) {
			if ( ! is_string( $class_token ) || self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			self::ensure_registered_for_class( $class_token );
		}
	}

	/**
	 * Resolve and append linked style IDs for class tokens on a block builder.
	 *
	 * @param array<int, string> $class_tokens Class tokens from a class attribute.
	 * @param array<int, string> $styles       Mutable style ID list on the block builder.
	 */
	public static function append_resolved_style_ids_for_classes( array $class_tokens, array &$styles ): void {
		foreach ( $class_tokens as $class_token ) {
			if ( self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			try {
				$style_id = self::ensure_registered_for_class( $class_token );
			} catch ( \InvalidArgumentException $exception ) {
				continue;
			}

			if ( ! in_array( $style_id, $styles, true ) ) {
				$styles[] = $style_id;
			}
		}
	}

	/**
	 * Resolve HTML class tokens to canonical Etch style IDs.
	 *
	 * Used by component class props to encode resolved IDs (not raw names),
	 * matching what Etch's ClassProperty::resolve_value expects at render.
	 *
	 * - Dynamic tokens (containing {) and runtime tokens (rt-*) are skipped silently.
	 * - Valid tokens are auto-registered as type=class styles when missing.
	 * - Tokens that cannot resolve to a valid style ID throw InvalidArgumentException.
	 *
	 * @param array<int, string> $class_tokens HTML class tokens.
	 * @return array<int, string> Resolved style IDs (skipped tokens omitted).
	 * @throws \InvalidArgumentException When a non-dynamic, non-runtime token cannot resolve.
	 */
	public static function resolve_class_tokens_to_style_ids( array $class_tokens ): array {
		$resolved = array();

		foreach ( array_values( $class_tokens ) as $class_token ) {
			if ( ! is_string( $class_token ) || '' === trim( $class_token ) ) {
				continue;
			}

			if ( self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			if ( ! self::is_valid_style_id( $class_token ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Class token "%s" cannot be resolved to an Etch style ID (must match /^[A-Za-z0-9_-]+$/).', $class_token )
				);
			}

			$resolved[] = self::ensure_registered_for_class( $class_token );
		}

		return $resolved;
	}

	/**
	 * Clear cached selector lookups (for tests).
	 */
	public static function reset_cache(): void {
		self::$selector_to_id           = null;
		self::$compound_contains_cache = array();
	}

	/**
	 * Walk parse_blocks() output and collect class attribute tokens.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int, string>                      $tokens Collected class tokens.
	 */
	public static function walk_parsed_blocks_for_classes( array $blocks, array &$tokens ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = $block['attrs'] ?? null;
			if ( is_array( $attrs ) ) {
				$attributes = $attrs['attributes'] ?? null;
				if ( is_array( $attributes ) && isset( $attributes['class'] ) && is_string( $attributes['class'] ) ) {
					foreach ( self::split_class_tokens( $attributes['class'] ) as $class_token ) {
						$tokens[] = $class_token;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_parsed_blocks_for_classes( $block['innerBlocks'], $tokens );
			}
		}
	}

	/**
	 * Validate partial attrs.styles linkage for a parsed block.
	 *
	 * @param array<string, mixed> $block             Parsed block.
	 * @param array<string, string> $selector_to_id_map Selector to style ID map.
	 * @return array<int, string> Error messages.
	 */
	public static function validate_block_class_style_linkage( array $block, array $selector_to_id_map ): array {
		$errors = array();
		$attrs  = $block['attrs'] ?? null;

		if ( ! is_array( $attrs ) ) {
			return $errors;
		}

		$style_ids = $attrs['styles'] ?? null;
		if ( ! is_array( $style_ids ) || array() === $style_ids ) {
			return $errors;
		}

		$linked = array();
		foreach ( $style_ids as $style_id ) {
			if ( is_string( $style_id ) && '' !== $style_id ) {
				$linked[ $style_id ] = true;
			}
		}

		$attributes = $attrs['attributes'] ?? null;
		if ( ! is_array( $attributes ) || ! isset( $attributes['class'] ) || ! is_string( $attributes['class'] ) ) {
			return $errors;
		}

		foreach ( self::split_class_tokens( $attributes['class'] ) as $class_token ) {
			if ( self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			$style_id = self::resolve_standalone_class_style_id( $class_token );
			if ( null === $style_id && self::selector_map_contains_class_token( $class_token ) ) {
				try {
					$style_id = self::ensure_standalone_class_style_for_token( $class_token );
				} catch ( \InvalidArgumentException $exception ) {
					$style_id = null;
				}
			}

			if ( null === $style_id ) {
				$errors[] = sprintf(
					'Rule E: Class "%s" on an element with attrs.styles is not registered in etch_styles.',
					$class_token
				);
				continue;
			}

			if ( ! isset( $linked[ $style_id ] ) ) {
				$errors[] = sprintf(
					'Rule E: Class "%s" resolves to standalone style "%s" but that ID is missing from attrs.styles on the same element.',
					$class_token,
					$style_id
				);
			}
		}

		return $errors;
	}

	/**
	 * Split a class attribute into tokens.
	 *
	 * @param string $class_value Class attribute value.
	 * @return array<int, string>
	 */
	public static function split_class_tokens( string $class_value ): array {
		$class_value = trim( $class_value );
		if ( '' === $class_value ) {
			return array();
		}

		$parts = preg_split( '/\s+/', $class_value );
		if ( false === $parts ) {
			return array();
		}

		$tokens = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$tokens[] = $part;
			}
		}

		return $tokens;
	}

	/**
	 * Build selector => style ID map from registry and persisted styles.
	 *
	 * @param array<string, array<string, mixed>>|null $persisted_styles etch_styles option.
	 * @return array<string, string>
	 */
	public static function selector_to_id_map( ?array $persisted_styles = null ): array {
		if ( null !== self::$selector_to_id ) {
			return self::$selector_to_id;
		}

		$map = array();

		foreach ( Style::registered_styles() as $style_id => $style ) {
			$selector = trim( $style['selector'] );
			if ( '' !== $selector ) {
				$map[ $selector ] = (string) $style_id;
			}
		}

		if ( null === $persisted_styles ) {
			$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
			$persisted_styles = is_array( $persisted ) ? $persisted : array();
		}

		foreach ( $persisted_styles as $style_id => $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}

			$selector = trim( (string) ( $style['selector'] ?? '' ) );
			if ( '' === $selector || isset( $map[ $selector ] ) ) {
				continue;
			}

			$map[ $selector ] = (string) $style_id;
		}

		self::$selector_to_id = $map;

		return $map;
	}

	/**
	 * Ensure a resolved style ID is present in the in-memory Style registry.
	 *
	 * @param string $style_id    Resolved Etch style ID.
	 * @param string $class_token Source HTML class token.
	 */
	private static function ensure_style_id_in_memory_registry( string $style_id, string $class_token ): void {
		if ( isset( Style::registered_styles()[ $style_id ] ) ) {
			return;
		}

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( is_array( $persisted ) && isset( $persisted[ $style_id ] ) && is_array( $persisted[ $style_id ] ) ) {
			$style = $persisted[ $style_id ];
			$css   = isset( $style['css'] ) && is_string( $style['css'] ) ? $style['css'] : '';

			Style::new()
				->id( $style_id )
				->selector( isset( $style['selector'] ) && is_string( $style['selector'] ) ? $style['selector'] : self::selector_for_class( $class_token ) )
				->css( '' !== $css ? $css : '/* class style registered for builder preview */' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->readonly( true )
				->add();

			return;
		}

		Style::new()
			->id( $style_id )
			->selector( self::selector_for_class( $class_token ) )
			->css( '/* class style registered for builder preview */' )
			->type( 'class' )
			->collection( 'OhMyIDEtch' )
			->readonly( true )
			->add();
	}

	/**
	 * CSS selector for a single HTML class token.
	 *
	 * @param string $class_token HTML class token.
	 */
	private static function selector_for_class( string $class_token ): string {
		return '.' . $class_token;
	}

	/**
	 * Whether a token is a valid Etch style ID.
	 *
	 * @param string $style_id Proposed style ID.
	 */
	private static function is_valid_style_id( string $style_id ): bool {
		return '' !== $style_id && 1 === preg_match( self::STYLE_ID_PATTERN, $style_id );
	}

	/**
	 * Fallback class extraction when parse_blocks is unavailable.
	 *
	 * @param string $blocks_markup Serialized blocks.
	 * @return array<int, string>
	 */
	/**
	 * Validate Rule F: standalone class styles must be linked when attrs.styles is set.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<int, string> Error messages.
	 */
	public static function validate_block_standalone_class_linkage( array $block ): array {
		$errors = array();
		$attrs  = $block['attrs'] ?? null;

		if ( ! is_array( $attrs ) ) {
			return $errors;
		}

		$style_ids = $attrs['styles'] ?? null;
		if ( ! is_array( $style_ids ) || array() === $style_ids ) {
			return $errors;
		}

		$linked = array();
		foreach ( $style_ids as $style_id ) {
			if ( is_string( $style_id ) && '' !== $style_id ) {
				$linked[ $style_id ] = true;
			}
		}

		$attributes = $attrs['attributes'] ?? null;
		if ( ! is_array( $attributes ) || ! isset( $attributes['class'] ) || ! is_string( $attributes['class'] ) ) {
			return $errors;
		}

		foreach ( self::split_class_tokens( $attributes['class'] ) as $class_token ) {
			if ( self::should_skip_class_token( $class_token ) ) {
				continue;
			}

			$standalone_id = self::resolve_standalone_class_style_id( $class_token );
			if ( null === $standalone_id ) {
				$errors[] = sprintf(
					'Rule F: Class "%s" has no standalone type=class style (.{token}) in etch_styles.',
					$class_token
				);
				continue;
			}

			if ( ! isset( $linked[ $standalone_id ] ) ) {
				$errors[] = sprintf(
					'Rule F: Class "%s" requires standalone style "%s" in attrs.styles (compound-only linkage strips classes in Etch builder).',
					$class_token,
					$standalone_id
				);
			}
		}

		return $errors;
	}

	/**
	 * Whether any registered selector includes the given class token.
	 *
	 * @param string                            $class_token       HTML class token.
	 * @param array<string, array<string, mixed>>|null $persisted_styles Optional etch_styles option payload.
	 */
	private static function selector_map_contains_class_token( string $class_token, ?array $persisted_styles = null ): bool {
		if ( isset( self::$compound_contains_cache[ $class_token ] ) ) {
			return self::$compound_contains_cache[ $class_token ];
		}

		foreach ( self::iter_registered_style_entries( $persisted_styles ) as $style ) {
			$selector = trim( (string) ( $style['selector'] ?? '' ) );
			if ( '' === $selector ) {
				continue;
			}

			if ( in_array( $class_token, self::extract_class_tokens_from_selector( $selector ), true ) ) {
				self::$compound_contains_cache[ $class_token ] = true;

				return true;
			}
		}

		self::$compound_contains_cache[ $class_token ] = false;

		return false;
	}

	/**
	 * Iterate in-memory and persisted style entries.
	 *
	 * @param array<string, array<string, mixed>>|null $persisted_styles etch_styles option.
	 * @return array<string, array<string, mixed>>
	 */
	private static function iter_registered_style_entries( ?array $persisted_styles = null ): array {
		$entries = Style::registered_styles();

		if ( null === $persisted_styles ) {
			$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
			$persisted_styles = is_array( $persisted ) ? $persisted : array();
		}

		foreach ( $persisted_styles as $style_id => $style ) {
			if ( ! is_string( $style_id ) || ! is_array( $style ) ) {
				continue;
			}

			if ( ! isset( $entries[ $style_id ] ) ) {
				$entries[ $style_id ] = $style;
			}
		}

		return $entries;
	}

	/**
	 * Whether a style entry is a standalone class style for a selector.
	 *
	 * @param array<string, mixed> $style    Style registry entry.
	 * @param string               $selector Expected selector (e.g. .token).
	 */
	private static function is_standalone_class_style_for_selector( array $style, string $selector ): bool {
		$style_selector = trim( (string) ( $style['selector'] ?? '' ) );
		if ( $style_selector !== $selector ) {
			return false;
		}

		$type = isset( $style['type'] ) && is_string( $style['type'] ) ? trim( $style['type'] ) : '';

		if ( 'class' === $type ) {
			return true;
		}

		if ( '' !== $type ) {
			return false;
		}

		return self::is_standalone_class_selector( $style_selector );
	}

	/**
	 * Whether a selector is a single class selector (.token).
	 *
	 * @param string $selector CSS selector.
	 */
	private static function is_standalone_class_selector( string $selector ): bool {
		return 1 === preg_match( '/^\.[A-Za-z][A-Za-z0-9_-]*$/', trim( $selector ) );
	}

	/**
	 * Resolve the CSS selector for a registered style ID.
	 *
	 * @param string $style_id Etch style ID.
	 */
	private static function selector_for_style_id( string $style_id ): ?string {
		$registered = Style::registered_styles();
		if ( isset( $registered[ $style_id ] ) ) {
			$selector = trim( $registered[ $style_id ]['selector'] );
			return '' !== $selector ? $selector : null;
		}

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $persisted ) || ! isset( $persisted[ $style_id ] ) || ! is_array( $persisted[ $style_id ] ) ) {
			return null;
		}

		$selector = trim( (string) ( $persisted[ $style_id ]['selector'] ?? '' ) );

		return '' !== $selector ? $selector : null;
	}

	/**
	 * @return array<int, string>
	 */
	private static function collect_class_tokens_from_markup_regex( string $blocks_markup ): array {
		$tokens = array();

		if ( preg_match_all( '/"class"\s*:\s*"([^"]+)"/', $blocks_markup, $matches ) ) {
			foreach ( $matches[1] as $class_value ) {
				foreach ( self::split_class_tokens( $class_value ) as $class_token ) {
					$tokens[] = $class_token;
				}
			}
		}

		return array_values( array_unique( $tokens ) );
	}
}