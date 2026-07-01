<?php
/**
 * Validates builder-preview-safe style registration and block linkage.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Content\AbstractContentBuilder;
use ReflectionClass;
/**
 * Crosswalks StylesParser IDs with serialized block attrs.styles for Etch preview parity.
 */
final class BuilderPreviewStyleGuard {

	private const STYLES_PARSER_PATH_PATTERN = '/StylesParser::new\s*\(\s*__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]\s*\)/';

	private const RUNTIME_STYLE_ID_PATTERN = '/^rt-/';

	/**
	 * StylesParser paths registered by style-only patterns (not per-block linkage).
	 *
	 * @var array<int, string>
	 */
	private const SHARED_STYLES_PARSER_PATH_MARKERS = array(
		'/Patterns/SiteUtilities/',
		'/Patterns/SiteFramework/',
		'/Components/BlueprintChrome/',
		'/Components/ComponentVisuals/CatalogRuntimeStyles/',
	);

	/**
	 * Etch core element styles referenced by built-in container/section helpers.
	 *
	 * @var array<int, string>
	 */
	private const ETCH_CORE_STYLE_IDS = array(
		'etch-section-style',
		'etch-container-style',
		'etch-flex-div-style',
		'etch-iframe-style',
	);

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Collect HTML class tokens from all site builder entities.
	 *
	 * @param list<array{class-string, string}> $entities Entity class map from SiteRegistry.
	 * @return array<int, string>
	 */
	public static function collect_class_tokens_for_entities( array $entities ): array {
		$tokens = array();

		foreach ( $entities as $entry ) {
			$class_name = $entry[0];

			try {
				$builder = $class_name::build();
			} catch ( \Throwable $throwable ) {
				return array();
			}

			$blocks_markup = self::resolve_blocks_markup( $builder );
			if ( '' === $blocks_markup ) {
				continue;
			}

			$tokens = array_merge(
				$tokens,
				ClassStyleRegistry::collect_class_tokens_from_blocks_markup( $blocks_markup )
			);
		}

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Validate all registered site builders for preview-safe styles.
	 *
	 * @param list<array{class-string, string}> $entities Entity class map from SiteRegistry.
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate_site( array $entities ): array {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			ClassStyleRegistry::reset_cache();

			$parser_paths    = array();
			$all_referenced  = array();
			$all_class_tokens = array();
			$blocks_markups  = array();

			foreach ( $entities as $entry ) {
				$class_name = $entry[0];
				$kind       = $entry[1];

				try {
					$builder = $class_name::build();
				} catch ( \Throwable $throwable ) {
					return array(
						sprintf(
							'%s (%s) failed to build for style guard: %s',
							$class_name,
							$kind,
							$throwable->getMessage()
						),
					);
				}

				$blocks_markup = self::resolve_blocks_markup( $builder );
				if ( '' === $blocks_markup ) {
					continue;
				}

				$entity_referenced = self::collect_style_ids_from_blocks_markup( $blocks_markup );
				$all_referenced    = array_merge( $all_referenced, $entity_referenced );
				$all_class_tokens    = array_merge(
					$all_class_tokens,
					ClassStyleRegistry::collect_class_tokens_from_blocks_markup( $blocks_markup )
				);
				$blocks_markups[]    = $blocks_markup;

				foreach ( self::discover_styles_parser_paths( $class_name ) as $css_path ) {
					$parser_paths[ $css_path ] = true;
				}
			}

			ClassStyleRegistry::ensure_registered_for_classes( $all_class_tokens );
			self::ensure_registered_for_referenced_class_style_ids( $all_referenced );

			$all_referenced = array_values( array_unique( $all_referenced ) );
			$registered     = array_keys( Style::registered_styles() );
			$errors         = array();

			foreach ( array_keys( $parser_paths ) as $css_path ) {
				$registered = array_merge( $registered, self::parser_style_ids_from_path( $css_path ) );
			}

			ClassStyleRegistry::reset_cache();

			// Rule H: @custom-media references must resolve against declared macros.
			$custom_media_refs = array();
			foreach ( array_keys( $parser_paths ) as $css_path ) {
				if ( ! is_readable( $css_path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local CSS file after is_readable() check.
				$css_content = file_get_contents( $css_path );
				if ( false === $css_content ) {
					continue;
				}
				$refs = StylesValidator::extract_referenced_custom_media( $css_content );
				if ( array() !== $refs ) {
					$custom_media_refs[ $css_path ] = $refs;
				}
			}

			foreach ( $custom_media_refs as $css_path => $names ) {
				$declared = Stylesheet::declared_custom_media_names();
				foreach ( $names as $name ) {
					if ( ! in_array( $name, $declared, true ) ) {
						$errors[] = sprintf(
							'Rule H: Style in %s uses @custom-media "--%s" which is not declared through Custom Media Definitions. Use Stylesheet::register_custom_media().',
							$css_path,
							$name
						);
					}
				}
			}

			$registered = array_values(
				array_unique(
					array_merge(
						$registered,
						self::ETCH_CORE_STYLE_IDS,
						self::persisted_readonly_style_ids()
					)
				)
			);
			$phantom      = array_diff( $all_referenced, $registered );
			foreach ( $phantom as $style_id ) {
				if ( 1 === preg_match( self::RUNTIME_STYLE_ID_PATTERN, $style_id ) ) {
					$errors[] = sprintf(
						'Rule C: Style ID "%s" is stylesheet-only (rt-*). Use class on the element and ->stylesheet(), not attrs.styles.',
						$style_id
					);
					continue;
				}

				$errors[] = sprintf(
					'Rule A: Block references unknown style ID "%s" (not registered via add_style/StylesParser in this run).',
					$style_id
				);
			}

			foreach ( array_keys( $parser_paths ) as $css_path ) {
				if ( self::is_shared_styles_parser_path( $css_path ) ) {
					continue;
				}

				$parser_ids = self::parser_style_ids_from_path( $css_path );

				if ( array() === $parser_ids ) {
					continue;
				}

				$unlinked = array_diff( $parser_ids, $all_referenced );
				foreach ( $unlinked as $style_id ) {
					$errors[] = sprintf(
						'Rule B: Style "%s" from %s is registered but not linked on any etch/element in the site tree.',
						$style_id,
						$css_path
					);
				}
			}

			ClassStyleRegistry::reset_cache();
			$selector_to_id = ClassStyleRegistry::selector_to_id_map();

			foreach ( array_unique( $all_class_tokens ) as $class_token ) {
				if ( ClassStyleRegistry::should_skip_class_token( $class_token ) ) {
					continue;
				}

				if ( null === ClassStyleRegistry::resolve_style_id_for_class( $class_token ) ) {
					$errors[] = sprintf(
						'Rule D: HTML class "%s" is used in block markup but is not registered in etch_styles.',
						$class_token
					);
				}
			}

			if ( function_exists( 'parse_blocks' ) ) {
				foreach ( $blocks_markups as $blocks_markup ) {
					$parsed = parse_blocks( $blocks_markup );
					$errors = array_merge(
						$errors,
						self::collect_class_linkage_errors_from_blocks( $parsed, $selector_to_id )
					);
					$errors = array_merge(
						$errors,
						self::collect_standalone_class_linkage_errors_from_blocks( $parsed )
					);
					$errors = array_merge(
						$errors,
						self::validate_component_class_props( $parsed )
					);
					$errors = array_merge(
						$errors,
						self::validate_loop_ids( $parsed )
					);
				}
			}

			return $errors;
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	/**
	 * Validate Rule B attachment for one entity (parser CSS linked on its blocks).
	 *
	 * @param class-string $class_name Entity class name.
	 * @param object       $builder    Built entity (Component, Pattern, or a content builder).
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate_entity_attachment( string $class_name, object $builder ): array {
		$blocks_markup = self::resolve_blocks_markup( $builder );
		if ( '' === $blocks_markup ) {
			return array();
		}

		$entity_referenced = self::collect_style_ids_from_blocks_markup( $blocks_markup );
		$errors            = array();

		foreach ( self::discover_styles_parser_paths( $class_name ) as $css_path ) {
			if ( ! self::is_entity_owned_css_path( $class_name, $css_path ) ) {
				continue;
			}

			$parser_ids = self::parser_style_ids_from_path( $css_path );
			$unlinked   = array_diff( $parser_ids, $entity_referenced );
			foreach ( $unlinked as $style_id ) {
				$errors[] = sprintf(
					'Rule B: Style "%s" from %s is registered but not linked on any etch/element in %s.',
					$style_id,
					$css_path,
					$class_name
				);
			}
		}

		return $errors;
	}

	/**
	 * Collect style IDs referenced in serialized Gutenberg block markup.
	 *
	 * @param string $blocks_markup Serialized blocks.
	 * @return array<int, string>
	 */
	public static function collect_style_ids_from_blocks_markup( string $blocks_markup ): array {
		if ( '' === trim( $blocks_markup ) ) {
			return array();
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return self::collect_style_ids_from_markup_regex( $blocks_markup );
		}

		$blocks = parse_blocks( $blocks_markup );
		$ids    = array();
		self::walk_parsed_blocks_for_styles( $blocks, $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Validate Rule G: component class prop tokens must resolve to type=class styles.
	 *
	 * Walks etch/component blocks. For each attributes key ending in `class`,
	 * splits the space-delimited value into tokens and asserts each non-dynamic,
	 * non-runtime token resolves to a registered type=class style.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate_component_class_props( array $blocks ): array {
		$errors = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = $block['blockName'] ?? '';
			if ( 'etch/component' === $block_name ) {
				$errors = array_merge( $errors, self::check_component_class_props( $block ) );
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$errors = array_merge( $errors, self::validate_component_class_props( $block['innerBlocks'] ) );
			}
		}

		return $errors;
	}

	/**
	 * Check one component block's class-typed props.
	 *
	 * @param array<string, mixed> $block Parsed component block.
	 * @return array<int, string>
	 */
	private static function check_component_class_props( array $block ): array {
		$errors = array();

		$attrs = $block['attrs'] ?? null;
		if ( ! is_array( $attrs ) ) {
			return $errors;
		}

		$attributes = $attrs['attributes'] ?? null;
		if ( ! is_array( $attributes ) ) {
			return $errors;
		}

		foreach ( $attributes as $key => $value ) {
			if ( ! is_string( $key ) || ! str_ends_with( strtolower( $key ), 'class' ) ) {
				continue;
			}

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			foreach ( ClassStyleRegistry::split_class_tokens( $value ) as $token ) {
				if ( ClassStyleRegistry::should_skip_class_token( $token ) ) {
					continue;
				}

				$style_id = ClassStyleRegistry::resolve_standalone_class_style_id( $token );
				if ( null === $style_id ) {
					$errors[] = sprintf(
						'Rule G: Component class prop "%s" token "%s" does not resolve to a type=class style in etch_styles.',
						$key,
						$token
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate Rule H: @custom-media references must be declared.
	 *
	 * Cross-checks referenced (--name) macros against
	 * Stylesheet::declared_custom_media_names().
	 *
	 * @param array<string, array<int, string>> $referenced Map of style ID => referenced custom-media names.
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate_custom_media_references( array $referenced ): array {
		$declared = Stylesheet::declared_custom_media_names();
		$errors   = array();

		foreach ( $referenced as $style_id => $names ) {
			foreach ( $names as $name ) {
				if ( ! in_array( $name, $declared, true ) ) {
					$errors[] = sprintf(
						'Rule H: Style "%s" uses @custom-media "--%s" which is not declared through Custom Media Definitions. Use Stylesheet::register_custom_media().',
						$style_id,
						$name
					);
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate Rule I: LoopBlock loopId must match a registered LoopPreset key.
	 *
	 * Catches blocks built without the typed builder (e.g. via to_block() with
	 * raw attrs) where loopId references a preset that was never registered.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, string> Validation error messages.
	 */
	public static function validate_loop_ids( array $blocks ): array {
		$errors = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = $block['blockName'] ?? '';
			if ( 'etch/loop' === $block_name ) {
				$attrs   = $block['attrs'] ?? array();
				$loop_id = $attrs['loopId'] ?? null;
				if ( is_string( $loop_id ) && '' !== $loop_id && ! LoopPreset::is_registered_key( $loop_id ) ) {
					$errors[] = sprintf(
						'Rule I: LoopBlock loopId "%s" does not match a registered LoopPreset key. Known: %s.',
						$loop_id,
						implode( ', ', LoopPreset::registered_keys() )
					);
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$errors = array_merge( $errors, self::validate_loop_ids( $block['innerBlocks'] ) );
			}
		}

		return $errors;
	}

	/**
	 * Discover absolute CSS paths passed to StylesParser in an entity build() file.
	 *
	 * @param class-string $class_name Entity class name.
	 * @return array<int, string>
	 */
	public static function discover_styles_parser_paths( string $class_name ): array {
		$reflection = new ReflectionClass( $class_name );
		$source     = self::read_file( $reflection->getFileName() );
		if ( null === $source ) {
			return array();
		}

		$file = $reflection->getFileName();
		if ( false === $file ) {
			return array();
		}

		$dir = dirname( $file );
		if ( ! preg_match_all( self::STYLES_PARSER_PATH_PATTERN, $source, $matches ) ) {
			return array();
		}

		$paths = array();
		foreach ( $matches[1] as $relative_path ) {
			$absolute = $dir . '/' . ltrim( $relative_path, '/' );
			$realpath = realpath( $absolute );
			if ( false !== $realpath ) {
				$paths[] = $realpath;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Assert site entities pass preview style validation.
	 *
	 * @param list<array{class-string, string}> $entities Entity class map.
	 * @throws \RuntimeException When validation fails.
	 */
	public static function assert_valid_site( array $entities ): void {
		$errors = self::validate_site( $entities );
		if ( array() === $errors ) {
			return;
		}

		throw new \RuntimeException(
			'BuilderPreviewStyleGuard:' . PHP_EOL . implode( PHP_EOL, $errors )
		);
	}

	/**
	 * Resolve serialized blocks from a built entity.
	 *
	 * @param object $builder Built page, pattern, component, post, or template.
	 */
	private static function resolve_blocks_markup( object $builder ): string {
		if ( $builder instanceof Component || $builder instanceof Pattern ) {
			return $builder->get_blocks();
		}

		if ( $builder instanceof AbstractContentBuilder ) {
			return self::resolve_content_builder_markup( $builder );
		}

		if ( method_exists( $builder, 'get_blocks' ) ) {
			$blocks = $builder->get_blocks();
			return is_string( $blocks ) ? $blocks : '';
		}

		return '';
	}

	/**
	 * Read style IDs from a parsed StylesParser CSS file.
	 *
	 * @param string $css_path Absolute CSS file path.
	 * @return array<int, string>
	 */
	private static function parser_style_ids_from_path( string $css_path ): array {
		if ( ! is_readable( $css_path ) ) {
			return array();
		}

		try {
			$parser = StylesParser::new( $css_path );
		} catch ( \Throwable $throwable ) {
			return array();
		}

		return $parser->get_style_ids();
	}

	/**
	 * Walk parse_blocks() output and collect attrs.styles IDs.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int, string>                      $ids    Collected style IDs.
	 */
	private static function walk_parsed_blocks_for_styles( array $blocks, array &$ids ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = $block['attrs'] ?? null;
			if ( is_array( $attrs ) && isset( $attrs['styles'] ) && is_array( $attrs['styles'] ) ) {
				foreach ( $attrs['styles'] as $style_id ) {
					if ( is_string( $style_id ) && '' !== $style_id ) {
						$ids[] = $style_id;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk_parsed_blocks_for_styles( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * Fallback style ID extraction when parse_blocks is unavailable.
	 *
	 * @param string $blocks_markup Serialized blocks.
	 * @return array<int, string>
	 */
	private static function collect_style_ids_from_markup_regex( string $blocks_markup ): array {
		$ids = array();
		if ( preg_match_all( '/"styles"\s*:\s*\[(.*?)\]/s', $blocks_markup, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				if ( preg_match_all( '/"([A-Za-z0-9_-]+)"/', $list, $id_matches ) ) {
					foreach ( $id_matches[1] as $style_id ) {
						$ids[] = $style_id;
					}
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Render markup from a page, post, or template builder.
	 */
	private static function resolve_content_builder_markup( AbstractContentBuilder $builder ): string {
		$reflection = new ReflectionClass( $builder );
		$method     = $reflection->getMethod( 'content_markup' );
		$method->setAccessible( true );

		$markup = $method->invoke( $builder );

		return is_string( $markup ) ? $markup : '';
	}

	/**
	 * Whether a CSS file lives under the entity class directory (exclusive styles).
	 *
	 * @param class-string $class_name Entity class name.
	 * @param string       $css_path   Absolute CSS path.
	 */
	private static function is_entity_owned_css_path( string $class_name, string $css_path ): bool {
		$reflection = new ReflectionClass( $class_name );
		$file = $reflection->getFileName();
		if ( false === $file ) {
			return false;
		}

		$entity_dir = dirname( $file );
		$normalized = rtrim( str_replace( '\\', '/', $css_path ), '/' );
		$prefix     = rtrim( str_replace( '\\', '/', $entity_dir ), '/' );

		return str_starts_with( $normalized, $prefix . '/' );
	}

	/**
	 * Whether a CSS path is owned by a style-only shared registry pattern.
	 *
	 * @param string $css_path Absolute CSS path.
	 */
	/**
	 * Auto-register attrs.styles IDs that are valid HTML class tokens (utility linkage).
	 *
	 * @param array<int, string> $referenced_style_ids Style IDs referenced in attrs.styles.
	 */
	private static function ensure_registered_for_referenced_class_style_ids( array $referenced_style_ids ): void {
		foreach ( array_unique( $referenced_style_ids ) as $style_id ) {
			if ( ! is_string( $style_id ) || ClassStyleRegistry::should_skip_class_token( $style_id ) ) {
				continue;
			}

			if ( isset( Style::registered_styles()[ $style_id ] ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $style_id ) ) {
				continue;
			}

			try {
				ClassStyleRegistry::ensure_registered_for_class( $style_id );
			} catch ( \InvalidArgumentException $exception ) {
				continue;
			}
		}
	}

	/**
	 * Collect Rule F errors from parsed blocks.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, string>
	 */
	private static function collect_standalone_class_linkage_errors_from_blocks( array $blocks ): array {
		$errors = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$errors = array_merge( $errors, ClassStyleRegistry::validate_block_standalone_class_linkage( $block ) );

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$errors = array_merge(
					$errors,
					self::collect_standalone_class_linkage_errors_from_blocks( $block['innerBlocks'] )
				);
			}
		}

		return $errors;
	}

	/**
	 * Collect Rule E errors from parsed blocks.
	 *
	 * @param array<int|string, array<string, mixed>> $blocks           Parsed blocks.
	 * @param array<string, string>                   $selector_to_id_map Selector to style ID map.
	 * @return array<int, string>
	 */
	private static function collect_class_linkage_errors_from_blocks( array $blocks, array $selector_to_id_map ): array {
		$errors = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$errors = array_merge(
				$errors,
				ClassStyleRegistry::validate_block_class_style_linkage( $block, $selector_to_id_map )
			);

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$errors = array_merge(
					$errors,
					self::collect_class_linkage_errors_from_blocks( $block['innerBlocks'], $selector_to_id_map )
				);
			}
		}

		return $errors;
	}

	/**
	 * Whether a CSS path is owned by a style-only shared registry pattern.
	 *
	 * @param string $css_path Absolute CSS path.
	 */
	private static function is_shared_styles_parser_path( string $css_path ): bool {
		$normalized = str_replace( '\\', '/', $css_path );

		foreach ( self::SHARED_STYLES_PARSER_PATH_MARKERS as $marker ) {
			if ( str_contains( $normalized, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read a file when readable.
	 *
	 * @param string|false $path File path.
	 */
	private static function read_file( string|false $path ): ?string {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		$content = file_get_contents( $path );
		return false === $content ? null : $content;
	}

	/**
	 * Readonly style IDs already owned by plugins (e.g. oh-my-etch fixed component CSS).
	 *
	 * Site builders may reference these on attrs.styles to link behavior without re-registering CSS.
	 *
	 * @return array<int, string>
	 */
	private static function persisted_readonly_style_ids(): array {
		$persisted = Environment::storage()->get( 'etch_styles', array() );
		if ( ! is_array( $persisted ) ) {
			return array();
		}

		$ids = array();
		foreach ( $persisted as $style_id => $style ) {
			if ( ! is_string( $style_id ) || ! is_array( $style ) ) {
				continue;
			}

			if ( ! empty( $style['readonly'] ) ) {
				$ids[] = $style_id;
			}
		}

		return $ids;
	}
}
