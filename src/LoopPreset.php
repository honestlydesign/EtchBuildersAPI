<?php
/**
 * Builder for Etch loop presets.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\Support\Json;
use HonestlyDesign\EtchBuilders\Support\Key;

/**
 * Builds loop preset arrays with a fluent API.
 */
final class LoopPreset {

	/**
	 * Option name used for persisted loop presets.
	 */
	private const OPTION_NAME = 'etch_loops';

	/**
	 * Hash key used to identify builder-managed presets.
	 */
	private const HASH_KEY = '_omide_builder_hash';

	/**
	 * Preset name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Preset ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Preset key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Whether the preset is global.
	 *
	 * @var bool
	 */
	private bool $global = true;

	/**
	 * Whether persisted presets may be overwritten.
	 *
	 * @var bool
	 */
	private bool $overwrite = false;

	/**
	 * Loop type.
	 *
	 * @var string|null
	 */
	private ?string $type = null;

	/**
	 * Config payload for the loop type.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $config_payload = array();

	/**
	 * In-memory registry of preset keys registered during the current pass.
	 *
	 * Keyed by preset key => preset id. Used by LoopBlock::loop_id() to validate
	 * that the referenced preset exists before encoding the block.
	 *
	 * @var array<string, string>
	 */
	private static array $registered = array();

	/**
	 * Constructor.
	 *
	 * @param string $name Preset name.
	 * @throws InvalidArgumentException When the name is empty or cannot produce a sanitized key.
	 */
	private function __construct( string $name ) {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Loop preset name must be non-empty.' );
		}

