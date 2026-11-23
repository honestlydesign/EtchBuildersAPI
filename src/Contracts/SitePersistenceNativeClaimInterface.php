<?php
/**
 * Explicit adoption of existing WordPress content into Builder ownership.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Lets the persistence engine claim an existing native page, post, or
 * template the Builder did not create, when the compiled entity explicitly
 * asks for adoption.
 *
 * A claim writes only the ownership metadata. It succeeds only when exactly
 * one matching native record exists, it is not owned yet, it has the
 * expected post type, and it is not a translation of another record.
 */
interface SitePersistenceNativeClaimInterface {

	/**
	 * Mark the single matching unowned native record as Builder-owned.
	 */
	public function claim_unowned_native_record( SitePersistenceRecord $record ): RegistrationResult;

	/**
	 * Undo a claim whose follow-up write failed, so the record is unowned again.
	 */
	public function release_native_claim( SitePersistenceRecord $record ): RegistrationResult;
}
