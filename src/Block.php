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
	 * @throws InvalidArgumentException When block name or attributes are invalid.
	 */
	public static function new( string $name, array $attributes = array() ): self {
		return new self( $name, $attributes, false );
	}

	/**
	 * Create a self-closing Etch block.
	 *
	 * @param string               $name Block name with or without etch/ prefix.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @throws InvalidArgumentException When block name or attributes are invalid.
	 */
	public static function new_self_closing( string $name, array $attributes = array() ): self {
		return new self( $name, $attributes, true );
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
	 * @throws InvalidArgumentException When block name is invalid.
	 */
	private function __construct( string $name, array $attributes, bool $self_closing, bool $is_core_block = false ) {
		$this->attributes   = $attributes;
		$this->self_closing = $self_closing;
		$this->name         = $is_core_block ? self::normalize_core_block_name( $name ) : self::normalize_block_name( $name );

		self::assert_attribute_keys( $this->attributes );
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
