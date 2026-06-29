<?php
/**
 * Global style builder for Etch style registration.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Environment;
use RuntimeException;

/**
 * Fluent builder for Etch global styles.
 *
 * Pattern:
 *   Style::new()
	 *     ->id( 'omide-accordion-base' )
	 *     ->selector( '[data-omide-accordion-root]' )
 *     ->css( 'display: flex;' )
 *     ->add();
 */
final class Style {

	/**
	 * Allowed Etch style types.
	 *
	 * @var array<int, string>
	 */
	private const STYLE_TYPES = array( 'class', 'id', 'tag', 'element', 'attribute', 'custom' );

	private const STYLES_OPTION_NAME = 'etch_styles';

	private const DEFAULT_COLLECTION = 'default';

	/**
	 * In-memory style registry keyed by style id.
	 *
	 * @var array<string, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	private static array $registry = array();

	/**
	 * Style ID.
	 *
	 * @var string
	 */
	private string $id = '';

	/**
	 * CSS selector.
	 *
	 * @var string
	 */
	private string $selector = '';

	/**
	 * CSS rules.
	 *
	 * @var string
	 */
	private string $css = '';

	/**
	 * Whether CSS was explicitly provided.
	 *
	 * @var bool
	 */
	private bool $has_css = false;

	/**
	 * Style type.
	 *
	 * @var string|null
	 */
	private ?string $type = null;

	/**
	 * Readonly flag.
	 *
	 * @var bool|null
	 */
	private ?bool $readonly = null;

	/**
	 * Overwrite-on-register flag.
	 *
	 * Internal only. This causes plugin CSS to overwrite DB state during
	 * registration without persisting the readonly flag into Etch styles.
	 *
	 * @var bool|null
	 */
	private ?bool $overwrite_on_register = null;

	/**
	 * Display name.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * Style collection override.
	 *
	 * When null, falls back to DEFAULT_COLLECTION. Set to a code-owned marker
	 * (e.g. 'OhMyIDEtch') so orphan detection can identify builder-managed styles.
	 *
	 * @var string|null
	 */
	private ?string $collection = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create a new Style builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the style ID (required).
	 *
	 * @param string $id Style identifier.
	 */
	public function id( string $id ): self {
		$this->id = $id;
		return $this;
	}

	/**
	 * Set the CSS selector (required).
	 *
	 * @param string $selector CSS selector.
	 */
	public function selector( string $selector ): self {
		$this->selector = $selector;
		return $this;
	}

	/**
	 * Set the CSS rules (required).
	 *
	 * @param string $css CSS rules.
	 */
	public function css( string $css ): self {
		$this->css     = $css;
		$this->has_css = true;
		return $this;
	}

	/**
	 * Set the style type.
	 *
	 * @param string $type Style type (class, id, tag, element, attribute, custom).
	 */
	public function type( string $type ): self {
		$this->type = $type;
		return $this;
	}

	/**
	 * Set the readonly flag.
	 *
	 * @param bool $is_readonly Whether the style is readonly.
	 */
	public function readonly( bool $is_readonly = true ): self {
		$this->readonly = $is_readonly;
		return $this;
	}

	/**
	 * Set overwrite-on-register behavior without persisting readonly.
	 *
	 * @param bool $should_overwrite Whether the style should overwrite DB state on register.
	 */
	public function overwrite_on_register( bool $should_overwrite = true ): self {
		$this->overwrite_on_register = $should_overwrite;
		return $this;
	}

	/**
	 * Set the display name.
	 *
	 * @param string $name Display name.
	 */
	public function name( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * Set the style collection.
	 *
	 * @param string $collection Collection identifier.
	 */
	public function collection( string $collection ): self {
		$this->collection = $collection;
		return $this;
	}

	/**
	 * Validate and add this style to the registry.
	 *
	 * @return string Registered style ID.
	 * @throws InvalidArgumentException When required fields are missing or invalid.
	 */
	public function add(): string {
		$style_id      = $this->validate_style_id();
		$selector      = $this->validate_selector();
		$css           = $this->validate_css();
		$resolved_type = $this->resolve_type( $selector );

		$style = array(
			'selector'   => $selector,
			'collection' => $this->collection ?? self::DEFAULT_COLLECTION,
			'css'        => $css,
			'type'       => $resolved_type,
		);

		if ( null !== $this->name ) {
			$style['name'] = $this->name;
		}

		if ( null !== $this->readonly ) {
			$style['readonly'] = $this->readonly;
		}

		if ( null !== $this->overwrite_on_register ) {
			$style['overwrite_on_register'] = $this->overwrite_on_register;
		}

		self::remove_registry_selector_conflicts( $style_id, $selector );

		self::$registry[ $style_id ] = $style;

		return $style_id;
	}

	/**
	 * Return the style as an array (for testing/inspection).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$result = array(
			'selector'   => $this->selector,
			'collection' => $this->collection ?? self::DEFAULT_COLLECTION,
			'css'        => $this->css,
		);

		if ( '' !== $this->id ) {
			$result['id'] = $this->id;
		}

		if ( null !== $this->type ) {
			$result['type'] = $this->type;
		}

		if ( null !== $this->readonly ) {
			$result['readonly'] = $this->readonly;
		}

		if ( null !== $this->name ) {
			$result['name'] = $this->name;
		}

		return $result;
	}

	/**
	 * Persist all collected styles into the Etch styles option.
	 *
	 * Handles readonly and non-readonly styles differently:
	 * - Readonly styles: Always overwrite DB and persist readonly state
	 * - Overwrite-on-register styles: Always overwrite DB without persisting readonly
	 * - Non-readonly styles (for example class-prop defaults): Only write if not exists, user owns after first registration
	 *
	 * Also ensures:
	 * - Removing orphaned code-owned styles no longer in code
	 * - Removing styles with same selector but different ID (conflicts)
	 *
	 * @return bool True when styles are up-to-date, false on persistence failure.
	 */
	public static function register_all(): bool {
		$existing_styles = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $existing_styles ) ) {
			$existing_styles = array();
		}

