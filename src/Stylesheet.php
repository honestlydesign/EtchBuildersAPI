<?php
/**
 * Builder for Etch global stylesheets.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use RuntimeException;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Support\Json;

/**
 * Builds and safely persists Etch global stylesheets.
 */
final class Stylesheet {

	/**
	 * Etch option name used for persisted global stylesheets.
	 */
	private const STYLESHEETS_OPTION_NAME = 'etch_global_stylesheets';

	/**
	 * OhMyIDEtch option name used for builder ownership hashes.
	 */
	private const HASHES_OPTION_NAME = 'oh_my_id_etch_builder_stylesheets';

	/**
	 * OhMyIDEtch option name used for builder stylesheet fragments.
	 */
	private const FRAGMENTS_OPTION_NAME = 'oh_my_id_etch_builder_stylesheet_fragments';

	/**
	 * Builder-owned Etch stylesheet ID for custom media definitions.
	 */
	private const CUSTOM_MEDIA_STYLESHEET_ID = 'etch-builders-custom-media';

	/**
	 * Display name Etch uses for the custom media definitions registry.
	 */
	private const CUSTOM_MEDIA_STYLESHEET_NAME = 'Custom Media Definitions';

	/**
	 * Etch stylesheet type that the runtime scans for @custom-media definitions.
	 */
	private const CUSTOM_MEDIA_STYLESHEET_TYPE = '@custom-media';

	/**
	 * OhMyIDEtch option name used for builder-owned custom media hash.
	 */
	private const CUSTOM_MEDIA_HASH_OPTION_NAME = 'oh_my_id_etch_builder_custom_media_hash';

	/**
	 * @custom-media declaration pattern.
	 */
	private const CUSTOM_MEDIA_DECLARATION_PATTERN = '/@custom-media\s+--[A-Za-z0-9_-]+\s+[^;]+;/';

	/**
	 * Stylesheet owners seen during the current registration pass.
	 *
	 * @var array<string, true>
	 */
	private static array $active_owner_keys = array();

	/**
	 * Declared @custom-media macros registered during the current pass.
	 *
	 * Keyed by name => query string. Used by StylesValidator/BuilderPreviewStyleGuard
	 * to verify (--name) references resolve against a declared macro.
	 *
	 * @var array<string, string>
	 */
	private static array $custom_media = array();

	/**
	 * Stylesheet ID.
	 *
	 * @var string|null
	 */
	private ?string $id = null;

	/**
	 * Stylesheet display name.
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * Stylesheet CSS.
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
	 * Whether existing persisted stylesheets may be overwritten.
	 *
	 * @var bool
	 */
	private bool $overwrite = false;

	/**
	 * Whether this stylesheet should only register in dev mode.
	 *
	 * @var bool
	 */
	private bool $dev_only = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create a new Stylesheet builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the stylesheet ID.
	 *
	 * @param string $id Stylesheet ID.
	 * @throws InvalidArgumentException When the ID is invalid.
	 */
	public function id( string $id ): self {
		$id = trim( $id );

		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Stylesheet id must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
			throw new InvalidArgumentException( 'Stylesheet id must match /^[A-Za-z0-9_-]+$/.' );
		}

		$this->id = $id;

