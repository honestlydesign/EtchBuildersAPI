<?php
/**
 * Base block attributes shared across all Etch block types.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Types;

use InvalidArgumentException;

/**
 * Common attributes for all Gutenberg blocks (script, hidden, options).
 *
 * This is the shared foundation that all block builders extend.
 */
final class BlockBase {

	/**
	 * Script configuration (id and code for dynamic behavior).
	 *
	 * @var Script|null
	 */
	private ?Script $script = null;

	/**
	 * Whether the block is hidden.
	 *
	 * @var bool
	 */
	private bool $hidden = false;

	/**
	 * Arbitrary options for specific block types.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create a new base block attributes container.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Create from array (legacy support).
	 *
	 * @param array<string, mixed> $config Base configuration.
	 */
	public static function from_array( array $config ): self {
		$instance = new self();

		if ( array_key_exists( 'script', $config ) && is_array( $config['script'] ) ) {
			$instance->script = Script::from_array( $config['script'] );
		}

		if ( array_key_exists( 'hidden', $config ) ) {
			$instance->hidden = (bool) $config['hidden'];
		}

		if ( array_key_exists( 'options', $config ) && is_array( $config['options'] ) ) {
			$instance->options = $config['options'];
		}

		return $instance;
	}

	/**
	 * Add a script to the block.
	 *
	 * @param string $id   Script identifier.
	 * @param string $code JavaScript code.
	 */
	public function script( string $id, string $code ): self {
		$this->script = Script::new( $id, $code );
		return $this;
	}

	/**
	 * Set the script using a Script object.
	 *
	 * @param Script $script The script object to set.
	 */
	public function set_script( Script $script ): self {
		$this->script = $script;
		return $this;
	}

	/**
	 * Get the script if set.
	 */
	public function get_script(): ?Script {
		return $this->script;
	}

	/**
	 * Set hidden state.
	 *
	 * @param bool $hidden Whether to hide the block.
	 */
	public function hidden( bool $hidden = true ): self {
		$this->hidden = $hidden;
		return $this;
	}

	/**
	 * Check if hidden.
	 */
	public function is_hidden(): bool {
		return $this->hidden;
	}

	/**
	 * Add an option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 */
	public function option( string $key, mixed $value ): self {
		$this->options[ $key ] = $value;
		return $this;
	}

	/**
	 * Add multiple options at once.
	 *
	 * @param array<string, mixed> $options Options to add.
	 * @throws InvalidArgumentException When non-string key is provided.
	 */
	public function options( array $options ): self {
		foreach ( $options as $key => $value ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					'Option keys must be strings. Got: ' . gettype( $key )
				);
			}
			$this->options[ $key ] = $value;
		}
		return $this;
	}

	/**
	 * Get an option value.
	 *
	 * @param string $key           Option key.
	 * @param mixed  $default_value Default value if not found.
	 */
	public function get_option( string $key, mixed $default_value = null ): mixed {
		return $this->options[ $key ] ?? $default_value;
	}

	/**
	 * Get all options.
	 *
	 * @return array<string, mixed>
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Convert to array for block attributes.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$result = array();

		if ( $this->hidden ) {
			$result['hidden'] = true;
		}

		if ( null !== $this->script ) {
			$result['script'] = $this->script->to_array();
		}

		if ( array() !== $this->options ) {
			$result['options'] = $this->options;
		}

		return $result;
	}

	/**
	 * Merge with another BlockBase instance.
	 *
	 * @param self $other The BlockBase instance to merge.
	 */
	public function merge( self $other ): self {
		if ( null !== $other->script ) {
			$this->script = $other->script;
		}

		$this->hidden  = $other->hidden || $this->hidden;
		$this->options = array_merge( $this->options, $other->options );

		return $this;
	}
}
