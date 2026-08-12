<?php
/**
 * WordPress runtime adapter for Contract Lab block wire probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface;

/**
 * Delegates to WordPress' public parser and serializer; no copied wire logic.
 */
final class WordPressBlockWireAdapter implements ContractLabBlockWireAdapterInterface {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse( string $markup ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			throw new ContractLabObservationException( 'unavailable', 'WordPress parse_blocks() is unavailable.' );
		}

		$parsed = \parse_blocks( $markup );
		if ( ! is_array( $parsed ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress parse_blocks() returned a non-array result.' );
		}

		return $parsed;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	public function serialize( array $blocks ): string {
		if ( ! function_exists( 'serialize_blocks' ) ) {
			throw new ContractLabObservationException( 'unavailable', 'WordPress serialize_blocks() is unavailable.' );
		}

		/**
		 * WordPress owns this parsed shape. The adapter deliberately forwards it
		 * unchanged; ContractLabBlockTreeNormalizer validates semantic fields
		 * before the result is observed.
		 *
		 * @var array<int, array{blockName: string|null, attrs: array, innerBlocks: array<array>, innerHTML: string, innerContent: array}> $blocks
		 */
		$serialized = \serialize_blocks( $blocks );
		if ( ! is_string( $serialized ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress serialize_blocks() returned a non-string result.' );
		}

		return $serialized;
	}
}
