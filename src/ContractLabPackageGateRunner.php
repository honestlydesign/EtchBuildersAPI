<?php
/**
 * Executes the fixed maintainer package gate command set.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Creates package evidence from real local process results.
 *
 * The command strings are owned by ContractLabPackageGateEvidence and are
 * never accepted from a caller, so a live run cannot substitute an easier
 * command while retaining the same gate identity.
 */
final class ContractLabPackageGateRunner {

	/**
	 * Run every required package gate in a fixed order.
	 */
	public static function run( string $working_directory, string $artifact_directory ): ContractLabPackageGateSet {
		return ContractLabPackageGateEvidence::run( $working_directory, $artifact_directory );
	}
}
