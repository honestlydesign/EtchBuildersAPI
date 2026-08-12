<?php
/**
 * Semantic normalization for WordPress parsed block trees.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Removes parser-only HTML fields while retaining contract-significant names,
 * typed attributes, and ordered child topology.
 */
final class ContractLabBlockTreeNormalizer {

	private const BLOCK_NAME_PATTERN = '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D';

	private function __construct() {
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array{block_name: string, attributes: array<string, mixed>, inner_blocks: array<int, array<string, mixed>>}>
	 */
	public static function normalize( array $blocks ): array {
		if ( ! array_is_list( $blocks ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress parsed block tree must be an ordered list.' );
		}
		if ( array() === $blocks ) {
			throw new ContractLabObservationException( 'unsupported', 'WordPress parsed block tree contains no supported blocks.' );
		}

		$normalized = array();
		foreach ( $blocks as $block ) {
			$normalized[] = self::normalize_block( $block );
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array{block_name: string, attributes: array<string, mixed>, inner_blocks: array<int, array<string, mixed>>}
	 */
	private static function normalize_block( array $block ): array {
		foreach ( array( 'blockName', 'attrs', 'innerBlocks' ) as $required_key ) {
			if ( ! array_key_exists( $required_key, $block ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress parsed block is missing %s.', $required_key ) );
			}
		}

		$block_name   = $block['blockName'];
		$attributes   = $block['attrs'];
		$inner_blocks = $block['innerBlocks'];
		if ( ! is_string( $block_name ) || 1 !== preg_match( self::BLOCK_NAME_PATTERN, $block_name ) ) {
			throw new ContractLabObservationException( 'unsupported', 'WordPress parsed block has an unsupported block name.' );
		}
		if ( ! is_array( $attributes ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress parsed block "%s" attrs must be an object.', $block_name ) );
		}
		if ( ! array_is_list( $inner_blocks ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress parsed block "%s" innerBlocks must be an ordered list.', $block_name ) );
		}
		foreach ( array_keys( $attributes ) as $attribute_name ) {
			if ( ! is_string( $attribute_name ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress parsed block "%s" attrs must use string keys.', $block_name ) );
			}
		}

		AcyclicArrayGuard::assert_acyclic( $attributes );
		$attributes = ImmutableArray::copy( $attributes, 'WordPress parsed block attributes must contain only scalar, null, or nested array values.' );
		$children   = array();
		foreach ( $inner_blocks as $child ) {
			if ( ! is_array( $child ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress parsed block "%s" has a malformed child block.', $block_name ) );
			}
			$children[] = self::normalize_block( $child );
		}

		/** @var array<string, mixed> $attributes */
		return array(
			'block_name'   => $block_name,
			'attributes'   => ImmutableArray::canonicalize( $attributes ),
			'inner_blocks' => $children,
		);
	}
}
