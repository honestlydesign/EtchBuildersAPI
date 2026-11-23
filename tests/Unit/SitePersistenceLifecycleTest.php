<?php
/**
 * Site persistence lifecycle tests: staged applies, hand-over, orphans,
 * adoption, translations, and editor-created style duplicates.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

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

	public function test_retiring_orphans_drafts_a_removed_page_and_adding_it_back_restores_it(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->site_plan() );

		self::assertTrue( $persistence->retire_orphans( $this->component_only_plan()->as_partial() )->was_blocked(), 'A partial plan cannot prove that content was removed.' );
		self::assertSame( 'publish', $this->post( 'intro' )->post_status );

		$retired = $persistence->retire_orphans( $this->component_only_plan() );
		self::assertSame( array( 'page:slug:intro' => 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED' ), $this->result_codes( $retired ) );
		self::assertSame( 'draft', $this->post( 'intro' )->post_status );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED' ), $this->notice_codes( $persistence->apply( $this->component_only_plan() ), 'page:slug:intro' ) );

		$back = $persistence->apply( $this->site_plan() );
		self::assertTrue( $back->is_success() );
		self::assertSame( 'ETCH_SITE_PERSISTENCE_RESTORED', $this->result_codes( $back )['page:slug:intro'] );
		self::assertSame( 'publish', $this->post( 'intro' )->post_status );
		self::assertSame( array(), $persistence->apply( $this->site_plan() )->applied_results() );
	}

	public function test_retiring_orphans_keeps_the_front_page_and_components(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->site_plan() );
		$GLOBALS['etch_builders_site_persistence_options']['page_on_front'] = $this->post( 'intro' )->ID;

		$report = $persistence->retire_orphans( CompiledSitePlan::from_sections() );

		self::assertSame( array(), $report->results() );
		self::assertSame( 'publish', $this->post( 'intro' )->post_status );
		self::assertSame( 'publish', $this->post( 'omide-component-hero' )->post_status );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN' ), $this->notice_codes( $report, 'page:slug:intro' ) );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN' ), $this->notice_codes( $report, 'component:Hero' ) );
	}

	public function test_handed_over_page_is_created_once_then_editor_and_code_changes_are_left_alone_until_it_is_managed_again(): void {
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

		$retired = $persistence->retire_orphans( CompiledSitePlan::from_sections() );
		self::assertSame( array(), $retired->results(), 'Handed-over content belongs to editors and is never retired.' );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN_KEPT' ), $this->notice_codes( $retired, 'page:slug:welcome' ) );

		$managed = $persistence->apply( $this->welcome_plan( 'code v2', false ) );
		self::assertSame( array( 'page:slug:welcome' => 'ETCH_SITE_PERSISTENCE_UPDATED' ), $this->result_codes( $managed ) );
		self::assertStringContainsString( 'code v2', $this->post( 'welcome' )->post_content );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_ORPHAN' ), $this->notice_codes( $persistence->orphans( CompiledSitePlan::from_sections() ), 'page:slug:welcome' ), 'Managed again, the page is a normal orphan candidate.' );
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
		unset( $GLOBALS['etch_builders_site_persistence_fail_meta'] );
		self::assertSame( array( 'post:book:saddle' => 'ETCH_SITE_PERSISTENCE_CONFLICT' ), $this->result_codes( $persistence->apply( $this->book_plan() ) ) );
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

	private function welcome_plan( string $text, bool $hand_over ): CompiledSitePlan {
		return SiteDefinition::new()
			->page( Page::new()->slug( 'welcome' )->title( 'Welcome' )->block( TextBlock::new()->content( $text ) )->hand_over_after_create( $hand_over ) )
			->compile();
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
