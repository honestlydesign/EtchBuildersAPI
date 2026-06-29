<?php
/**
 * Trait providing additive class attribute helpers for Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks\Concerns;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Support\EtchJsonAttribute;
use HonestlyDesign\EtchBuilders\Types\Attributes;

/**
 * Provides class/style pairing helpers for fluent Etch block builders.
 *
 * Note: The consuming class MUST have:
 * - a private \HonestlyDesign\EtchBuilders\Types\Attributes $attributes property.
 * - a private array $styles property.
 */
trait HasClassAndStyleAttributes {

	/**
	 * Add a class token and matching style ID.
	 *
	 * @param string $class_name Single class token.
	 * @return static
	 * @throws InvalidArgumentException When class name is empty or contains whitespace.
	 */
	public function class( string $class_name ): static {
		$class_name = $this->validate_single_class_token( $class_name );

		$this->append_class_tokens( array( $class_name ) );
		ClassStyleRegistry::sync_block_class_style_linkage(
			$this->extract_class_tokens_from_attributes(),
			$this->styles
		);

		return $this;
	}

	/**
	 * Add multiple class tokens and matching style IDs.
	 *
	 * @param array<int, string> $class_names Class tokens to add.
	 * @return static
	 * @throws InvalidArgumentException When a class name is invalid.
	 */
	public function classes( array $class_names ): static {
		foreach ( $class_names as $class_name ) {
			if ( ! is_string( $class_name ) ) {
				throw new InvalidArgumentException( 'Class names must be strings.' );
			}

			$this->class( $class_name );
		}

		return $this;
	}

	/**
	 * Add a JSON-encoded attribute with Etch double-brace escaping.
	 *
	 * @param string                         $name  Attribute name.
	 * @param array<int|string, mixed>|string $value PHP array or pre-encoded JSON string.
	 * @return static
	 */
	public function json_attribute( string $name, array|string $value ): static {
		$this->set_attribute_value( $name, EtchJsonAttribute::encode_value( $value ) );
		return $this;
	}

	/**
	 * Set or merge an attribute value.
	 *
	 * Class attributes are merged additively; all others overwrite.
	 *
	 * @param string      $name  Attribute name.
	 * @param string|null $value Attribute value.
	 */
	private function set_attribute_value( string $name, ?string $value ): void {
		if ( null === $value ) {
			return;
		}

		if ( 'class' !== $name ) {
			$this->attributes->add( $name, $value );
			return;
		}

		$class_tokens = $this->extract_class_tokens( $value );
		if ( array() === $class_tokens ) {
			$this->attributes->add( 'class', $value );
			return;
		}

		$this->append_class_tokens( $class_tokens );
		ClassStyleRegistry::sync_block_class_style_linkage( $class_tokens, $this->styles );
	}

	/**
	 * Replace attributes while preserving class/style linkage.
	 *
	 * @param Attributes $attrs Attributes to set.
	 */
	private function set_attributes_value( Attributes $attrs ): void {
		$this->attributes = Attributes::new();

		foreach ( $attrs->to_array() as $name => $value ) {
			$this->set_attribute_value( $name, $value );
		}
	}

	/**
	 * Re-sync standalone class style IDs after attrs.styles changes.
	 */
	protected function sync_standalone_class_style_linkage(): void {
		ClassStyleRegistry::sync_block_class_style_linkage(
			$this->extract_class_tokens_from_attributes(),
			$this->styles
		);
	}

	/**
	 * Class tokens from the current class attribute.
	 *
	 * @return array<int, string>
	 */
	protected function extract_class_tokens_from_attributes(): array {
		return $this->extract_class_tokens( (string) $this->attributes->get( 'class' ) );
	}

	/**
	 * Append class tokens to the class attribute.
	 *
	 * @param array<int, string> $class_tokens Class tokens to append.
	 */
	private function append_class_tokens( array $class_tokens ): void {
		$existing_tokens = $this->extract_class_tokens( $this->attributes->get( 'class' ) );
		$merged_tokens   = $existing_tokens;

		foreach ( $class_tokens as $class_token ) {
			if ( ! in_array( $class_token, $merged_tokens, true ) ) {
				$merged_tokens[] = $class_token;
			}
		}

		$this->attributes->add( 'class', implode( ' ', $merged_tokens ) );
	}

	/**
	 * Extract class tokens from an attribute string.
	 *
	 * @param string $class_names Class attribute string.
	 * @return array<int, string>
	 */
	private function extract_class_tokens( string $class_names ): array {
		$class_names = trim( $class_names );
		if ( '' === $class_names ) {
			return array();
		}

		$class_tokens = preg_split( '/\s+/', $class_names );
		if ( false === $class_tokens ) {
			return array();
		}

		$normalized_tokens = array();

		foreach ( $class_tokens as $class_token ) {
			$class_token = trim( $class_token );
			if ( '' === $class_token ) {
				continue;
			}

			if ( ! in_array( $class_token, $normalized_tokens, true ) ) {
				$normalized_tokens[] = $class_token;
			}
		}

		return $normalized_tokens;
	}

	/**
	 * Validate a single class token for class().
	 *
	 * @param string $class_name Proposed class token.
	 * @return string
	 * @throws InvalidArgumentException When class name is invalid.
	 */
	private function validate_single_class_token( string $class_name ): string {
		$class_name = trim( $class_name );

		if ( '' === $class_name ) {
			throw new InvalidArgumentException( 'class() requires a non-empty single class token.' );
		}

		if ( 1 === preg_match( '/\s/', $class_name ) ) {
			throw new InvalidArgumentException( 'class() requires a single class token. Use classes() for multiple classes.' );
		}

		return $class_name;
	}
}
