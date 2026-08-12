<?php
/**
 * Real WordPress block wire round-trip probe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface;

/**
 * Parses with the supplied runtime, serializes with that same runtime, then
 * compares normalized semantic trees. It never implements a parser itself.
 */
final class ContractLabBlockRoundTripProbe {

	private function __construct() {
	}

	public static function run( string $markup, ContractLabBlockWireAdapterInterface $adapter ): ContractLabBlockRoundTripResult {
		if ( '' === trim( $markup ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab block fixture markup must be non-empty.' );
		}

		$parsed = $adapter->parse( $markup );
		$before = ContractLabBlockTreeNormalizer::normalize( $parsed );
		$serialized = $adapter->serialize( $parsed );
		if ( '' === trim( $serialized ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress block serializer returned empty markup.' );
		}

		$round_tripped = $adapter->parse( $serialized );
		$after         = ContractLabBlockTreeNormalizer::normalize( $round_tripped );

		return ContractLabBlockRoundTripResult::compare( $before, $after );
	}
}
