<?php
/**
 * Fail-closed Contract Lab fixture lifecycle error.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use RuntimeException;

/**
 * Signals that fixture ownership or state cannot be proven safely.
 */
final class ContractLabFixtureException extends RuntimeException {

	public function __construct( string $code, string $message ) {
		parent::__construct( $message );
		$this->code = 'ETCH_CONTRACT_FIXTURE_' . strtoupper( $code );
	}
}
