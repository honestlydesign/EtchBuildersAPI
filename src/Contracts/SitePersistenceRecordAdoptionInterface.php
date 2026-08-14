<?php
/**
 * Adoption of unowned native records the Builder itself authored.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Lets the persistence engine adopt an unowned native record when the store
 * can prove the Builder itself authored it (for example style-handoff
 * entries that predate recorded ownership) and the compiled payload still
 * matches exactly.
 */
interface SitePersistenceRecordAdoptionInterface {

	/**
	 * Whether one unowned native record may be adopted by the compiled plan.
	 */
	public function adopt_unowned_record( SitePersistenceRecord $record ): bool;
}
