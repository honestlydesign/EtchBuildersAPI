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
	 * @var array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	private static array $registry = array();

	/**
	 * Style identities claimed during the current request, including registry entries
	 * later evicted because another ID took ownership of the same selector.
	 *
	 * @var array<array-key, array{selector: string, type: string, collection: string}>
	 */
	private static array $claimed_identities = array();

	/**
	 * Existing persisted IDs linked during this request.
	 *
	 * Retention prevents legacy orphan cleanup without importing, adopting, or
	 * rewriting the persisted definition through the request-local registry.
	 *
	 * @var array<array-key, array{selector: string, type: string, collection: string}>
	 */
	private static array $retained_persisted_identities = array();

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
	 * Whether the CSS was supplied through the checked owner-local path.
	 */
	private bool $is_owner_local = false;

	/**
	 * Explicit non-simple selector escape, when one was supplied.
	 */
	private ?ScopedSelector $scoped_selector = null;

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
		if ( null !== $this->scoped_selector ) {
			throw new InvalidArgumentException( 'A ScopedSelector escape is already set; choose the normal selector before the escape.' );
		}

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
	 * Set a CSS ruleset body through the owner-local Golden Path.
	 *
	 * The selector remains a separate Etch field. Native nested pseudo/state,
	 * descendant, media, and container rules are accepted; global at-rules and
	 * Sass-style BEM synthesis fail before the style enters the registry.
	 *
	 * @param string $css Ruleset body without selector or braces.
	 */
	public function owner_local_css( string $css ): self {
		if ( null !== $this->scoped_selector ) {
			throw new InvalidArgumentException( 'Owner-local CSS cannot be combined with a ScopedSelector escape.' );
		}

		$this->css            = $css;
		$this->has_css        = true;
		$this->is_owner_local = true;

		return $this;
	}

	/**
	 * Set an explicit non-simple selector escape.
	 *
	 * @param ScopedSelector $selector Checked selector and rationale.
	 */
	public function scoped_selector( ScopedSelector $selector ): self {
		if ( $this->is_owner_local ) {
			throw new InvalidArgumentException( 'ScopedSelector cannot be combined with owner-local CSS.' );
		}

		$this->selector        = $selector->selector();
		$this->scoped_selector = $selector;

		return $this;
	}

	/**
	 * Whether this style uses an explicit ScopedSelector escape.
	 */
	public function is_scoped_selector(): bool {
		return null !== $this->scoped_selector;
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
	 * @throws InvalidArgumentException When required fields are invalid or the style ID has another identity.
	 */
	public function add(): string {
		$style_id      = $this->validate_style_id();
		$selector      = $this->validate_selector();
		$css           = $this->validate_css();
		$resolved_type = $this->resolve_type( $selector );

		if ( $this->is_owner_local ) {
			$errors = StylesValidator::validate_owner_local_css( $selector, $css );
			if ( array() !== $errors ) {
				throw new InvalidArgumentException( implode( ' ', $errors ) );
			}
		}

		self::assert_style_id_identity_available( $style_id, $selector, $resolved_type );

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

		self::$claimed_identities[ $style_id ] = array(
			'selector'   => $selector,
			'type'       => $resolved_type,
			'collection' => $style['collection'],
		);

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
	 * - Non-readonly styles: Require matching selector/type identity, then preserve user-owned DB content after first registration
	 *
	 * Also ensures:
	 * - Removing orphaned code-owned styles no longer in code
	 * - Removing styles with same selector but different ID (conflicts)
	 *
	 * @return bool True when styles are up-to-date, false on persistence failure.
	 * @throws InvalidArgumentException When a registry style ID conflicts with persisted identity.
	 */
	public static function register_all(): bool {
		$existing_styles = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $existing_styles ) ) {
			$existing_styles = array();
		}

		self::assert_claimed_identities_match_persisted( $existing_styles );
		self::assert_retained_identities_match_persisted( $existing_styles );
		self::assert_retained_selectors_are_unique( $existing_styles );

		// If registry is empty, clear orphaned code-owned styles from DB.
		if ( array() === self::$registry ) {
			$cleaned_styles = array();
			$changed        = false;

			foreach ( $existing_styles as $style_id => $style ) {
				if ( isset( self::$retained_persisted_identities[ (string) $style_id ] ) ) {
					$cleaned_styles[ $style_id ] = $style;
					continue;
				}

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

			// A linked persisted record remains external to this registration pass.
			if ( isset( self::$retained_persisted_identities[ $normalized_existing_style_id ] ) ) {
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
		self::$registry                        = array();
		self::$claimed_identities              = array();
		self::$retained_persisted_identities    = array();
	}

	/**
	 * Capture the current in-memory style registry.
	 *
	 * @return array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	public static function snapshot(): array {
		return self::$registry;
	}

	/**
	 * Capture the complete request-local style state for temporary reset/rollback.
	 *
	 * @return array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
	 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
	 *     retained_persisted_identities: array<array-key, array{selector: string, type: string, collection: string}>
	 * }
	 */
	public static function snapshot_state(): array {
		return array(
			'registry'                      => self::$registry,
			'claimed_identities'            => self::$claimed_identities,
			'retained_persisted_identities' => self::$retained_persisted_identities,
		);
	}

	/**
	 * Restore the active registry as a new identity-claim baseline.
	 *
	 * Use restore_state() instead when temporarily resetting within the same request.
	 *
	 * @param array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}> $registry Style registry snapshot.
	 */
	public static function restore( array $registry ): void {
		self::$registry                        = $registry;
		self::$claimed_identities              = array();
		self::$retained_persisted_identities    = array();

		foreach ( $registry as $style_id => $style ) {
			self::$claimed_identities[ (string) $style_id ] = array(
				'selector'   => $style['selector'],
				'type'       => $style['type'],
				'collection' => $style['collection'],
			);
		}
	}

	/**
	 * Restore complete request-local state captured by snapshot_state().
	 *
	 * @param array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
	 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
	 *     retained_persisted_identities?: array<array-key, array{selector: string, type: string, collection: string}>
	 * } $state Complete style state snapshot.
	 */
	public static function restore_state( array $state ): void {
		self::$registry                     = $state['registry'];
		self::$claimed_identities           = $state['claimed_identities'];
		self::$retained_persisted_identities = $state['retained_persisted_identities'] ?? array();
	}

	/**
	 * Protect an existing linked record from orphan cleanup without adopting it.
	 *
	 * @internal
	 * @param string $style_id Persisted opaque ID.
	 * @param string $selector Selector observed when the record was linked.
	 * @param string $type     Effective type observed when the record was linked.
	 * @throws InvalidArgumentException When the persisted ID disappeared or changed identity.
	 */
	public static function retain_linked_persisted_style( string $style_id, string $selector, string $type ): void {
		$selector = trim( $selector );
		$type     = trim( $type );

		self::assert_request_local_identity_matches( $style_id, $selector, $type );

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $persisted ) || ! array_key_exists( $style_id, $persisted ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Linked persisted style ID `%s` disappeared before its identity could be retained.', $style_id )
			);
		}

		self::assert_style_identity_matches_persisted( $style_id, $selector, $type, $persisted );
		$persisted_style = $persisted[ $style_id ];
		$collection      = is_array( $persisted_style ) && isset( $persisted_style['collection'] ) && is_string( $persisted_style['collection'] )
			? $persisted_style['collection']
			: '';

		self::$retained_persisted_identities[ $style_id ] = array(
			'selector'   => $selector,
			'type'       => $type,
			'collection' => $collection,
		);
	}

	/**
	 * Return opaque IDs of persisted records linked during this request.
	 *
	 * @internal
	 * @return array<int, string>
	 */
	public static function retained_persisted_style_ids(): array {
		return array_map(
			static fn ( int|string $style_id ): string => (string) $style_id,
			array_keys( self::$retained_persisted_identities )
		);
	}

	/**
	 * Return in-memory styles collected during current request.
	 *
	 * @return array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>
	 */
	public static function registered_styles(): array {
		return self::$registry;
	}

	/**
	 * Remove one exact owner's request-local collection before atomic replacement.
	 *
	 * @internal EntityStyleSet owns the public replacement operation.
	 */
	public static function forget_registered_collection( string $collection ): void {
		foreach ( self::$registry as $style_id => $style ) {
			if ( $collection !== $style['collection'] ) {
				continue;
			}

			unset( self::$registry[ $style_id ] );
		}

		foreach ( self::$claimed_identities as $style_id => $identity ) {
			if ( $collection === $identity['collection'] ) {
				unset( self::$claimed_identities[ $style_id ] );
			}
		}

		foreach ( self::$retained_persisted_identities as $style_id => $identity ) {
			if ( $collection === $identity['collection'] ) {
				unset( self::$retained_persisted_identities[ $style_id ] );
			}
		}
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
			return (string) $matching_ids[0];
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
			$conflicting_selector = self::conflicting_selector_for_style_id( $preferred_id, $selector_key );
			if ( null !== $conflicting_selector ) {
				throw new RuntimeException(
					sprintf(
						'Legacy StylesParser comment ID `%s` is already used by selector `%s`; selector `%s` did not match an existing style. Remove the comment or update the existing style by selector before reusing the ID.',
						$preferred_id,
						'' !== $conflicting_selector ? $conflicting_selector : '(missing selector)',
						$selector_key
					)
				);
			}

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
	 * Return the conflicting selector when a style ID is already occupied.
	 *
	 * @param string $style_id     Proposed style ID.
	 * @param string $selector_key Normalized selector key for the new style.
	 */
	private static function conflicting_selector_for_style_id( string $style_id, string $selector_key ): ?string {
		if ( isset( self::$registry[ $style_id ] ) ) {
			$selector = self::$registry[ $style_id ]['selector'];
			if ( StylesParserRuleScanner::normalize_selector_key( $selector ) !== $selector_key ) {
				return $selector;
			}

			return null;
		}

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $persisted ) || ! isset( $persisted[ $style_id ] ) || ! is_array( $persisted[ $style_id ] ) ) {
			return null;
		}

		$selector = isset( $persisted[ $style_id ]['selector'] ) && is_string( $persisted[ $style_id ]['selector'] )
			? $persisted[ $style_id ]['selector']
			: '';

		if ( StylesParserRuleScanner::normalize_selector_key( $selector ) !== $selector_key ) {
			return $selector;
		}

		return null;
	}

	/**
	 * Reject reusing a style ID for a different selector or type.
	 *
	 * @param string $style_id Proposed style ID.
	 * @param string $selector Proposed style selector.
	 * @param string $type     Proposed style type.
	 * @throws InvalidArgumentException When the style ID already identifies another selector or type.
	 */
	private static function assert_style_id_identity_available( string $style_id, string $selector, string $type ): void {
		self::assert_request_local_identity_matches( $style_id, $selector, $type );

		// Mutable ownership covers persisted content, never selector/type identity.
		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $persisted ) ) {
			return;
		}

		self::assert_style_identity_matches_persisted( $style_id, $selector, $type, $persisted );
	}

	/**
	 * Reject an identity that contradicts either request-local identity ledger.
	 *
	 * @param string $style_id Proposed style ID.
	 * @param string $selector Proposed style selector.
	 * @param string $type     Proposed style type.
	 * @throws InvalidArgumentException When the request already observed another identity.
	 */
	private static function assert_request_local_identity_matches( string $style_id, string $selector, string $type ): void {
		$ledgers = array( self::$claimed_identities, self::$retained_persisted_identities );

		foreach ( $ledgers as $identities ) {
			if ( ! isset( $identities[ $style_id ] ) ) {
				continue;
			}

			$existing = $identities[ $style_id ];
			if (
				StylesParserRuleScanner::normalize_selector_key( $existing['selector'] ) !== StylesParserRuleScanner::normalize_selector_key( $selector )
				|| $existing['type'] !== $type
			) {
				self::throw_style_identity_conflict( $style_id, $existing['selector'], $existing['type'], $selector, $type );
			}
		}
	}

	/**
	 * Recheck every request-local identity claim against the storage snapshot being updated.
	 *
	 * @param array<array-key, mixed> $persisted Persisted Etch styles.
	 * @throws InvalidArgumentException When a claimed style conflicts with persisted identity.
	 */
	private static function assert_claimed_identities_match_persisted( array $persisted ): void {
		foreach ( self::$claimed_identities as $style_id => $identity ) {
			self::assert_style_identity_matches_persisted(
				(string) $style_id,
				$identity['selector'],
				$identity['type'],
				$persisted
			);
		}
	}

	/**
	 * Recheck linked external identities against the storage snapshot being updated.
	 *
	 * @param array<array-key, mixed> $persisted Persisted Etch styles.
	 * @throws InvalidArgumentException When a linked style disappeared or changed identity.
	 */
	private static function assert_retained_identities_match_persisted( array $persisted ): void {
		foreach ( self::$retained_persisted_identities as $style_id => $identity ) {
			$normalized_style_id = (string) $style_id;
			if ( ! array_key_exists( $normalized_style_id, $persisted ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Linked persisted style ID `%s` disappeared before registration completed.', $normalized_style_id )
				);
			}

			self::assert_style_identity_matches_persisted(
				$normalized_style_id,
				$identity['selector'],
				$identity['type'],
				$persisted
			);
		}
	}

	/**
	 * Reject any other request-local or persisted ID for a retained selector.
	 *
	 * @param array<array-key, mixed> $persisted Current persisted Etch styles.
	 * @throws InvalidArgumentException When two IDs would own the same selector.
	 */
	private static function assert_retained_selectors_are_unique( array $persisted ): void {
		foreach ( self::$retained_persisted_identities as $retained_id => $identity ) {
			$normalized_retained_id = (string) $retained_id;
			$retained_selector = StylesParserRuleScanner::normalize_selector_key( $identity['selector'] );

			foreach ( self::$registry as $registry_id => $style ) {
				$normalized_registry_id = (string) $registry_id;
				if ( $normalized_registry_id === $normalized_retained_id ) {
					continue;
				}

				if ( StylesParserRuleScanner::normalize_selector_key( $style['selector'] ) === $retained_selector ) {
					throw new InvalidArgumentException(
						sprintf(
							'Selector `%s` is already linked to persisted style ID `%s` and cannot also be registered as style ID `%s`.',
							$retained_selector,
							$normalized_retained_id,
							$normalized_registry_id
						)
					);
				}
			}

			foreach ( $persisted as $persisted_id => $style ) {
				$normalized_persisted_id = (string) $persisted_id;
				if ( $normalized_persisted_id === $normalized_retained_id || ! is_array( $style ) ) {
					continue;
				}

				$selector = isset( $style['selector'] ) && is_string( $style['selector'] )
					? StylesParserRuleScanner::normalize_selector_key( $style['selector'] )
					: '';

				if ( '' !== $selector && $selector === $retained_selector ) {
					throw new InvalidArgumentException(
						sprintf(
							'Selector `%s` is already linked to persisted style ID `%s` and is also claimed by persisted style ID `%s`.',
							$retained_selector,
							$normalized_retained_id,
							$normalized_persisted_id
						)
					);
				}
			}
		}
	}

	/**
	 * Reject a proposed identity when its persisted ID is occupied differently.
	 *
	 * @param string                  $style_id Proposed style ID.
	 * @param string                  $selector Proposed style selector.
	 * @param string                  $type     Proposed style type.
	 * @param array<array-key, mixed> $persisted Persisted Etch styles.
	 * @throws InvalidArgumentException When persisted identity is malformed or conflicting.
	 */
	private static function assert_style_identity_matches_persisted( string $style_id, string $selector, string $type, array $persisted ): void {
		if ( ! array_key_exists( $style_id, $persisted ) ) {
			return;
		}

		$persisted_style = $persisted[ $style_id ];
		if (
			! is_array( $persisted_style )
			|| ! isset( $persisted_style['selector'] )
			|| ! is_string( $persisted_style['selector'] )
			|| '' === trim( $persisted_style['selector'] )
		) {
			throw new InvalidArgumentException(
				sprintf( 'Style ID `%s` is occupied by a malformed persisted style and cannot be reused.', $style_id )
			);
		}

		$existing_selector = trim( $persisted_style['selector'] );
		$has_type          = array_key_exists( 'type', $persisted_style );

		if (
			$has_type
			&& (
				! is_string( $persisted_style['type'] )
				|| ! in_array( trim( $persisted_style['type'] ), self::STYLE_TYPES, true )
			)
		) {
			throw new InvalidArgumentException(
				sprintf( 'Style ID `%s` has a present malformed persisted `type` value and cannot be reused.', $style_id )
			);
		}

		$existing_type = $has_type
			? trim( $persisted_style['type'] )
			: self::infer_type_from_selector( $existing_selector );

		if (
			StylesParserRuleScanner::normalize_selector_key( $existing_selector ) !== StylesParserRuleScanner::normalize_selector_key( $selector )
			|| $existing_type !== $type
		) {
			self::throw_style_identity_conflict( $style_id, $existing_selector, $existing_type, $selector, $type );
		}
	}

	/**
	 * Throw a consistent style identity collision error.
	 *
	 * @param string $style_id          Conflicting style ID.
	 * @param string $existing_selector Existing selector.
	 * @param string $existing_type     Existing type.
	 * @param string $selector          Proposed selector.
	 * @param string $type              Proposed type.
	 * @throws InvalidArgumentException Always.
	 */
	private static function throw_style_identity_conflict(
		string $style_id,
		string $existing_selector,
		string $existing_type,
		string $selector,
		string $type
	): void {
		throw new InvalidArgumentException(
			sprintf(
				'Style ID `%s` already identifies selector `%s` with type `%s` and cannot be reused for selector `%s` with type `%s`.',
				$style_id,
				$existing_selector,
				$existing_type,
				$selector,
				$type
			)
		);
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

		if ( isset( self::$retained_persisted_identities[ $style_id ] ) ) {
			return false;
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
	 * @param array<array-key, array{selector: string}> $styles Source styles array.
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