		$this->name = $name;
		$this->id   = self::derive_key_from_name( $name );
		$this->key  = self::derive_key_from_name( $name );
	}

	/**
	 * Create a new LoopPreset builder.
	 *
	 * @param string $name Preset name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the preset ID.
	 *
	 * @param string $id Preset ID.
	 * @throws InvalidArgumentException When the ID is not a sanitized key.
	 */
	public function id( string $id ): self {
		$this->id = self::validate_sanitized_identifier( $id, 'ID' );

		return $this;
	}

	/**
	 * Set the preset key.
	 *
	 * @param string $key Preset key.
	 * @throws InvalidArgumentException When the key is not a sanitized key.
	 */
	public function key( string $key ): self {
		$this->key = self::validate_sanitized_identifier( $key, 'key' );

		return $this;
	}

	/**
	 * Set whether the preset is global.
	 *
	 * @param bool $is_global Whether the preset is global.
	 */
	public function global( bool $is_global ): self {
		$this->global = $is_global;

		return $this;
	}

	/**
	 * Set whether existing persisted presets may be overwritten.
	 *
	 * @param bool $overwrite Whether existing persisted presets may be overwritten.
	 */
	public function overwrite( bool $overwrite = true ): self {
		$this->overwrite = $overwrite;

		return $this;
	}

	/**
	 * Configure a WP_Query loop preset.
	 *
	 * @param array<string, mixed> $args WP_Query args.
	 * @throws InvalidArgumentException When args are empty or numerically keyed.
	 */
	public function wp_query( array $args ): self {
		self::validate_non_empty_args( $args, 'wp-query' );

		$this->type           = 'wp-query';
		$this->config_payload = array(
			'args' => $args,
		);

		return $this;
	}

	/**
	 * Configure a main query loop preset.
	 *
	 * @param array<string, mixed> $args Main query args.
	 * @throws InvalidArgumentException When args are numerically keyed.
	 */
	public function main_query( array $args = array() ): self {
		self::validate_args( $args );

		$this->type           = 'main-query';
		$this->config_payload = array(
			'args' => $args,
		);

		return $this;
	}

	/**
	 * Configure a WP_Term_Query loop preset.
	 *
	 * @param array<string, mixed> $args Term query args.
	 * @throws InvalidArgumentException When args are empty or numerically keyed.
	 */
	public function wp_terms( array $args ): self {
		self::validate_non_empty_args( $args, 'wp-terms' );

		$this->type           = 'wp-terms';
		$this->config_payload = array(
			'args' => $args,
		);

		return $this;
	}

	/**
	 * Configure a WP_User_Query loop preset.
	 *
	 * @param array<string, mixed> $args User query args.
	 * @throws InvalidArgumentException When args are empty or numerically keyed.
	 */
	public function wp_users( array $args ): self {
		self::validate_non_empty_args( $args, 'wp-users' );

		$this->type           = 'wp-users';
		$this->config_payload = array(
			'args' => $args,
		);

		return $this;
	}

	/**
	 * Configure a JSON loop preset.
	 *
	 * @param array<int, mixed> $data JSON data rows.
	 * @throws InvalidArgumentException When data is empty.
	 */
	public function json( array $data ): self {
		if ( array() === $data ) {
			throw new InvalidArgumentException( 'json loop presets require non-empty data.' );
		}

		$this->type           = 'json';
		$this->config_payload = array(
			'data' => $data,
		);

		return $this;
	}

	/**
	 * Serialize the loop preset to an array.
	 *
	 * @return array{name: string, key: string, global: bool, config: array<int|string, mixed>}
	 * @throws InvalidArgumentException When the preset has no config.
	 */
	public function to_array(): array {
		if ( null === $this->type ) {
			throw new InvalidArgumentException( 'LoopPreset requires a config.' );
		}

		return array(
			'name'   => $this->name,
			'key'    => $this->key,
			'global' => $this->global,
			'config' => array_merge(
				array(
					'type' => $this->type,
				),
				$this->config_payload
			),
		);
	}

	/**
	 * Register this preset's key in the in-memory registry.
	 *
	 * Called by register() so LoopBlock::loop_id() can validate against it.
	 * Returns self for fluent chaining.
	 *
	 * @return self
	 */
	public function register_internal(): self {
		self::$registered[ $this->key ] = $this->id;
		return $this;
	}

	/**
	 * All preset keys registered during the current pass.
	 *
	 * @return array<int, string>
	 */
	public static function registered_keys(): array {
		return array_keys( self::$registered );
	}

	/**
	 * Whether a preset key is registered.
	 *
	 * @param string $key Preset key.
	 */
	public static function is_registered_key( string $key ): bool {
		return isset( self::$registered[ $key ] );
	}

	/**
	 * Capture the current in-memory registry.
	 *
	 * @return array<string, string>
	 */
	public static function snapshot(): array {
		return self::$registered;
	}

	/**
	 * Restore the in-memory registry from a snapshot.
	 *
	 * @param array<string, string> $registry Snapshot.
	 */
	public static function restore( array $registry ): void {
		self::$registered = $registry;
	}

	/**
	 * Clear the in-memory registry.
	 */
	public static function reset(): void {
		self::$registered = array();
	}

	/**
	 * Register the loop preset.
	 *
	 * @return bool|RegistrationResult True on success, RegistrationResult on conflict/failure.
	 * @throws InvalidArgumentException When the preset has no config.
	 */
	public function register(): bool|RegistrationResult {
		$this->register_internal();

		$clean_preset = $this->to_array();
		$current      = Environment::storage()->get( self::OPTION_NAME, array() );
		$current      = is_array( $current ) ? $current : array();

		if (
			! $this->overwrite
			&& array_key_exists( $this->id, $current )
			&& ! $this->is_existing_preset_builder_owned( $current[ $this->id ] )
		) {
			return RegistrationResult::error(
				'loop_preset_exists',
				'Loop preset already exists and is not owned by this builder.'
			);
		}

		$preset_with_hash                   = $clean_preset;
		$preset_with_hash[ self::HASH_KEY ] = self::hash_payload( $clean_preset );

		$next_loops              = $current;
		$next_loops[ $this->id ] = $preset_with_hash;

		if ( $next_loops === $current ) {
			return true;
		}

		if ( Environment::storage()->set( self::OPTION_NAME, $next_loops ) ) {
			return true;
		}

		return RegistrationResult::error(
			'loop_preset_update_failed',
			'Loop preset option could not be updated.'
		);
	}

	/**
	 * Determine whether an existing preset is builder-owned and unchanged.
	 *
	 * @param mixed $existing_preset Existing preset payload.
	 */
	private function is_existing_preset_builder_owned( mixed $existing_preset ): bool {
		if ( ! is_array( $existing_preset ) ) {
			return false;
		}

		$hash = $existing_preset[ self::HASH_KEY ] ?? null;

		if ( ! is_string( $hash ) || '' === $hash ) {
			return false;
		}

		unset( $existing_preset[ self::HASH_KEY ] );

		return hash_equals( $hash, self::hash_payload( $existing_preset ) );
	}

	/**
	 * Hash a clean preset payload.
	 *
	 * @param array<string, mixed> $payload Clean preset payload.
	 */
	private static function hash_payload( array $payload ): string {
		$encoded_payload = Json::encode( $payload );

		if ( '' === $encoded_payload ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback only runs when JSON cannot represent the preset payload.
			$encoded_payload = serialize( $payload );
		}

		return hash( 'sha256', $encoded_payload );
	}

	/**
	 * Validate explicit sanitized identifiers.
	 *
	 * @param string $value Identifier to validate.
	 * @param string $label Identifier label.
	 * @throws InvalidArgumentException When the identifier is not sanitized.
	 */
	private static function validate_sanitized_identifier( string $value, string $label ): string {
		if ( '' === $value || Key::sanitize( $value ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Label is an internal constant from builder call sites.
			throw new InvalidArgumentException( "Loop preset {$label} must be a non-empty sanitized key." );
		}

		return $value;
	}

	/**
	 * Validate non-empty keyed args for loop query presets.
	 *
	 * @param array<string, mixed> $args Args to validate.
	 * @param string               $type Loop type.
	 * @throws InvalidArgumentException When args are empty or numerically keyed.
	 */
	private static function validate_non_empty_args( array $args, string $type ): void {
		if ( array() === $args ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Type is an internal loop preset constant from builder methods.
			throw new InvalidArgumentException( "{$type} loop presets require non-empty args." );
		}

		self::validate_args( $args );
	}

	/**
	 * Validate keyed args for loop query presets.
	 *
	 * @param array<string, mixed> $args Args to validate.
	 * @throws InvalidArgumentException When args are numerically keyed.
	 */
	private static function validate_args( array $args ): void {
		foreach ( array_keys( $args ) as $key ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Loop preset args must use string keys.' );
			}
		}
	}

	/**
	 * Derive a sanitized key from a display name.
	 *
	 * @param string $name Display name.
	 * @throws InvalidArgumentException When the name cannot produce a sanitized key.
	 */
	private static function derive_key_from_name( string $name ): string {
		$key = strtolower( trim( $name ) );
		$key = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $key );
		$key = trim( $key, '-' );
		$key = Key::sanitize( $key );

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Loop preset name must produce a non-empty sanitized key.' );
		}

		return $key;
	}
}
