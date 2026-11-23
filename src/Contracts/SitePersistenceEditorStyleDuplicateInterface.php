<?php
/**
 * Reconciliation of styles the Etch editor duplicated from code-owned styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use HonestlyDesign\EtchBuilders\SitePersistenceReport;

/**
 * Lets the persistence engine heal styles the Etch editor created in its
 * `default` collection for a selector a code-owned style already uses.
 *
 * Code-owned styles live in `default` too since 2.1.1, so the editor links
 * them instead of creating copies; copies made while they lived in their own
 * collections remain. A copy is a `default` style the resource ledger does
 * not record as Builder-owned.
 *
 * An empty duplicate, or one whose CSS already equals the code-owned CSS,
 * is adopted as a mirror: it keeps its ID and collection (so the editor
 * keeps reusing it and every element linked to it renders correctly) and
 * follows the code-owned CSS from then on. A duplicate whose CSS a person
 * changed is never written; it is reported as a warning notice instead.
 */
interface SitePersistenceEditorStyleDuplicateInterface {

	/**
	 * Adopt empty or identical editor duplicates of the given owned styles.
	 *
	 * @param SitePersistenceRecord ...$records Style records the current apply persisted successfully.
	 * @return SitePersistenceReport Applied results for duplicates written in this call, plus notices.
	 */
	public function adopt_editor_style_duplicates( SitePersistenceRecord ...$records ): SitePersistenceReport;

	/**
	 * Describe editor duplicates of the given styles without writing anything.
	 *
	 * @param SitePersistenceRecord ...$records Compiled style records.
	 * @return SitePersistenceReport A report with notices only.
	 */
	public function inspect_editor_style_duplicates( SitePersistenceRecord ...$records ): SitePersistenceReport;
}
