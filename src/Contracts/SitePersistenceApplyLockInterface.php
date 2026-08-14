<?php
/**
 * Site-wide apply serialization contract for persistence stores.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Serializes whole-plan applies so concurrent read-modify-write cycles cannot
 * lose updates against the same WordPress option state.
 */
interface SitePersistenceApplyLockInterface {

	/**
	 * Acquire the site-wide apply lock or fail closed.
	 *
	 * Implementations must recover locks abandoned by crashed applies instead
	 * of failing permanently.
	 */
	public function acquire_site_apply_lock(): bool;

	/**
	 * Release a lock acquired by this process.
	 */
	public function release_site_apply_lock(): bool;
}
