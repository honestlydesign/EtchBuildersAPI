<?php
/**
 * Etch Gutenberg block markup builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Support\Json;

/**
 * Builds serialized Gutenberg markup for Etch and WordPress core blocks.
 */
final class Block {

	private const BLOCK_NAMESPACE_PREFIX = 'etch/';

	/**
	 * Known Etch block names.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_BLOCK_NAMES = array(
		'etch/element',
		'etch/dynamic-element',
		'etch/text',
		'etch/component',
		'etch/condition',
		'etch/loop',
		'etch/svg',
		'etch/slot-content',
		'etch/slot-placeholder',
		'etch/raw-html',
		'etch/dynamic-image',
	);

	/**
	 * Normalized block name (etch/* or core block slug for wp:* comments).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Block attributes.
	 *
	 * @var array<string, mixed>
	 */
	private array $attributes;

	/**
	 * Whether block is self-closing.
	 *
	 * @var bool
	 */
	private bool $self_closing;

	/**
	 * Non-wire class ownership metadata in attachment order.
	 *
	 * @var array<int, ClassToken>
	 */
	private array $class_tokens;

	/**
	 * Checked raw fragment metadata, when this block was created from one.
	 */
	private ?RawFragment $raw_fragment;

	/**
	 * Child blocks.
	 *
	 * @var array<int, self>
	 */
	private array $children = array();

	/**
	 * Create a container Etch block.
	 *
	 * @param string               $name Block name with or without etch/ prefix.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, ClassToken> $class_tokens Explicit non-wire class metadata.
	 * @param RawFragment|null      $raw_fragment Checked raw fragment metadata.
	 * @throws InvalidArgumentException When block name, attributes, or class metadata are invalid.
	 */
	public static function new( string $name, array $attributes = array(), array $class_tokens = array(), ?RawFragment $raw_fragment = null ): self {
		return new self( $name, $attributes, false, false, $class_tokens, $raw_fragment );
	}

	/**
	 * Create a self-closing Etch block.
	 *
	 * @param string               $name Block name with or without etch/ prefix.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, ClassToken> $class_tokens Explicit non-wire class metadata.
	 * @param RawFragment|null      $raw_fragment Checked raw fragment metadata.
	 * @throws InvalidArgumentException When block name, attributes, or class metadata are invalid.
	 */
	public static function new_self_closing( string $name, array $attributes = array(), array $class_tokens = array(), ?RawFragment $raw_fragment = null ): self {
		return new self( $name, $attributes, true, false, $class_tokens, $raw_fragment );
	}

	/**
	 * Create a self-closing WordPress core block.
	 *
	 * @param string               $name       Core block name (e.g. post-content or core/post-content).
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $self_closing Whether the block is self-closing.
	 * @throws InvalidArgumentException When block name or attributes are invalid.
	 */
	public static function new_core( string $name, array $attributes = array(), bool $self_closing = true ): self {
		return new self( $name, $attributes, $self_closing, true );
	}

	/**
	 * Add a single child block.
	 *
	 * @param self $child Child block to add.
	 * @throws InvalidArgumentException When adding child to self-closing block.
	 */
	public function add_child( self $child ): self {
		if ( $this->self_closing ) {
			throw new InvalidArgumentException( 'Cannot add children to a self-closing Etch block.' );
		}

		$this->children[] = $child;

		return $this;
	}

	/**
	 * Add multiple child blocks.
	 *
	 * @param array<int, self> $children Child block instances.
	 * @throws InvalidArgumentException When a non-Block is passed.
	 */
	public function add_children( array $children ): self {
		foreach ( $children as $child ) {
			if ( ! ( $child instanceof self ) ) {
				throw new InvalidArgumentException( 'Block::add_children expects an array of Block instances.' );
			}

			$this->add_child( $child );
		}

		return $this;
	}

	/**
	 * Whether this block has one exact normalized Etch block name.
	 *
	 * This narrow introspection hook lets higher-level typed builders enforce
	 * placement rules without parsing their own serialized markup.
	 */
	public function is_named( string $name ): bool {
		return $this->name === self::normalize_block_name( $name );
	}

	/**
	 * Return the normalized block name for compiler traversal.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Return a copy-on-write snapshot of serialized block attributes.
	 *
	 * @return array<string, mixed>
	 */
	public function attributes(): array {
		return $this->attributes;
	}

	/**
	 * Return detached child snapshots in insertion order.
	 *
	 * @return array<int, self>
	 */
	public function children(): array {
		return array_map( static fn ( self $child ): self => $child->detached_copy(), $this->children );
	}

	/**
	 * Return a detached structural snapshot of this finite block tree.
	 *
	 * @throws InvalidArgumentException When the graph contains a cycle.
	 */
	public function detached_copy(): self {
		return $this->copy_with_ancestors( array() );
	}

