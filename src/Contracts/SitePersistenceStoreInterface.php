<?php
/**
 * Record store used by compiled Site persistence adapters.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Narrow storage boundary for one already-normalized persistence record.
 */
interface SitePersistenceStoreInterface {

	/**
	 * Find a record by its stable compiled identity.
	 */
	public function find( string $identity ): ?SitePersistenceRecord;

	/**
	 * Create a record when the identity does not exist.
	 */
	public function create( SitePersistenceRecord $record ): RegistrationResult;

	/**
	 * Update a record already owned by this builder.
	 */
	public function update( SitePersistenceRecord $record ): RegistrationResult;
}