		// If registry is empty, clear orphaned code-owned styles from DB.
		if ( array() === self::$registry ) {
			$cleaned_styles = array();
			$changed        = false;

			foreach ( $existing_styles as $style_id => $style ) {
				if ( is_array( $style ) && self::is_orphaned_code_owned_style( (string) $style_id, $style ) ) {
					$changed = true;
					continue;
				}

				if ( is_array( $style ) ) {
					$cleaned_styles[ $style_id ] = self::normalize_persisted_style( $style );
					continue;
				}

				$cleaned_styles[ $style_id ] = $style;
			}

			if ( ! $changed ) {
				return true;
			}

			return Environment::storage()->set( self::STYLES_OPTION_NAME, $cleaned_styles );
		}

		$selector_map     = self::build_selector_map( self::$registry );
		$cleaned_existing = array();

		foreach ( $existing_styles as $existing_style_id => $existing_style ) {
			$normalized_existing_style_id = (string) $existing_style_id;

			// Handle styles that are currently being registered.
			if ( isset( self::$registry[ $normalized_existing_style_id ] ) ) {
				$registry_style = self::$registry[ $normalized_existing_style_id ];

				// Readonly/overwrite-on-register styles: use registry value (plugin owns it).
				if ( self::should_overwrite_db_state( $registry_style ) ) {
					$cleaned_existing[ $existing_style_id ] = $existing_style;
					continue;
				}

				// Non-readonly styles: preserve DB value (user owns it after first registration).
				// Auto-unlock legacy entries that were previously persisted as readonly.
				if ( is_array( $existing_style ) ) {
					$unlocked_style = $existing_style;
					if ( isset( $unlocked_style['readonly'] ) && true === $unlocked_style['readonly'] ) {
						unset( $unlocked_style['readonly'] );
					}
					$cleaned_existing[ $existing_style_id ] = self::normalize_persisted_style( $unlocked_style );
					continue;
				}

				$cleaned_existing[ $existing_style_id ] = $existing_style;
				continue;
			}

			// Skip invalid entries.
			if ( ! is_array( $existing_style ) || ! isset( $existing_style['selector'] ) || ! is_string( $existing_style['selector'] ) ) {
				continue;
			}

			$existing_selector = trim( $existing_style['selector'] );
			if ( '' === $existing_selector ) {
				continue;
			}

			// Remove styles with same selector but different ID (conflicts with new registry).
			if ( isset( $selector_map[ $existing_selector ] ) ) {
				continue;
			}

			// Remove orphaned code-owned styles no longer present in the registry.
			if ( self::is_orphaned_code_owned_style( $normalized_existing_style_id, $existing_style ) ) {
				continue;
			}

			// Keep all other existing styles (user/etch styles not managed by this starter).
			$cleaned_existing[ $existing_style_id ] = self::normalize_persisted_style( $existing_style );
		}

		// Build merged styles with overwrite handling.
		$merged_styles = $cleaned_existing;
		foreach ( self::$registry as $style_id => $registry_style ) {
			$should_overwrite = self::should_overwrite_db_state( $registry_style );

			if ( $should_overwrite ) {
				// Plugin-owned styles: always use registry value.
				$merged_styles[ $style_id ] = self::prepare_registry_style_for_persistence( $registry_style );
			} elseif ( ! isset( $cleaned_existing[ $style_id ] ) ) {
				// Non-readonly: only use registry if not in DB, otherwise preserve DB.
				$merged_styles[ $style_id ] = self::prepare_registry_style_for_persistence( $registry_style );
			}
		}

