<?php
/**
 * Trait providing additive class attribute helpers for Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks\Concerns;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ClassProvenance;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassToken;
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
	 * Explicit typed class declarations in attachment order.
	 *
	 * Legacy class()/classes() calls intentionally remain unclassified.
	 *
	 * @var array<int, ClassToken>
	 */
	private array $class_tokens = array();

	/**
	 * Atomically emit one referenced class and attach its exact opaque style ID.
	 *
	 * @param ClassStyleReference $reference Current exact class-style identity proof.
	 * @return static
	 */
	public function class_style( ClassStyleReference $reference ): static {
		return $this->class_token( ClassToken::site_presentation( $reference ) );
	}

	/**
	 * Attach one class through its explicit owner representation.
	 *
	 * @throws InvalidArgumentException When the same token already has another owner identity.
	 */
	public function class_token( ClassToken $class_token ): static {
		$class_token->assert_current();
		$token     = $class_token->token();
		$reference = null;

		$existing = $this->find_explicit_class_token( $token );
		if ( null !== $existing ) {
			if ( ! $existing->has_same_identity( $class_token ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Class token "%s" already has conflicting provenance or origin on this block.', $token )
				);
			}

			return $this;
		}

		if (
			ClassProvenance::SITE_PRESENTATION !== $class_token->provenance()
			&& in_array( $token, $this->extract_class_tokens_from_attributes(), true )
		) {
			throw new InvalidArgumentException(
				sprintf(
					'Class token "%s" was already emitted through the unclassified legacy lane and cannot be relabelled as a non-site class. Attach its ClassToken before any legacy class API.',
					$token
				)
			);
		}

		if ( ClassProvenance::SITE_PRESENTATION === $class_token->provenance() ) {
			$reference = $class_token->style_reference();
			if ( null === $reference ) {
				throw new InvalidArgumentException( 'A site presentation class requires an exact Class Style Reference.' );
			}
		}

		$this->append_class_tokens( array( $token ) );

		if ( null !== $reference ) {
			if ( ! in_array( $reference->id(), $this->styles, true ) ) {
				$this->styles[] = $reference->id();
			}
		}

		$this->class_tokens[] = $class_token;

		return $this;
	}

	/**
	 * Return explicit class metadata for the immutable Block snapshot.
	 *
	 * @return array<int, ClassToken>
	 */
	protected function explicit_class_tokens(): array {
		return $this->class_tokens;
	}

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
			$this->unclassified_class_tokens( $this->extract_class_tokens_from_attributes() ),
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
		ClassStyleRegistry::sync_block_class_style_linkage(
			$this->unclassified_class_tokens( $class_tokens ),
			$this->styles
		);
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

		$emitted_tokens = $this->extract_class_tokens_from_attributes();
		foreach ( $this->class_tokens as $index => $class_token ) {
			$token = $class_token->token();
			if ( in_array( $token, $emitted_tokens, true ) ) {
				continue;
			}

			$reference = $class_token->style_reference();
			if ( null !== $reference ) {
				$this->styles = array_values(
					array_filter(
						$this->styles,
						static fn ( string $style_id ): bool => $style_id !== $reference->id()
					)
				);
			}

			unset( $this->class_tokens[ $index ] );
		}

		$this->class_tokens = array_values( $this->class_tokens );
	}

	/**
	 * Re-sync standalone class style IDs after attrs.styles changes.
	 */
	protected function sync_standalone_class_style_linkage(): void {
		ClassStyleRegistry::sync_block_class_style_linkage(
			$this->unclassified_class_tokens( $this->extract_class_tokens_from_attributes() ),
			$this->styles
		);
	}

	/**
	 * Remove explicitly owned tokens from legacy selector-to-style synchronization.
	 *
	 * @param array<int, string> $class_tokens Emitted HTML class tokens.
	 * @return array<int, string>
	 */
	private function unclassified_class_tokens( array $class_tokens ): array {
		return array_values(
			array_filter(
				$class_tokens,
				fn ( string $class_token ): bool => null === $this->find_explicit_class_token( $class_token )
			)
		);
	}

	/**
	 * Find an explicitly declared token without relying on PHP array-key coercion.
	 */
	private function find_explicit_class_token( string $token ): ?ClassToken {
		foreach ( $this->class_tokens as $class_token ) {
			if ( $token === $class_token->token() ) {
				return $class_token;
			}
		}

		return null;
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
		if ( '' === $class_names ) {
			return array();
		}

		$class_tokens = preg_split(
			'/[\x09\x0A\x0C\x0D\x20]+/',
			$class_names,
			-1,
			PREG_SPLIT_NO_EMPTY
		);
		if ( false === $class_tokens ) {
			return array();
		}

		$normalized_tokens = array();

		foreach ( $class_tokens as $class_token ) {
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