		return $this;
	}

	/**
	 * Set the stylesheet display name.
	 *
	 * @param string $name Stylesheet display name.
	 * @throws InvalidArgumentException When the name is empty.
	 */
	public function name( string $name ): self {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Stylesheet name must be non-empty.' );
		}

		$this->name = $name;

		return $this;
	}

	/**
	 * Set stylesheet CSS directly.
	 *
	 * @param string $css Stylesheet CSS.
	 */
	public function css( string $css ): self {
		$this->css     = $css;
		$this->has_css = true;

		return $this;
	}

	/**
	 * Load stylesheet CSS from a file.
	 *
	 * @param string $file_path CSS file path.
	 * @throws InvalidArgumentException When the file path is empty.
	 * @throws RuntimeException When the file cannot be read.
	 */
	public function css_file( string $file_path ): self {
		$file_path = trim( $file_path );

		if ( '' === $file_path ) {
			throw new InvalidArgumentException( 'Stylesheet CSS file path must be non-empty.' );
		}

		if ( ! is_file( $file_path ) ) {
			throw new RuntimeException( 'Stylesheet CSS file not found.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local CSS file after is_file() validation.
		$css = file_get_contents( $file_path );
		if ( false === $css ) {
			throw new RuntimeException( 'Stylesheet CSS file could not be read.' );
		}

		return $this->css( $css );
	}

	/**
	 * Set whether existing persisted stylesheets may be overwritten.
	 *
	 * @param bool $overwrite Whether existing persisted stylesheets may be overwritten.
	 */
	public function overwrite( bool $overwrite = true ): self {
		$this->overwrite = $overwrite;

		return $this;
	}

	/**
	 * Mark this stylesheet as dev-only.
	 *
	 * Dev-only stylesheets are silently skipped during registration when not
	 * running in development mode.
	 *
	 * @param bool $dev_only Whether this stylesheet is dev-only.
	 */
	public function dev_only( bool $dev_only = true ): self {
		$this->dev_only = $dev_only;

		return $this;
	}

	/**
	 * Whether this stylesheet is dev-only.
	 */
	public function is_dev_only(): bool {
		return $this->dev_only;
	}

	/**
	 * Serialize the stylesheet to Etch's persisted schema.
	 *
	 * @return array{name: string, css: string}
	 * @throws InvalidArgumentException When required fields are missing.
	 */
	public function to_array(): array {
		$this->validate_id();

		return array(
			'name' => $this->validate_name(),
			'css'  => $this->validate_css(),
		);
	}

	/**
	 * Register the stylesheet.
	 *
	 * @return bool|RegistrationResult
	 * @throws InvalidArgumentException When required fields are missing.
	 */
	public function register(): bool|RegistrationResult {
		if ( $this->is_dev_only() && ! Environment::mode()->is_dev_mode() ) {
			return true;
		}

		$stylesheet          = $this->to_array();
		$id                  = (string) $this->id;
		$current             = self::stylesheets();
		$builder_hashes      = self::builder_hashes();
		$existing_stylesheet = $current[ $id ] ?? null;

		if (
			array_key_exists( $id, $current )
			&& ! $this->overwrite
			&& ! self::is_builder_owned_and_unchanged( $existing_stylesheet, $builder_hashes[ $id ] ?? null )
		) {
			return true;
		}

		$next_stylesheets        = $current;
		$next_hashes             = $builder_hashes;
		$next_stylesheets[ $id ] = $stylesheet;
		$next_hashes[ $id ]      = self::hash_payload( $stylesheet );

		$stylesheets_updated = self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $current, $next_stylesheets );
		if ( ! $stylesheets_updated ) {
			return RegistrationResult::error(
				'stylesheet_update_failed',
				'Stylesheet option could not be updated.'
			);
		}

		$hashes_updated = self::update_option_if_changed( self::HASHES_OPTION_NAME, $builder_hashes, $next_hashes );
		if ( $hashes_updated ) {
			return true;
		}

		self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $next_stylesheets, $current );

		return RegistrationResult::error(
			'stylesheet_update_failed',
			'Stylesheet option could not be updated.'
		);
	}

	/**
	 * Register stylesheet fragments from a builder owner.
	 *
	 * Fragments with the same stylesheet ID are stacked in registration order
	 * and persisted as one Etch global stylesheet.
	 *
	 * @param string                          $owner_key Builder owner key.
	 * @param array<int, StylesheetReference> $references Stylesheet references.
	 * @return bool|RegistrationResult
	 * @throws InvalidArgumentException When owner key is empty.
	 */
	public static function register_references( string $owner_key, array $references ): bool|RegistrationResult {
		$owner_key = trim( $owner_key );

		if ( '' === $owner_key ) {
			throw new InvalidArgumentException( 'Stylesheet owner key must be non-empty.' );
		}

		self::$active_owner_keys[ $owner_key ] = true;

		$current_fragments = self::stylesheet_fragments();
		$next_fragments    = $current_fragments;
		$touched_ids       = self::owner_stylesheet_ids( $current_fragments, $owner_key );
		$source_keys       = array();

		foreach ( $references as $reference ) {
			$source_keys[ $reference->source_key( $owner_key ) ] = true;
			$touched_ids[ $reference->id() ]                     = true;
		}

		foreach ( $next_fragments as $id => $sources ) {
			foreach ( $sources as $source_key => $source ) {
				if ( self::source_key_belongs_to_owner( $source_key, $owner_key ) && ! isset( $source_keys[ $source_key ] ) ) {
					unset( $next_fragments[ $id ][ $source_key ] );
				}
			}

			if ( isset( $next_fragments[ $id ] ) && array() === $next_fragments[ $id ] ) {
				unset( $next_fragments[ $id ] );
			}
		}

		foreach ( $references as $reference ) {
			$id         = $reference->id();
			$source_key = $reference->source_key( $owner_key );

			if ( ! isset( $next_fragments[ $id ] ) ) {
				$next_fragments[ $id ] = array();
			}

			$next_fragments[ $id ][ $source_key ] = array(
				'css'       => $reference->css(),
				'file_path' => $reference->file_path(),
			);
		}

		$fragments_updated = self::update_option_if_changed( self::FRAGMENTS_OPTION_NAME, $current_fragments, $next_fragments );
		if ( ! $fragments_updated ) {
			return RegistrationResult::error(
				'stylesheet_fragments_update_failed',
				'Stylesheet fragment option could not be updated.'
			);
		}

		foreach ( array_keys( $touched_ids ) as $id ) {
			if ( ! isset( $next_fragments[ $id ] ) ) {
				$result = self::remove_registered_stylesheet( $id );
				if ( $result instanceof RegistrationResult ) {
					return $result;
				}

				continue;
			}

			$result = self::new()
				->id( $id )
				->name( $id )
				->css( self::aggregate_fragment_css( $next_fragments[ $id ] ) )
				->register();

			if ( $result instanceof RegistrationResult ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Reset request-local active stylesheet owners.
	 */
	public static function reset_active_owner_keys(): void {
		self::$active_owner_keys = array();
	}

	/**
	 * Declare an @custom-media macro.
	 *
	 * The name (without leading --) is registered for reference validation and
	 * persisted to Etch's "Custom Media Definitions" stylesheet type.
	 *
	 * @param string $name  Macro name without leading dashes (e.g. 'tablet').
	 * @param string $query Media query string (e.g. '(min-width: 768px)').
	 * @return bool|RegistrationResult
	 * @throws InvalidArgumentException When the name or query is invalid.
	 */
	public static function register_custom_media( string $name, string $query ): bool|RegistrationResult {
		$name  = self::normalize_custom_media_name( $name );
		$query = self::normalize_custom_media_query( $query );

		self::$custom_media[ $name ] = $query;

		return self::sync_custom_media_definitions();
	}

	/**
	 * Persist the request-local custom media registry to Etch's custom-media stylesheet type.
	 *
	 * Removes the builder-owned definitions entry when no code-owned custom
	 * media macros are registered in the current pass.
	 *
	 * @return bool|RegistrationResult
	 */
	public static function sync_custom_media_definitions(): bool|RegistrationResult {
		$current_stylesheets = self::stylesheets();
		$next_stylesheets    = $current_stylesheets;

		if ( array() === self::$custom_media ) {
			unset( $next_stylesheets[ self::CUSTOM_MEDIA_STYLESHEET_ID ] );

			if ( ! self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $current_stylesheets, $next_stylesheets ) ) {
				return RegistrationResult::error(
					'custom_media_update_failed',
					'Custom Media Definitions option could not be updated.'
				);
			}

			if ( ! Environment::storage()->delete( self::CUSTOM_MEDIA_HASH_OPTION_NAME ) ) {
				return RegistrationResult::error(
					'custom_media_update_failed',
					'Custom Media Definitions option could not be updated.'
				);
			}

			return true;
		}

		$payload = self::custom_media_payload();

		$next_stylesheets[ self::CUSTOM_MEDIA_STYLESHEET_ID ] = $payload;

		if ( ! self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $current_stylesheets, $next_stylesheets ) ) {
			return RegistrationResult::error(
				'custom_media_update_failed',
				'Custom Media Definitions option could not be updated.'
			);
		}

		if ( ! Environment::storage()->set( self::CUSTOM_MEDIA_HASH_OPTION_NAME, self::hash_payload( $payload ) ) ) {
			self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $next_stylesheets, $current_stylesheets );

			return RegistrationResult::error(
				'custom_media_update_failed',
				'Custom Media Definitions option could not be updated.'
			);
		}

		return true;
	}

	/**
	 * Names of all declared @custom-media macros.
	 *
	 * @return array<int, string>
	 */
	public static function declared_custom_media_names(): array {
		return array_keys( self::$custom_media );
	}

	/**
	 * Capture the current @custom-media registry.
	 *
	 * @return array<string, string>
	 */
	public static function custom_media_snapshot(): array {
		return self::$custom_media;
	}

	/**
	 * Restore the @custom-media registry from a snapshot.
	 *
	 * @param array<string, string> $registry Snapshot.
	 */
	public static function restore_custom_media( array $registry ): void {
		self::$custom_media = $registry;
	}

	/**
	 * Clear the @custom-media registry.
	 */
	public static function reset_custom_media(): void {
		self::$custom_media = array();
	}

	/**
	 * Remove builder stylesheet fragments whose owners no longer register.
	 */
	public static function prune_inactive_owner_fragments(): bool|RegistrationResult {
		$current_fragments = self::stylesheet_fragments();
		$next_fragments    = $current_fragments;
		$touched_ids       = array();

		foreach ( $next_fragments as $id => $sources ) {
			foreach ( array_keys( $sources ) as $source_key ) {
				if ( self::source_key_belongs_to_active_owner( $source_key ) ) {
					continue;
				}

				unset( $next_fragments[ $id ][ $source_key ] );
				$touched_ids[ $id ] = true;
			}

			if ( isset( $next_fragments[ $id ] ) && array() === $next_fragments[ $id ] ) {
				unset( $next_fragments[ $id ] );
			}
		}

		$builder_hashes = self::builder_hashes();
		foreach ( array_keys( $builder_hashes ) as $id ) {
			if ( ! isset( $next_fragments[ $id ] ) ) {
				$touched_ids[ $id ] = true;
			}
		}

		$fragments_updated = self::update_option_if_changed( self::FRAGMENTS_OPTION_NAME, $current_fragments, $next_fragments );
		if ( ! $fragments_updated ) {
			return RegistrationResult::error(
				'stylesheet_fragments_update_failed',
				'Stylesheet fragment option could not be updated.'
			);
		}

		foreach ( array_keys( $touched_ids ) as $id ) {
			if ( ! isset( $next_fragments[ $id ] ) ) {
				$result = self::remove_registered_stylesheet( $id );
				if ( $result instanceof RegistrationResult ) {
					return $result;
				}

				continue;
			}

			$result = self::new()
				->id( $id )
				->name( $id )
				->css( self::aggregate_fragment_css( $next_fragments[ $id ] ) )
				->register();

			if ( $result instanceof RegistrationResult ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Remove an aggregate stylesheet that no longer has builder fragments.
	 *
	 * @param string $id Stylesheet ID.
	 */
	private static function remove_registered_stylesheet( string $id ): bool|RegistrationResult {
		$current_stylesheets = self::stylesheets();
		$current_hashes      = self::builder_hashes();

		if ( ! array_key_exists( $id, $current_stylesheets ) && ! array_key_exists( $id, $current_hashes ) ) {
			return true;
		}

		$next_stylesheets = $current_stylesheets;
		$next_hashes      = $current_hashes;

		unset( $next_stylesheets[ $id ], $next_hashes[ $id ] );

		$stylesheets_updated = self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $current_stylesheets, $next_stylesheets );
		if ( ! $stylesheets_updated ) {
			return RegistrationResult::error(
				'stylesheet_update_failed',
				'Stylesheet option could not be updated.'
			);
		}

		$hashes_updated = self::update_option_if_changed( self::HASHES_OPTION_NAME, $current_hashes, $next_hashes );
		if ( $hashes_updated ) {
			return true;
		}

		self::update_option_if_changed( self::STYLESHEETS_OPTION_NAME, $next_stylesheets, $current_stylesheets );

		return RegistrationResult::error(
			'stylesheet_update_failed',
			'Stylesheet option could not be updated.'
		);
	}

	/**
	 * Validate stylesheet ID.
	 *
	 * @throws InvalidArgumentException When the ID is missing.
	 */
	private function validate_id(): string {
		if ( null === $this->id ) {
			throw new InvalidArgumentException( 'Stylesheet id is required.' );
		}

		return $this->id;
	}

	/**
	 * Validate stylesheet display name.
	 *
	 * @throws InvalidArgumentException When the name is missing.
	 */
	private function validate_name(): string {
		if ( null === $this->name ) {
			throw new InvalidArgumentException( 'Stylesheet name is required.' );
		}

		return $this->name;
	}

	/**
	 * Validate stylesheet CSS.
	 *
	 * @throws InvalidArgumentException When CSS is missing.
	 */
	private function validate_css(): string {
		if ( ! $this->has_css ) {
			throw new InvalidArgumentException( 'Stylesheet css is required.' );
		}

		self::assert_no_custom_media_declarations( $this->css );

		return $this->css;
	}

	/**
	 * Normalize a custom media macro name.
	 *
	 * @param string $name Macro name with or without leading dashes.
	 */
	private static function normalize_custom_media_name( string $name ): string {
		$name = trim( $name, "-- \t\n\r\0\x0B" );

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Custom media name must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $name ) ) {
			throw new InvalidArgumentException( 'Custom media name must match /^[A-Za-z0-9_-]+$/.' );
		}

		return $name;
	}

	/**
	 * Normalize a custom media query string.
	 *
	 * @param string $query Media query string.
	 */
	private static function normalize_custom_media_query( string $query ): string {
		$query = trim( $query );

		if ( '' === $query ) {
			throw new InvalidArgumentException( 'Custom media query must be non-empty.' );
		}

		if ( str_contains( $query, ';' ) ) {
			throw new InvalidArgumentException( 'Custom media query must not contain semicolons.' );
		}

		return $query;
	}

	/**
	 * Ensure normal stylesheets do not contain custom media declarations.
	 *
	 * @param string $css Stylesheet CSS.
	 */
	private static function assert_no_custom_media_declarations( string $css ): void {
		if ( 1 === preg_match( self::CUSTOM_MEDIA_DECLARATION_PATTERN, $css ) ) {
			throw new InvalidArgumentException( 'Do not declare @custom-media in a normal stylesheet. Use Stylesheet::register_custom_media().' );
		}
	}

	/**
	 * Build the Etch custom media definitions stylesheet payload.
	 *
	 * @return array{name: string, css: string, type: string}
	 */
	private static function custom_media_payload(): array {
		$definitions = self::$custom_media;
		ksort( $definitions );

		$lines = array();
		foreach ( $definitions as $name => $query ) {
			$lines[] = sprintf( '@custom-media --%s %s;', $name, $query );
		}

		return array(
			'name' => self::CUSTOM_MEDIA_STYLESHEET_NAME,
			'css'  => implode( "\n", $lines ) . "\n",
			'type' => self::CUSTOM_MEDIA_STYLESHEET_TYPE,
		);
	}

	/**
	 * Return persisted Etch stylesheets.
	 *
	 * @return array<string, mixed>
	 */
	private static function stylesheets(): array {
		$stylesheets = Environment::storage()->get( self::STYLESHEETS_OPTION_NAME, array() );

		return is_array( $stylesheets ) ? $stylesheets : array();
	}

	/**
	 * Return persisted builder stylesheet fragments.
	 *
	 * @return array<string, array<string, array{css: string, file_path: string}>>
	 */
	private static function stylesheet_fragments(): array {
		$fragments = Environment::storage()->get( self::FRAGMENTS_OPTION_NAME, array() );

		if ( ! is_array( $fragments ) ) {
			return array();
		}

		$normalized_fragments = array();
		foreach ( $fragments as $id => $sources ) {
			if ( ! is_string( $id ) || ! is_array( $sources ) ) {
				continue;
			}

			foreach ( $sources as $source_key => $source ) {
				if ( ! is_string( $source_key ) || ! is_array( $source ) ) {
					continue;
				}

				$css       = $source['css'] ?? null;
				$file_path = $source['file_path'] ?? null;

				if ( is_string( $css ) && is_string( $file_path ) ) {
					$normalized_fragments[ $id ][ $source_key ] = array(
						'css'       => $css,
						'file_path' => $file_path,
					);
				}
			}
		}

		return $normalized_fragments;
	}

	/**
	 * Return stylesheet IDs already owned by a builder owner.
	 *
	 * @param array<string, array<string, array{css: string, file_path: string}>> $fragments Stylesheet fragments.
	 * @param string                                                              $owner_key Builder owner key.
	 * @return array<string, true>
	 */
	private static function owner_stylesheet_ids( array $fragments, string $owner_key ): array {
		$ids = array();

		foreach ( $fragments as $id => $sources ) {
			foreach ( array_keys( $sources ) as $source_key ) {
				if ( self::source_key_belongs_to_owner( $source_key, $owner_key ) ) {
					$ids[ $id ] = true;
				}
			}
		}

		return $ids;
	}

	/**
	 * Determine whether a fragment source key belongs to an owner key.
	 *
	 * @param string $source_key Fragment source key.
	 * @param string $owner_key Builder owner key.
	 */
	private static function source_key_belongs_to_owner( string $source_key, string $owner_key ): bool {
		return str_starts_with( $source_key, $owner_key . ':' );
	}

	/**
	 * Determine whether a fragment source key belongs to any active owner.
	 *
	 * @param string $source_key Fragment source key.
	 */
	private static function source_key_belongs_to_active_owner( string $source_key ): bool {
		foreach ( array_keys( self::$active_owner_keys ) as $owner_key ) {
			if ( self::source_key_belongs_to_owner( $source_key, $owner_key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Aggregate stylesheet fragment CSS.
	 *
	 * @param array<string, array{css: string, file_path: string}> $sources Stylesheet sources.
	 */
	private static function aggregate_fragment_css( array $sources ): string {
		$chunks = array();

		foreach ( $sources as $source ) {
			$css = rtrim( $source['css'] );
			if ( '' !== $css ) {
				$chunks[] = $css;
			}
		}

		if ( array() === $chunks ) {
			return '';
		}

		return implode( "\n\n", $chunks ) . "\n";
	}

	/**
	 * Return persisted builder ownership hashes.
	 *
	 * @return array<string, string>
	 */
	private static function builder_hashes(): array {
		$hashes = Environment::storage()->get( self::HASHES_OPTION_NAME, array() );

		if ( ! is_array( $hashes ) ) {
			return array();
		}

		$normalized_hashes = array();
		foreach ( $hashes as $id => $hash ) {
			if ( is_string( $id ) && is_string( $hash ) && '' !== $hash ) {
				$normalized_hashes[ $id ] = $hash;
			}
		}

		return $normalized_hashes;
	}

	/**
	 * Determine whether a stylesheet is builder-owned and unchanged.
	 *
	 * @param mixed $stylesheet Existing stylesheet payload.
	 * @param mixed $hash Existing ownership hash.
	 */
	private static function is_builder_owned_and_unchanged( mixed $stylesheet, mixed $hash ): bool {
		if ( ! is_array( $stylesheet ) || ! is_string( $hash ) || '' === $hash ) {
			return false;
		}

		$payload = self::normalize_stylesheet_payload( $stylesheet );
		if ( null === $payload ) {
			return false;
		}

		return hash_equals( $hash, self::hash_payload( $payload ) );
	}

	/**
	 * Normalize persisted stylesheet data to Etch's schema.
	 *
	 * @param array<mixed> $stylesheet Persisted stylesheet data.
	 * @return array{name: string, css: string}|null
	 */
	private static function normalize_stylesheet_payload( array $stylesheet ): ?array {
		$name = $stylesheet['name'] ?? null;
		$css  = $stylesheet['css'] ?? null;

		if ( ! is_string( $name ) || ! is_string( $css ) ) {
			return null;
		}

		return array(
			'name' => $name,
			'css'  => $css,
		);
	}

	/**
	 * Update an option only when the value changed.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $current Current option value.
	 * @param array<string, mixed> $next Next option value.
	 */
	private static function update_option_if_changed( string $option_name, array $current, array $next ): bool {
		if ( $next === $current ) {
			return true;
		}

		return Environment::storage()->set( $option_name, $next );
	}

	/**
	 * Hash a stylesheet payload.
	 *
	 * @param array{name: string, css: string, type?: string} $payload Stylesheet payload.
	 */
	private static function hash_payload( array $payload ): string {
		$encoded_payload = Json::encode( $payload );

		if ( '' === $encoded_payload ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback only runs when JSON cannot represent the stylesheet payload.
			$encoded_payload = serialize( $payload );
		}

		return hash( 'sha256', $encoded_payload );
	}
}
