<?php
/**
 * Adoption of unowned native global stylesheets the Builder itself authored.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Lets the persistence engine adopt unowned native global stylesheets when
 * the store can prove the Builder itself authored them (for example entries
 * written by an earlier Builder version that predates recorded asset
 * ownership).
 *
 * Unlike single-record adoption, stylesheet adoption needs every stylesheet
 * record of the compiled plan: one native stylesheet aggregates fragments
 * from several plan records, so authorship can only be proven against the
 * complete planned aggregate.
 */
interface SitePersistenceStylesheetAdoptionInterface {

	/**
	 * Adopt matching unowned native global stylesheets for the compiled plan.
	 *
	 * Adoption is fail-closed: a native stylesheet is claimed only when the
	 * entry already matches the aggregate the plan would write, so claiming
	 * it changes no rendered output. Stylesheets that do not match stay
	 * untouched and keep their per-record conflict behavior.
	 *
	 * @param SitePersistenceRecord ...$records Every stylesheet asset record of one compiled plan, in plan order.
	 * @return int The number of records whose ownership was adopted.
	 */
	public function adopt_unowned_stylesheet_records( SitePersistenceRecord ...$records ): int;
}