	/**
	 * Return explicit non-wire class declarations for compiler policy checks.
	 *
	 * @return array<int, ClassToken>
	 */
	public function class_tokens(): array {
		return $this->class_tokens;
	}

	/**
	 * Return checked raw fragment metadata attached to this block, if any.
	 */
	public function raw_fragment(): ?RawFragment {
		return $this->raw_fragment;
	}

	/**
	 * Return checked raw fragments attached throughout this block tree.
	 *
	 * @return array<int, RawFragment>
	 */
	public function children_raw_fragments(): array {
		$fragments = null !== $this->raw_fragment ? array( $this->raw_fragment ) : array();

		foreach ( $this->children as $child ) {
			$fragments = array_merge( $fragments, $child->children_raw_fragments() );
		}

		return $fragments;
	}

	/**
	 * Return explicit class declarations from this complete block tree.
	 *
	 * @return array<int, ClassToken>
	 */
	public function class_tokens_in_tree(): array {
		$class_tokens = $this->class_tokens;

		foreach ( $this->children as $child ) {
			$class_tokens = array_merge( $class_tokens, $child->class_tokens_in_tree() );
		}

		return $class_tokens;
	}

	/**
	 * Whether this tree contains one of the exact block names outside an
	 * ownership boundary. A boundary root itself is allowed and its descendants
	 * belong to that nested owner.
	 *
	 * @param array<int, string> $names Exact normalized or unprefixed Etch names.
	 */
	public function contains_named_outside_boundary( array $names, string $boundary ): bool {
		if ( $this->is_named( $boundary ) ) {
			return false;
		}

		foreach ( $names as $name ) {
			if ( $this->is_named( $name ) ) {
				return true;
			}
		}

		foreach ( $this->children as $child ) {
			if ( $child->contains_named_outside_boundary( $names, $boundary ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render block as serialized Gutenberg markup.
	 */
	public function to_string(): string {
		$attributes_json = self::encode_attributes( $this->attributes );

		$opening = '<!-- wp:' . $this->name;
		if ( '' !== $attributes_json ) {
			$opening .= ' ' . $attributes_json;
		}

		if ( $this->self_closing ) {
			return $opening . ' /-->';
		}

		$children_markup = '';
		foreach ( $this->children as $child ) {
			$children_markup .= $child->to_string();
		}

		return $opening . ' -->' . $children_markup . '<!-- /wp:' . $this->name . ' -->';
	}

	/**
	 * Internal constructor.
	 *
	 * @param string               $name Block name.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $self_closing Whether the block is self-closing.
	 * @param array<int, ClassToken> $class_tokens Explicit non-wire class metadata.
	 * @param RawFragment|null      $raw_fragment Checked raw fragment metadata.
	 * @throws InvalidArgumentException When block name or class metadata is invalid.
	 */
	private function __construct(
		string $name,
		array $attributes,
		bool $self_closing,
		bool $is_core_block = false,
		array $class_tokens = array(),
		?RawFragment $raw_fragment = null
	) {
		self::assert_attribute_keys( $attributes );

		$this->attributes   = $attributes;
		$this->self_closing = $self_closing;
		$this->name         = $is_core_block ? self::normalize_core_block_name( $name ) : self::normalize_block_name( $name );
		$this->class_tokens = self::validate_class_tokens( $class_tokens, $attributes );
		if ( null !== $raw_fragment && ( 'etch/raw-html' !== $this->name || ! $self_closing ) ) {
			throw new InvalidArgumentException( 'Checked RawFragment metadata is only valid on self-closing etch/raw-html blocks.' );
		}
		$this->raw_fragment = $raw_fragment;
	}

	/**
	 * Validate and deduplicate explicit class metadata in attachment order.
	 *
	 * @param array<array-key, mixed> $class_tokens Explicit class declarations.
	 * @param array<string, mixed>    $attributes   Complete serialized block attributes.
	 * @return array<int, ClassToken>
	 */
	private static function validate_class_tokens( array $class_tokens, array $attributes ): array {
		$validated     = array();
		$emitted       = self::emitted_class_tokens( $attributes );
		$attached_ids  = isset( $attributes['styles'] ) && is_array( $attributes['styles'] )
			? $attributes['styles']
			: array();

		foreach ( $class_tokens as $class_token ) {
			if ( ! ( $class_token instanceof ClassToken ) ) {
				throw new InvalidArgumentException( 'Block class metadata must contain only ClassToken values.' );
			}
			$token = $class_token->token();

			foreach ( $validated as $prior ) {
				if ( $token !== $prior->token() ) {
					continue;
				}

				if ( ! $prior->has_same_identity( $class_token ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Block class token "%s" has conflicting provenance metadata.', $token )
					);
				}

				continue 2;
			}

			$class_token->assert_current();

			if ( ! in_array( $token, $emitted, true ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Block class metadata for "%s" requires the same emitted HTML class token.', $token )
				);
			}

			if ( ClassProvenance::SITE_PRESENTATION === $class_token->provenance() ) {
				$reference = $class_token->style_reference();
				if ( null === $reference || ! in_array( $reference->id(), $attached_ids, true ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Site presentation class "%s" requires its exact opaque style ID on the same block.', $token )
					);
				}
			}

			$validated[] = $class_token;
		}

		return $validated;
	}

	/**
	 * Extract the unchanged HTML class tokens from serialized block attributes.
	 *
	 * @param array<string, mixed> $attributes Complete serialized block attributes.
	 * @return array<int, string>
	 */
	private static function emitted_class_tokens( array $attributes ): array {
		$html_attributes = isset( $attributes['attributes'] ) && is_array( $attributes['attributes'] )
			? $attributes['attributes']
			: array();
		$class_names = isset( $html_attributes['class'] ) && is_string( $html_attributes['class'] )
			? $html_attributes['class']
			: '';

		if ( '' === $class_names ) {
			return array();
		}

		$tokens = preg_split(
			'/[\x09\x0A\x0C\x0D\x20]+/',
			$class_names,
			-1,
			PREG_SPLIT_NO_EMPTY
		);
		return false === $tokens ? array() : array_values( array_unique( $tokens ) );
	}

	/**
	 * @param array<int, true> $ancestors Active path keyed by object ID.
	 */
	private function copy_with_ancestors( array $ancestors ): self {
		$object_id = spl_object_id( $this );
		if ( isset( $ancestors[ $object_id ] ) ) {
			throw new InvalidArgumentException( 'Block snapshot requires a finite, non-recursive block tree.' );
		}
		$ancestors[ $object_id ] = true;

		$copy           = clone $this;
		$copy->children = array();
		foreach ( $this->children as $child ) {
			$copy->children[] = $child->copy_with_ancestors( $ancestors );
		}

		return $copy;
	}

	/**
	 * Assert block attribute keys are strings.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @throws InvalidArgumentException When a non-string key is provided.
	 */
	private static function assert_attribute_keys( array $attributes ): void {
		foreach ( $attributes as $attribute_name => $_ ) {
			if ( ! is_string( $attribute_name ) ) {
				throw new InvalidArgumentException( 'Block attributes must use string keys.' );
			}
		}
	}

	/**
	 * Normalize and validate block name.
	 *
	 * @param string $name Block name to normalize.
	 * @return string Normalized block name.
	 * @throws InvalidArgumentException When block name is invalid.
	 */
	private static function normalize_block_name( string $name ): string {
		$normalized_name = trim( $name );

		if ( '' === $normalized_name ) {
			throw new InvalidArgumentException( 'Etch block name must be non-empty.' );
		}

		if ( ! str_contains( $normalized_name, '/' ) ) {
			$normalized_name = self::BLOCK_NAMESPACE_PREFIX . $normalized_name;
		}

		if ( 1 !== preg_match( '/^etch\/[a-z][a-z0-9-]*$/', $normalized_name ) ) {
			throw new InvalidArgumentException( 'Etch block name must match /^etch\/[a-z][a-z0-9-]*$/.' );
		}

		if ( ! in_array( $normalized_name, self::KNOWN_BLOCK_NAMES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message for developers.
			throw new InvalidArgumentException( 'Unknown Etch block name: ' . $normalized_name );
		}

		return $normalized_name;
	}

	/**
	 * Normalize and validate a WordPress core block name.
	 *
	 * @param string $name Block name to normalize.
	 * @return string Normalized core block slug used in wp:* comments.
	 * @throws InvalidArgumentException When block name is invalid.
	 */
	private static function normalize_core_block_name( string $name ): string {
		$normalized_name = trim( $name );

		if ( '' === $normalized_name ) {
			throw new InvalidArgumentException( 'Core block name must be non-empty.' );
		}

		if ( str_starts_with( $normalized_name, 'core/' ) ) {
			$normalized_name = substr( $normalized_name, strlen( 'core/' ) );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)*$/', $normalized_name ) ) {
			throw new InvalidArgumentException( 'Core block name must match /^[a-z][a-z0-9-]*(?:\\/[a-z][a-z0-9-]*)*$/' );
		}

		return $normalized_name;
	}

	/**
	 * Encode attributes as JSON.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string JSON encoded attributes.
	 * @throws InvalidArgumentException When JSON encoding fails.
	 */
	private static function encode_attributes( array $attributes ): string {
		if ( array() === $attributes ) {
			return '';
		}

		$encoded_attributes = Json::encode( $attributes );
		if ( '' === $encoded_attributes ) {
			throw new InvalidArgumentException( 'Failed to encode Etch block attributes as JSON.' );
		}

		return $encoded_attributes;
	}
}
