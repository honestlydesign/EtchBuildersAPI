<?php
/**
 * Detection and reversible retirement of Builder-owned content removed from code.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Lists Builder-owned native post records and retires the ones a complete
 * plan no longer contains.
 *
 * Retirement must be the most reversible native action the store has (the
 * WordPress store moves pages and posts to draft); it never deletes content.
 */
interface SitePersistenceNativeOrphanInterface {

	/**
	 * Builder-owned (including handed-over) native post records, excluding
	 * translations and trashed records.
	 *
	 * `protected` marks records WordPress or a plugin depends on (front page,
	 * posts page, privacy policy page, shop pages, filtered IDs).
	 *
	 * @return array<int, array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool}>
	 */
	public function owned_native_entities(): array;

	/**
	 * Retire one owned native record and remember how to restore it.
	 *
	 * Fails with `ETCH_SITE_PERSISTENCE_ORPHAN_PROTECTED` for protected
	 * records and records with live children.
	 */
	public function retire_owned_native_entity( string $identity ): RegistrationResult;

	/**
	 * Restore a record retired earlier, now that the plan contains it again.
	 *
	 * The previous status comes back only when the record's payload does not
	 * set a status itself.
	 *
	 * @return RegistrationResult|null Null when the record was not retired.
	 */
	public function restore_retired_native_entity( SitePersistenceRecord $record ): ?RegistrationResult;

	/**
	 * Remember the digest of the complete plan the last fully successful apply wrote.
	 */
	public function record_complete_plan_digest( string $digest ): RegistrationResult;

	/**
	 * The digest recorded by record_complete_plan_digest(), or null.
	 */
	public function complete_plan_digest(): ?string;

	/**
	 * Drop the recorded digest before another plan starts writing, so a failed
	 * or partial apply never leaves an older plan looking current.
	 */
	public function forget_complete_plan_digest(): RegistrationResult;
}
