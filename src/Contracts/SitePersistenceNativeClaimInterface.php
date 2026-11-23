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
 * expected post type, and it is not a translation of another record. When
 * the follow-up write will replace the content, the store must also make
 * sure the replaced content stays recoverable (the WordPress store requires
 * revisions, unless the content already matches).
 */
interface SitePersistenceNativeClaimInterface {

	/**
	 * Mark the single matching unowned native record as Builder-owned.
	 *
	 * @param bool $replaces_content Whether the apply will overwrite the record's content next.
	 */
	public function claim_unowned_native_record( SitePersistenceRecord $record, bool $replaces_content = true ): RegistrationResult;

	/**
	 * Undo a claim whose follow-up write failed: restore the content the
	 * claim found and make the record unowned again.
	 */
	public function release_native_claim( SitePersistenceRecord $record ): RegistrationResult;
}
