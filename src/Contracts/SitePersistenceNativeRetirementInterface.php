<?php
/**
 * Retire stale Builder ownership of now-native runtime records.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;

/**
 * Lets the persistence engine retire builder-owned records for identities the
 * current plan declares externally owned native dependencies, so a project can
 * migrate from a managed preset to a native one without manual SQL.
 */
interface SitePersistenceNativeRetirementInterface {

	/**
	 * Remove the stale builder ownership record for one native identity.
	 *
	 * The matching native option entry is removed only while it is still
	 * byte-for-byte what the builder wrote; any foreign modification fails
	 * closed and keeps the conflict for manual review.
	 */
	public function retire_owned_native_record( string $identity ): RegistrationResult;
}
