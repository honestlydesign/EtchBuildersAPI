<?php
/**
 * Site persistence lifecycle tests: staged applies, hand-over, orphans,
 * adoption, translations, and editor-created style duplicates.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\CompiledSiteDependency;
use HonestlyDesign\EtchBuilders\CompiledSiteDiagnostic;
use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityPersistenceIntent;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\Page;
use HonestlyDesign\EtchBuilders\SiteDefinition;
use HonestlyDesign\EtchBuilders\SitePersistenceReport;
use HonestlyDesign\EtchBuilders\SitePersistenceResult;
use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistence;
use HonestlyDesign\EtchBuilders\Tests\Unit\Support\WordPressPersistenceStubs;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the WordPress persistence adapter across several applies.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SitePersistenceLifecycleTest extends TestCase {

	protected function setUp(): void {
		WordPressPersistenceStubs::install();
		$GLOBALS['etch_builders_site_persistence_options'] = array();
		$GLOBALS['etch_builders_site_persistence_posts']   = array();
		$GLOBALS['etch_builders_site_persistence_meta']    = array();
		$GLOBALS['etch_builders_test_filters']             = array();
		$GLOBALS['etch_builders_site_persistence_revisions'] = array();
		unset( $GLOBALS['etch_builders_site_persistence_no_revisions'], $GLOBALS['etch_builders_site_persistence_fail_meta'] );
	}

	public function test_partial_bootstrap_apply_keeps_other_entities_styles_and_full_reapply_is_unchanged(): void {
		$persistence = new WordPressSitePersistence();
		$full        = $this->site_plan();

		$first = $persistence->apply( $full );
		self::assertTrue( $first->is_success() );

		$bootstrap = $persistence->apply( $this->component_only_plan()->as_partial() );
		self::assertTrue( $bootstrap->is_success() );
		self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $bootstrap ) );
		self::assertArrayHasKey( 'page-intro', $this->option( 'etch_styles' ), 'A partial stage must not delete styles owned by entities it does not contain.' );
		self::assertArrayHasKey( 'global', $this->option( 'etch_global_stylesheets' ) );

		$again = $persistence->apply( $full );
		self::assertTrue( $again->is_success() );
		self::assertSame( array(), $again->applied_results(), 'An unchanged site must not report created or updated records.' );
		self::assertCount( count( $again->results() ), $again->unchanged_results() );
	}

	public function test_a_page_removed_from_code_stays_live_with_its_styles_is_reported_and_its_styles_go_once_it_is_trashed(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->site_plan() );

		$removed = $persistence->apply( $this->component_only_plan() );

		self::assertTrue( $removed->is_success(), 'A reported orphan must not turn the apply into a failure.' );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN' ), $this->notice_codes( $removed, 'page:slug:intro' ) );
		self::assertSame( 'publish', $this->post( 'intro' )->post_status, 'Orphans are reported, never retired, by a normal apply.' );
		self::assertArrayHasKey( 'page-intro', $this->option( 'etch_styles' ), 'A page that is still live keeps its styles.' );

		$this->post( 'intro' )->post_status = 'trash';
		$persistence->apply( $this->component_only_plan() );

		self::assertArrayNotHasKey( 'page-intro', $this->option( 'etch_styles' ) );
		self::assertArrayHasKey( 'hero', $this->option( 'etch_styles' ) );
	}

	public function test_complete_plan_still_cleans_styles_code_removed_from_an_entity_it_keeps(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->site_plan() );

		$plan = $this->site_plan();
		$persistence->apply(
			CompiledSitePlan::from_sections(
				entities: $plan->entities(),
				styles: array( $this->style( 'hero', '.hero', 'color: red;' ) ),
				assets: $plan->assets(),
				ownership: array_values( array_filter( $plan->ownership(), static fn ( CompiledSiteOwnership $edge ): bool => 'style:page-intro' !== $edge->resource_identity() ) )
			)
		);

		self::assertArrayNotHasKey( 'page-intro', $this->option( 'etch_styles' ) );
	}

	public function test_retiring_an_orphan_drafts_only_the_selected_page_and_adding_it_back_restores_it(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->pages_plan( 'home', 'intro', 'contact' ) );
		$without = $this->pages_plan( 'home' );
		$persistence->apply( $without );

		$retired = $persistence->retire_orphans( $without, array( 'page:slug:intro' ) );

		self::assertSame( array( 'page:slug:intro' => 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED' ), $this->result_codes( $retired ) );
		self::assertSame( 'draft', $this->post( 'intro' )->post_status );
		self::assertSame( 'publish', $this->post( 'contact' )->post_status, 'Only identities passed explicitly are retired.' );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED' ), $this->notice_codes( $persistence->apply( $without ), 'page:slug:intro' ) );

		$back = $persistence->apply( $this->pages_plan( 'home', 'intro', 'contact' ) );
		self::assertTrue( $back->is_success() );
		self::assertSame( 'ETCH_SITE_PERSISTENCE_RESTORED', $this->result_codes( $back )['page:slug:intro'] );
		self::assertSame( 'publish', $this->post( 'intro' )->post_status );
		self::assertSame( array(), $persistence->apply( $this->pages_plan( 'home', 'intro', 'contact' ) )->applied_results() );
	}

	public function test_retiring_orphans_refuses_every_plan_that_does_not_prove_the_content_was_removed(): void {
		$persistence = new WordPressSitePersistence();
		$full        = $this->pages_plan( 'a', 'b', 'c' );
		$persistence->apply( $full );
		$stale = $this->pages_plan( 'a', 'b' );
		$persistence->apply( $stale );
		$persistence->apply( $full );

		$refusals = array(
			'an empty plan'                      => array( CompiledSitePlan::from_sections(), array( 'page:slug:a' ) ),
			'a stage plan that was not applied'  => array( $this->pages_plan( 'a' ), array( 'page:slug:b', 'page:slug:c' ) ),
			'a partial plan'                     => array( $this->pages_plan( 'a' )->as_partial(), array( 'page:slug:b' ) ),
			'a stale plan applied earlier'       => array( $stale, array( 'page:slug:c' ) ),
			'an identity change not applied yet' => array( CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'a', id: 1 ), $this->page_entity( 'b' ), $this->page_entity( 'c' ) ) ), array( 'page:slug:a' ) ),
			'no selected identities'             => array( $full, array() ),
			'a plan with a broken dependency'    => array( CompiledSitePlan::from_sections( entities: $full->entities(), dependencies: array( CompiledSiteDependency::new( 'page:slug:a', 'page:slug:missing', 'pattern' ) ) ), array( 'page:slug:a' ) ),
		);
		foreach ( $refusals as $case => $arguments ) {
			self::assertTrue( $persistence->retire_orphans( ...$arguments )->was_blocked(), sprintf( 'Retirement must refuse %s.', $case ) );
		}

		$not_orphan = $persistence->retire_orphans( $full, array( 'page:slug:a' ) );
		self::assertSame( array( 'page:slug:a' => 'ETCH_SITE_PERSISTENCE_ORPHAN_NOT_FOUND' ), $this->result_codes( $not_orphan ) );
		foreach ( array( 'a', 'b', 'c' ) as $slug ) {
			self::assertSame( 'publish', $this->post( $slug )->post_status );
		}
	}

	public function test_reapplying_the_same_site_succeeds_and_only_a_successful_full_apply_enables_retirement(): void {
		$persistence = new WordPressSitePersistence();
		$home        = $this->pages_plan( 'home' );
		$persistence->apply( $this->pages_plan( 'home', 'a' ) );
		self::assertTrue( $persistence->apply( $home )->is_success() );

		$again = $persistence->apply( $home );
		self::assertTrue( $again->is_success(), 'Re-applying an unchanged site must not fail on values WordPress already holds.' );
		self::assertSame( array(), $again->applied_results() );

		$GLOBALS['etch_builders_site_persistence_fail_meta'] = 'etch_builders_site_persistence_record';
		self::assertFalse( $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'home', 'changed in code' ), $this->page_entity( 'a' ) ) ) )->is_success() );
		unset( $GLOBALS['etch_builders_site_persistence_fail_meta'] );
		self::assertTrue( $persistence->retire_orphans( $home, array( 'page:slug:a' ) )->was_blocked(), 'A failed apply of another plan leaves the site in no proven state.' );

		$persistence->apply( $home );
		$persistence->apply( $this->pages_plan( 'a' )->as_partial() );
		self::assertTrue( $persistence->retire_orphans( $home, array( 'page:slug:a' ) )->was_blocked(), 'A partial stage applied after the full plan changes what the site holds.' );
		self::assertSame( 'publish', $this->post( 'a' )->post_status );

		$persistence->apply( $home );
		self::assertSame( array( 'page:slug:a' => 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED' ), $this->result_codes( $persistence->retire_orphans( $home, array( 'page:slug:a' ) ) ) );
	}

	public function test_an_id_targeted_page_is_not_an_orphan_of_its_old_slug_identity(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->pages_plan( 'a', 'b' ) );
		$by_id = CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'a', id: 1 ), $this->page_entity( 'b' ) ) );

		self::assertSame( array(), $this->notice_codes( $persistence->orphans( $by_id ), 'page:slug:a' ) );
		$persistence->apply( $by_id );
		self::assertSame( array( 'page:slug:a' => 'ETCH_SITE_PERSISTENCE_ORPHAN_NOT_FOUND' ), $this->result_codes( $persistence->retire_orphans( $by_id, array( 'page:slug:a' ) ) ) );
		self::assertSame( 'publish', $this->post( 'a' )->post_status );
	}

	public function test_retiring_orphans_keeps_special_pages_parents_of_live_pages_and_components(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->component_entity(), ...$this->pages_plan( 'home', 'front', 'privacy', 'shop', 'listed', 'parent' )->entities() ) ) );
		$GLOBALS['etch_builders_site_persistence_options']['page_on_front']              = $this->post( 'front' )->ID;
		$GLOBALS['etch_builders_site_persistence_options']['wp_page_for_privacy_policy'] = $this->post( 'privacy' )->ID;
		$GLOBALS['etch_builders_site_persistence_options']['woocommerce_shop_page_id']   = $this->post( 'shop' )->ID;
		$listed = $this->post( 'listed' )->ID;
		$GLOBALS['etch_builders_test_filters']['etch_builders_site_persistence_protected_post_ids'] = static fn ( array $ids ): array => array_merge( $ids, array( $listed ) );
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'child', 'post_status' => 'publish', 'post_parent' => $this->post( 'parent' )->ID ) );
		$home = $this->pages_plan( 'home' );
		$persistence->apply( $home );

		$report = $persistence->retire_orphans( $home, array( 'page:slug:front', 'page:slug:privacy', 'page:slug:shop', 'page:slug:listed', 'page:slug:parent', 'component:Hero' ) );

		self::assertSame( array_fill_keys( array( 'page:slug:front', 'page:slug:privacy', 'page:slug:shop', 'page:slug:listed', 'page:slug:parent', 'component:Hero' ), 'ETCH_SITE_PERSISTENCE_ORPHAN_KEPT' ), $this->result_codes( $report ) );
		foreach ( array( 'front', 'privacy', 'shop', 'listed', 'parent', 'omide-component-hero' ) as $slug ) {
			self::assertSame( 'publish', $this->post( $slug )->post_status );
		}
	}

	public function test_handed_over_page_is_created_once_then_editor_and_code_changes_are_left_alone(): void {
		$persistence = new WordPressSitePersistence();

		$created = $persistence->apply( $this->welcome_plan( 'code v1', true ) );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_CREATED' ), $this->result_codes( $created ) );
		self::assertStringContainsString( 'code v1', $this->post( 'welcome' )->post_content );

		\wp_update_post( array( 'ID' => $this->post( 'welcome' )->ID, 'post_content' => 'edited in wp-admin' ) );

		$after_edit = $persistence->apply( $this->welcome_plan( 'code v1', true ) );
		$after_code = $persistence->apply( $this->welcome_plan( 'code v2', true ) );
		foreach ( array( $after_edit, $after_code ) as $report ) {
			self::assertTrue( $report->is_success() );
			self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_HANDED_OVER' ), $this->result_codes( $report ) );
		}
		self::assertSame( 'edited in wp-admin', $this->post( 'welcome' )->post_content );
		self::assertFalse( ( new \HonestlyDesign\EtchBuilders\Support\WordPressSitePersistenceStore() )->find( 'page:slug:welcome' )?->is_owned(), 'Plain Builder ownership (what 2.0.x checks) must not cover handed-over content.' );

		$home = CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'home' ) ) );
		$persistence->apply( $home );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN_KEPT' ), $this->notice_codes( $persistence->orphans( $home ), 'page:slug:welcome' ) );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_ORPHAN_KEPT' ), $this->result_codes( $persistence->retire_orphans( $home, array( 'page:slug:welcome' ) ) ), 'Handed-over content belongs to editors and is never retired.' );
	}

	public function test_handed_over_page_stays_with_editors_when_code_manages_it_again_until_it_is_reclaimed(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->welcome_plan( 'code v1', true ) );
		\wp_update_post( array( 'ID' => $this->post( 'welcome' )->ID, 'post_content' => 'editor work' ) );

		$managed = $persistence->apply( $this->welcome_plan( 'code v2', false ) );
		self::assertFalse( $managed->is_success() );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_HANDED_OVER_RECLAIM_REQUIRED' ), $this->result_codes( $managed ) );
		self::assertSame( 'editor work', $this->post( 'welcome' )->post_content );

		$reclaimed = $persistence->apply( $this->welcome_plan( 'code v2', false, reclaim: true ) );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_RECLAIMED' ), $this->result_codes( $reclaimed ) );
		self::assertStringContainsString( 'code v2', $this->post( 'welcome' )->post_content );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_UNCHANGED' ), $this->result_codes( $persistence->apply( $this->welcome_plan( 'code v2', false ) ) ) );
	}

	public function test_handed_over_page_an_editor_renamed_trashed_or_deleted_is_not_created_again(): void {
		foreach ( array( 'renamed', 'trashed', 'deleted' ) as $change ) {
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']   = array();
			$GLOBALS['etch_builders_site_persistence_meta']    = array();
			$persistence = new WordPressSitePersistence();
			$persistence->apply( $this->welcome_plan( 'code v1', true ) );
			$post = $this->post( 'welcome' );
			match ( $change ) {
				'renamed' => $post->post_name = 'hello',
				'trashed' => array( $post->post_status = 'trash', $post->post_name = 'welcome__trashed' ),
				'deleted' => \wp_delete_post( $post->ID, true ),
			};

			$again = $persistence->apply( $this->welcome_plan( 'code v1', true ) );

			self::assertTrue( $again->is_success(), $change );
			self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_HANDED_OVER_MISSING' ), $this->result_codes( $again ), $change );
			self::assertCount( 'deleted' === $change ? 0 : 1, $GLOBALS['etch_builders_site_persistence_posts'], $change );
		}

		$recreated = $persistence->apply( $this->welcome_plan( 'code v1', true, reclaim: true ) );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_CREATED' ), $this->result_codes( $recreated ) );
	}

	public function test_a_retired_page_added_back_as_handed_over_content_is_published_again(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->pages_plan( 'home', 'a' ) );
		$persistence->apply( $this->pages_plan( 'home' ) );
		$persistence->retire_orphans( $this->pages_plan( 'home' ), array( 'page:slug:a' ) );
		self::assertSame( 'draft', $this->post( 'a' )->post_status );

		$back = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'home' ), $this->page_entity( 'a', intent: CompiledSiteEntityPersistenceIntent::CREATE_ONLY ) ) ) );

		self::assertSame( 'ETCH_SITE_PERSISTENCE_RESTORED', $this->result_codes( $back )['page:slug:a'] );
		self::assertSame( 'publish', $this->post( 'a' )->post_status );
	}

	public function test_a_retired_page_stays_retired_when_writing_its_content_back_fails(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->pages_plan( 'home', 'a' ) );
		$persistence->apply( $this->pages_plan( 'home' ) );
		$persistence->retire_orphans( $this->pages_plan( 'home' ), array( 'page:slug:a' ) );

		$GLOBALS['etch_builders_site_persistence_fail_meta'] = 'etch_builders_site_persistence_record';
		$failed = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'home' ), $this->page_entity( 'a', 'changed in code' ) ) ) );

		self::assertFalse( $failed->is_success() );
		self::assertSame( 'draft', $this->post( 'a' )->post_status );
	}

	public function test_an_existing_wp_admin_page_conflicts_unless_the_code_page_adopts_it(): void {
		$persistence = new WordPressSitePersistence();
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'written by hand' ) );

		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_CONFLICT' ), $this->result_codes( $persistence->apply( $this->about_plan() ) ) );
		self::assertSame( 'written by hand', $this->post( 'about' )->post_content );

		$adopted = $persistence->apply( $this->about_plan( adopt: true ) );
		self::assertTrue( $adopted->is_success() );
		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_ADOPTED' ), $this->result_codes( $adopted ) );
		self::assertSame( 'code about', $this->post( 'about' )->post_content );
		self::assertSame( array( 'written by hand' ), array_column( $GLOBALS['etch_builders_site_persistence_revisions'][ $this->post( 'about' )->ID ] ?? array(), 'post_content' ), 'The content adoption replaced is kept as a revision.' );
		self::assertSame( 1, count( $GLOBALS['etch_builders_site_persistence_posts'] ), 'Adoption reuses the existing post.' );
		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_UNCHANGED' ), $this->result_codes( $persistence->apply( $this->about_plan( adopt: true ) ) ) );
	}

	public function test_adopting_a_page_that_is_also_handed_over_keeps_its_content(): void {
		$persistence = new WordPressSitePersistence();
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'written by hand' ) );

		$adopted = $persistence->apply( $this->about_plan( adopt: true, intent: CompiledSiteEntityPersistenceIntent::CREATE_ONLY ) );

		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_ADOPTED' ), $this->result_codes( $adopted ) );
		self::assertSame( 'written by hand', $this->post( 'about' )->post_content );
		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_HANDED_OVER' ), $this->result_codes( $persistence->apply( $this->about_plan( adopt: true, intent: CompiledSiteEntityPersistenceIntent::CREATE_ONLY ) ) ) );
	}

	public function test_adoption_refuses_ambiguous_matches_and_releases_a_claim_whose_write_failed(): void {
		$persistence = new WordPressSitePersistence();
		$this->insert_post( array( 'post_type' => 'book', 'post_name' => 'saddle', 'post_status' => 'publish', 'post_content' => 'one' ) );
		$this->insert_post( array( 'post_type' => 'book', 'post_name' => 'saddle', 'post_status' => 'draft', 'post_content' => 'two' ) );

		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_ADOPTION_AMBIGUOUS' ), $this->result_codes( $persistence->apply( $this->book_plan( adopt: true ) ) ) );

		unset( $GLOBALS['etch_builders_site_persistence_posts'][2] );
		$GLOBALS['etch_builders_site_persistence_fail_meta'] = 'etch_builders_site_persistence_record';
		$failed = $persistence->apply( $this->book_plan( adopt: true ) );

		self::assertFalse( $failed->is_success() );
		self::assertSame( '', \get_post_meta( 1, 'etch_builders_site_persistence_owner', true ), 'A failed adoption must not leave the post looking Builder-owned.' );
		self::assertSame( 'one', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_content, 'A failed adoption must give the person their content back.' );
		self::assertSame( 'publish', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_status );
		unset( $GLOBALS['etch_builders_site_persistence_fail_meta'] );
		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_CONFLICT' ), $this->result_codes( $persistence->apply( $this->book_plan() ) ) );
	}

	public function test_adoption_that_would_replace_content_without_revisions_is_refused_unless_the_content_matches(): void {
		$persistence = new WordPressSitePersistence();
		$GLOBALS['etch_builders_site_persistence_no_revisions'] = array( 'book' );
		$this->insert_post( array( 'post_type' => 'book', 'post_name' => 'saddle', 'post_status' => 'publish', 'post_content' => 'written by hand' ) );

		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_ADOPTION_NO_REVISIONS' ), $this->result_codes( $persistence->apply( $this->book_plan( adopt: true ) ) ) );
		self::assertSame( 'written by hand', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_content );
		self::assertSame( '', \get_post_meta( 1, 'etch_builders_site_persistence_owner', true ) );

		$GLOBALS['etch_builders_site_persistence_posts'][1]->post_content = 'code book';
		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_ADOPTED' ), $this->result_codes( $persistence->apply( $this->book_plan( adopt: true ) ) ) );
	}

	public function test_adopting_by_id_refuses_a_wpml_translation(): void {
		$persistence = new WordPressSitePersistence();
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'EN' ) );
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'NL' ) );
		$GLOBALS['etch_builders_test_filters']['wpml_element_language_details'] = static fn ( mixed $value, array $args ): array => array( 'source_language_code' => 2 === $args['element_id'] ? 'en' : null );

		$report = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->page_entity( 'about', 'code', adopt: true, id: 2 ) ) ) );

		self::assertSame( array( 'page:id:2' => 'ETCH_SITE_PERSISTENCE_ADOPTION_TRANSLATION' ), $this->result_codes( $report ) );
		self::assertSame( 'NL', $GLOBALS['etch_builders_site_persistence_posts'][2]->post_content );
	}

	public function test_wpml_translations_with_copied_ownership_meta_do_not_block_or_get_written(): void {
		$persistence = new WordPressSitePersistence();
		$plan        = CompiledSitePlan::from_sections( entities: array( $this->component_entity(), ...$this->book_plan()->entities() ) );
		$persistence->apply( $plan );

		$book_translation      = $this->translate( 2, array( 'post_content' => 'NL boek' ) );
		$component_duplicate   = $this->translate( 1, array( 'post_content' => 'NL hero' ), duplicate: true );
		$GLOBALS['etch_builders_test_filters']['wpml_element_language_details'] = static fn ( mixed $value, array $args ): object => (object) array(
			'source_language_code' => $book_translation === $args['element_id'] ? 'en' : null,
		);

		$again = $persistence->apply( $plan );

		self::assertTrue( $again->is_success() );
		self::assertSame( array( 'component:Hero' => 'ETCH_SITE_PERSISTENCE_UNCHANGED', 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_UNCHANGED' ), $this->result_codes( $again ) );
		self::assertSame( array(), $again->notices(), 'Translations are not reported as orphans.' );
		self::assertSame( 'NL boek', $GLOBALS['etch_builders_site_persistence_posts'][ $book_translation ]->post_content );
		self::assertSame( 'NL hero', $GLOBALS['etch_builders_site_persistence_posts'][ $component_duplicate ]->post_content );
	}

	public function test_a_slug_lookup_that_finds_only_a_translation_uses_its_original_or_fails_closed(): void {
		$persistence = new WordPressSitePersistence();
		$this->insert_post( array( 'post_type' => 'page', 'post_name' => 'about', 'post_status' => 'publish', 'post_content' => 'NL over ons' ) );
		$persistence->apply( $this->about_plan( adopt: true ) );
		$original = 1;
		// Make the slug lookup see the translation first, as a language-filtered lookup can.
		$GLOBALS['etch_builders_site_persistence_posts'] = array( 2 => clone $GLOBALS['etch_builders_site_persistence_posts'][1] ) + $GLOBALS['etch_builders_site_persistence_posts'];
		$GLOBALS['etch_builders_site_persistence_posts'][2]->ID           = 2;
		$GLOBALS['etch_builders_site_persistence_posts'][2]->post_content = 'NL over ons';
		$GLOBALS['etch_builders_site_persistence_meta'][2]                = $GLOBALS['etch_builders_site_persistence_meta'][ $original ];
		$GLOBALS['etch_builders_test_filters']['wpml_element_language_details'] = static fn ( mixed $value, array $args ): array => array( 'source_language_code' => 2 === $args['element_id'] ? 'en' : null );
		$GLOBALS['etch_builders_test_filters']['wpml_object_id']                = static fn ( mixed $id ): ?int => 2 === $id ? $original : null;

		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_UNCHANGED' ), $this->result_codes( $persistence->apply( $this->about_plan() ) ) );

		$GLOBALS['etch_builders_test_filters']['wpml_object_id'] = static fn (): ?int => null;
		$unresolved = $persistence->apply( $this->about_plan() );

		self::assertSame( array( 'page:slug:about' => 'ETCH_SITE_PERSISTENCE_CONFLICT' ), $this->result_codes( $unresolved ) );
		self::assertSame( 'NL over ons', $GLOBALS['etch_builders_site_persistence_posts'][2]->post_content, 'A translation is never written.' );
	}

	public function test_other_multilingual_plugins_can_mark_translations_through_the_package_filter(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->book_plan() );
		$translation = $this->translate( 1, array( 'post_content' => 'DE Buch' ) );
		$GLOBALS['etch_builders_test_filters']['etch_builders_site_persistence_is_translation'] = static fn ( bool $is, object $post ): bool => $is || $translation === ( get_object_vars( $post )['ID'] ?? 0 );

		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_UNCHANGED' ), $this->result_codes( $persistence->apply( $this->book_plan() ) ) );
	}

	private function site_plan(): CompiledSitePlan {
		$component = $this->component_entity();
		$page      = CompiledSiteEntity::new(
			CompiledSiteEntityType::PAGE,
			'page:slug:intro',
			array( 'slug' => 'intro', 'blocks' => '<!-- wp:etch/text -->intro<!-- /wp:etch/text -->', 'post_title' => 'Intro' )
		);
		$hero_style = $this->style( 'hero', '.hero', 'color: red;' );
		$page_style = $this->style( 'page-intro', '.page-intro', 'margin: 0;' );
		$stylesheet = CompiledSiteResource::new(
			CompiledSiteResourceType::ASSET,
			'asset:stylesheet:site:root:global:hash',
			array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { margin: 0; }' )
		);

		return CompiledSitePlan::from_sections(
			entities: array( $component, $page ),
			styles: array( $hero_style, $page_style ),
			assets: array( $stylesheet ),
			ownership: array(
				CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'style' ),
				CompiledSiteOwnership::new( 'page:slug:intro', 'style:page-intro', 'style' ),
				CompiledSiteOwnership::new( 'site:root', $stylesheet->identity(), 'stylesheet' ),
			)
		);
	}

	private function component_only_plan(): CompiledSitePlan {
		return CompiledSitePlan::from_sections(
			entities: array( $this->component_entity() ),
			styles: array( $this->style( 'hero', '.hero', 'color: red;' ) ),
			ownership: array( CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'style' ) )
		);
	}

	private function welcome_plan( string $text, bool $hand_over, bool $reclaim = false ): CompiledSitePlan {
		return SiteDefinition::new()
			->page( Page::new()->slug( 'welcome' )->title( 'Welcome' )->block( TextBlock::new()->content( $text ) )->hand_over_after_create( $hand_over )->reclaim_from_editors( $reclaim ) )
			->compile();
	}

	private function pages_plan( string ...$slugs ): CompiledSitePlan {
		return CompiledSitePlan::from_sections( entities: array_values( array_map( fn ( string $slug ): CompiledSiteEntity => $this->page_entity( $slug ), $slugs ) ) );
	}

	private function page_entity(
		string $slug,
		string $blocks = 'code page',
		CompiledSiteEntityPersistenceIntent $intent = CompiledSiteEntityPersistenceIntent::MANAGED,
		bool $adopt = false,
		?int $id = null
	): CompiledSiteEntity {
		return null === $id
			? CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:slug:' . $slug, array( 'slug' => $slug, 'blocks' => $blocks ), $intent, $adopt )
			: CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:id:' . $id, array( 'id' => $id, 'blocks' => $blocks ), $intent, $adopt );
	}

	private function about_plan( bool $adopt = false, CompiledSiteEntityPersistenceIntent $intent = CompiledSiteEntityPersistenceIntent::MANAGED ): CompiledSitePlan {
		return CompiledSitePlan::from_sections(
			entities: array( CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:slug:about', array( 'slug' => 'about', 'blocks' => 'code about' ), $intent, $adopt ) )
		);
	}

	private function book_plan( bool $adopt = false ): CompiledSitePlan {
		return CompiledSitePlan::from_sections(
			entities: array( CompiledSiteEntity::new( CompiledSiteEntityType::POST, 'post:book:saddle', array( 'slug' => 'saddle', 'post_type' => 'book', 'blocks' => 'code book' ), CompiledSiteEntityPersistenceIntent::MANAGED, $adopt ) )
		);
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private function insert_post( array $fields ): int {
		return \wp_insert_post( $fields );
	}

	/**
	 * Copy a post the way WPML does for a translation: same slug, all meta copied.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function translate( int $original, array $fields, bool $duplicate = false ): int {
		$id = $this->insert_post( array_merge( (array) $GLOBALS['etch_builders_site_persistence_posts'][ $original ], $fields ) );
		$GLOBALS['etch_builders_site_persistence_posts'][ $id ]->ID = $id;
		$GLOBALS['etch_builders_site_persistence_meta'][ $id ]      = $GLOBALS['etch_builders_site_persistence_meta'][ $original ] ?? array();
		if ( $duplicate ) {
			$GLOBALS['etch_builders_site_persistence_meta'][ $id ]['_icl_lang_duplicate_of'] = (string) $original;
		}

		return $id;
	}

	private function post( string $slug ): \stdClass {
		foreach ( $GLOBALS['etch_builders_site_persistence_posts'] as $post ) {
			if ( $post instanceof \stdClass && $slug === $post->post_name ) {
				return $post;
			}
		}

		self::fail( sprintf( 'No post with slug "%s".', $slug ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function result_codes( SitePersistenceReport $report ): array {
		$codes = array();
		foreach ( $report->results() as $result ) {
			$codes[ $result->identity() ] = $result->code();
		}

		return $codes;
	}

	/**
	 * @return array<int, string>
	 */
	private function notice_codes( SitePersistenceReport $report, string $identity ): array {
		return array_values(
			array_map(
				static fn ( CompiledSiteDiagnostic $notice ): string => $notice->code(),
				array_filter( $report->notices(), static fn ( CompiledSiteDiagnostic $notice ): bool => $identity === $notice->identity() )
			)
		);
	}

	private function component_entity(): CompiledSiteEntity {
		return CompiledSiteEntity::new(
			CompiledSiteEntityType::COMPONENT,
			'component:Hero',
			array( 'name' => 'Hero', 'description' => 'Hero', 'blocks' => '<!-- wp:etch/text -->hero<!-- /wp:etch/text -->', 'properties' => array() )
		);
	}

	private function style( string $id, string $selector, string $css, string $collection = 'OhMyIDEtch:entity:component:Hero' ): CompiledSiteResource {
		return CompiledSiteResource::new(
			CompiledSiteResourceType::STYLE,
			'style:' . $id,
			array( 'selector' => $selector, 'collection' => $collection, 'css' => $css, 'type' => 'class', 'overwrite_on_register' => true )
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function option( string $name ): array {
		$value = $GLOBALS['etch_builders_site_persistence_options'][ $name ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<int, string>
	 */
	private function outcomes( SitePersistenceReport $report ): array {
		return array_map(
			static fn ( SitePersistenceResult $result ): string => $result->outcome()->value,
			$report->results()
		);
	}
}