		// Only update if something actually changed.
		if ( $merged_styles === $existing_styles ) {
			return true;
		}

		return Environment::storage()->set( self::STYLES_OPTION_NAME, $merged_styles );
	}

	/**
	 * Clear the in-memory style registry.
	 */
	public static function reset(): void {
		self::$registry = array();
	}

	/**
	 * Capture the current in-memory style registry.
	 *
	 * @return array<string, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	public static function snapshot(): array {
		return self::$registry;
	}

	/**
	 * Restore the in-memory style registry from a snapshot.
	 *
	 * @param array<string, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}> $registry Style registry snapshot.
	 */
	public static function restore( array $registry ): void {
		self::$registry = $registry;
	}

	/**
	 * Return in-memory styles collected during current request.
	 *
	 * @return array<string, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	public static function registered_styles(): array {
		return self::$registry;
	}

	/**
	 * Resolve the style ID that should own a selector.
	 *
	 * @param string      $selector     CSS selector.
	 * @param string|null $preferred_id Optional create-time style ID from a legacy CSS comment.
	 * @throws RuntimeException When multiple existing style IDs use the selector.
	 */
	public static function resolve_id_for_selector( string $selector, ?string $preferred_id = null ): string {
		$selector_key = StylesParserRuleScanner::normalize_selector_key( $selector );
		$matches      = array();

		foreach ( self::$registry as $style_id => $style ) {
			if ( StylesParserRuleScanner::normalize_selector_key( $style['selector'] ) === $selector_key ) {
				$matches[ (string) $style_id ] = true;
			}
		}

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( is_array( $persisted ) ) {
			foreach ( $persisted as $style_id => $style ) {
				if ( ! is_array( $style ) || ! isset( $style['selector'] ) || ! is_string( $style['selector'] ) ) {
					continue;
				}

				if ( StylesParserRuleScanner::normalize_selector_key( $style['selector'] ) === $selector_key ) {
					$matches[ (string) $style_id ] = true;
				}
			}
		}

		$matching_ids = array_keys( $matches );
		if ( 1 === count( $matching_ids ) ) {
			return $matching_ids[0];
		}

		if ( count( $matching_ids ) > 1 ) {
			throw new RuntimeException(
				sprintf(
					'Multiple existing Etch styles use selector `%s`: %s.',
					$selector_key,
					implode( ', ', $matching_ids )
				)
			);
		}

		if ( null !== $preferred_id && self::is_valid_style_id( $preferred_id ) ) {
			return $preferred_id;
		}

		$single_class_token = StylesParserRuleScanner::single_class_token( $selector );
		if ( null !== $single_class_token ) {
			return $single_class_token;
		}

		return StylesParserRuleScanner::generated_style_id_for_selector( $selector );
	}

	/**
	 * Check whether a value can be used as an Etch style ID.
	 *
	 * @param string $style_id Proposed style ID.
	 */
	private static function is_valid_style_id( string $style_id ): bool {
		return '' !== trim( $style_id ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', trim( $style_id ) );
	}

	/**
	 * Determine whether a persisted style was code-owned but is no longer registered.
	 *
	 * @param string               $style_id Persisted style ID.
	 * @param array<string, mixed> $style    Persisted style data.
	 */
	private static function is_orphaned_code_owned_style( string $style_id, array $style ): bool {
		if ( isset( self::$registry[ $style_id ] ) ) {
			return false;
		}

		if ( isset( $style['readonly'] ) && true === $style['readonly'] ) {
			return true;
		}

		if ( isset( $style['collection'] ) && is_string( $style['collection'] ) && str_starts_with( $style['collection'], 'OhMyIDEtch' ) ) {
			return true;
		}

		return self::is_code_owned_style_id( $style_id );
	}

	/**
	 * Match style IDs registered by this starter's parsed CSS and builders.
	 *
	 * @param string $style_id Persisted style ID.
	 */
	private static function is_code_owned_style_id( string $style_id ): bool {
		return 1 === preg_match( '/^(?:omide|clayo)-/', $style_id );
	}

	/**
	 * Determine whether a registry style should overwrite DB state on register.
	 *
	 * @param array<string, mixed> $registry_style In-memory registry style.
	 */
	private static function should_overwrite_db_state( array $registry_style ): bool {
		if ( isset( $registry_style['readonly'] ) && true === $registry_style['readonly'] ) {
			return true;
		}

		return isset( $registry_style['overwrite_on_register'] ) && true === $registry_style['overwrite_on_register'];
	}

	/**
	 * Remove internal-only registry keys before persistence.
	 *
	 * @param array<string, mixed> $registry_style In-memory registry style.
	 * @return array<string, mixed>
	 */
	private static function prepare_registry_style_for_persistence( array $registry_style ): array {
		unset( $registry_style['overwrite_on_register'] );

		return $registry_style;
	}

	/**
	 * Validate style id.
	 *
	 * @throws InvalidArgumentException When style id is invalid.
	 */
	private function validate_style_id(): string {
		if ( '' === $this->id ) {
			throw new InvalidArgumentException( 'Style "id" is required.' );
		}

		$style_id = trim( $this->id );
		if ( '' === $style_id ) {
			throw new InvalidArgumentException( 'Style "id" must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $style_id ) ) {
			throw new InvalidArgumentException( 'Style "id" must match /^[A-Za-z0-9_-]+$/.' );
		}

		return $style_id;
	}

	/**
	 * Validate selector.
	 *
	 * @throws InvalidArgumentException When selector is invalid.
	 */
	private function validate_selector(): string {
		if ( '' === $this->selector ) {
			throw new InvalidArgumentException( 'Style "selector" is required.' );
		}

		$selector = trim( $this->selector );
		if ( '' === $selector ) {
			throw new InvalidArgumentException( 'Style "selector" must be non-empty.' );
		}

		return $selector;
	}

	/**
	 * Validate CSS.
	 *
	 * @throws InvalidArgumentException When CSS is invalid.
	 */
	private function validate_css(): string {
		if ( ! $this->has_css ) {
			throw new InvalidArgumentException( 'Style "css" is required.' );
		}

		return trim( $this->css );
	}

	/**
	 * Resolve style type from explicit setting or selector inference.
	 *
	 * @param string $selector Normalized CSS selector.
	 * @throws InvalidArgumentException When explicit type is invalid.
	 */
	private function resolve_type( string $selector ): string {
		if ( null !== $this->type ) {
			$type = trim( $this->type );
			if ( '' === $type || ! in_array( $type, self::STYLE_TYPES, true ) ) {
				throw new InvalidArgumentException( 'Style "type" must be one of: class, id, tag, element, attribute, custom.' );
			}

			return $type;
		}

		return self::infer_type_from_selector( $selector );
	}

	/**
	 * Normalize persisted style schema.
	 *
	 * @param array<string, mixed> $style Persisted style data.
	 * @return array<string, mixed>
	 */
	private static function normalize_persisted_style( array $style ): array {
		if ( ! isset( $style['selector'] ) || ! is_string( $style['selector'] ) ) {
			return $style;
		}

		if ( isset( $style['type'] ) && is_string( $style['type'] ) && in_array( trim( $style['type'] ), self::STYLE_TYPES, true ) ) {
			$style['type'] = trim( $style['type'] );
			return $style;
		}

		$selector = trim( $style['selector'] );
		if ( '' === $selector ) {
			return $style;
		}

		$style['type'] = self::infer_type_from_selector( $selector );

		return $style;
	}

	/**
	 * Infer a style type from a selector.
	 *
	 * @param string $selector Normalized CSS selector.
	 */
	private static function infer_type_from_selector( string $selector ): string {
		if ( 1 === preg_match( '/^\.[A-Za-z][A-Za-z0-9_-]*$/', $selector ) ) {
			return 'class';
		}

		if ( 1 === preg_match( '/^#[A-Za-z][A-Za-z0-9_-]*$/', $selector ) ) {
			return 'id';
		}

		if ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $selector ) ) {
			return 'tag';
		}

		if ( 1 === preg_match( '/^\[[A-Za-z][A-Za-z0-9_-]*(?:(?:[~|^$*]?=(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9_-]+))?\s*[iI]?)?\]$/', $selector ) ) {
			return 'attribute';
		}

		if ( 1 === preg_match( '/^:where\(\[data-etch-element=".+"\]\)$/', $selector ) ) {
			return 'element';
		}

		return 'custom';
	}

	/**
	 * Remove in-memory styles using the same selector but different ids.
	 *
	 * @param string $style_id New style id.
	 * @param string $selector New style selector.
	 */
	private static function remove_registry_selector_conflicts( string $style_id, string $selector ): void {
		foreach ( self::$registry as $existing_style_id => $existing_style ) {
			if ( $existing_style_id === $style_id ) {
				continue;
			}

			if ( trim( $existing_style['selector'] ) === $selector ) {
				unset( self::$registry[ $existing_style_id ] );
			}
		}
	}

	/**
	 * Build a lookup map for selectors.
	 *
	 * @param array<string, array{selector: string}> $styles Source styles array.
	 * @return array<string, true>
	 */
	private static function build_selector_map( array $styles ): array {
		$selectors = array();

		foreach ( $styles as $style ) {
			$selector = trim( $style['selector'] );
			if ( '' === $selector ) {
				continue;
			}

			$selectors[ $selector ] = true;
		}

		return $selectors;
	}
}
