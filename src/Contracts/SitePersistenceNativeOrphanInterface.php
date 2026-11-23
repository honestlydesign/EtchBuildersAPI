<?php
/**
 * Detection and reversible retirement of Builder-owned content removed from code.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;

/**
 * Lists Builder-owned native post records and retires the ones a complete
 * plan no longer contains.
 *
 * Retirement must be the most reversible native action the store has (the
 * WordPress store moves pages and posts to draft); it never deletes content.
 */
interface SitePersistenceNativeOrphanInterface {

	/**
	 * Builder-owned native post records, excluding translations and trashed records.
	 *
	 * @return array<int, array{identity: string, kind: string, status: string, handed_over: bool, retired: bool, front_page: bool}>
	 */
	public function owned_native_entities(): array;

	/**
	 * Retire one owned native record and remember how to restore it.
	 */
	public function retire_owned_native_entity( string $identity ): RegistrationResult;

	/**
	 * Restore a record retired earlier, now that the plan contains it again.
	 *
	 * @return RegistrationResult|null Null when the record was not retired.
	 */
	public function restore_retired_native_entity( string $identity ): ?RegistrationResult;
}
