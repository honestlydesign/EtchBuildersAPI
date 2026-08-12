<?php
/**
 * Persistence port for immutable compiled Site plans.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\SitePersistenceReport;

/**
 * Applies only a validated Compiled Site Plan.
 */
interface SitePersistenceInterface {

	/**
	 * Apply a compiled plan through the adapter's persistence boundary.
	 *
	 * Implementations must reject plans with blocking diagnostics before they
	 * inspect or mutate their backing store.
	 */
	public function apply( CompiledSitePlan $plan ): SitePersistenceReport;
}
