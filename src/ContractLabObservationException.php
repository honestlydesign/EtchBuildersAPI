<?php
/**
 * Fail-closed Contract Lab observation error.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use RuntimeException;

/**
 * Distinguishes malformed or unavailable observation inputs from a match.
 */
final class ContractLabObservationException extends RuntimeException {

	public function __construct( private readonly string $reason, string $message ) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}
}
