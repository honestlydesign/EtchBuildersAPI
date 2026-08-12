<?php
/**
 * Optional resource reconciliation capabilities for compiled Site stores.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Extends the base persistence port with exact resource cleanup and migration.
 *
 * The optional boundary keeps existing custom stores source-compatible while
 * allowing native stores to reconcile resources proven by persisted records.
 */
interface SitePersistenceResourceStoreInterface extends SitePersistenceStoreInterface {

	/**
	 * Return resource records whose ownership was explicitly recorded earlier.
	 *
	 * @return array<int, SitePersistenceRecord>
	 */
	public function owned_resource_records(): array;

	/**
	 * Delete one resource only when the supplied ownership record authorizes it.
	 */
	public function delete_owned_resource( SitePersistenceRecord $record ): RegistrationResult;

	/**
	 * Adopt explicitly listed legacy resources into the recorded ownership ledger.
	 */
	public function migrate_legacy_ownership( CompiledSitePlan $plan ): RegistrationResult;
}
