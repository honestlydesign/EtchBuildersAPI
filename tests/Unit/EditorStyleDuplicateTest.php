<?php
/**
 * Etch editor style duplicates of code-owned styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\CompiledSiteDiagnostic;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\EntityStyleSet;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\SiteDefinition;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use HonestlyDesign\EtchBuilders\SitePersistenceReport;
use HonestlyDesign\EtchBuilders\SitePersistenceResult;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistence;
use HonestlyDesign\EtchBuilders\Tests\Unit\Support\WordPressPersistenceStubs;
use PHPUnit\Framework\TestCase;

/**
 * The Etch editor (1.8.x) resolves classes only in its `default` style
 * collection. Builders 2.1.0 and earlier stored code-owned styles in their
 * own collections, so editing a code-owned element created an empty `default`
 * style with the same selector and linked the element to it. Since 2.1.1
 * code-owned styles live in `default` too; duplicates already created keep
 * working as mirrors.
 */
final class EditorStyleDuplicateTest extends TestCase {

	private const COLLECTION = 'default';

	private const LEGACY_COLLECTION = 'OhMyIDEtch:entity:component:SiteNav';

	/**
	 * @var array<int, string>
	 */
	private array $temporary_files = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file_path ) {
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}
		}

		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider persisted_code_owned_states
	 */
	public function test_entity_styles_keep_their_code_owned_id_beside_an_empty_editor_duplicate( bool $legacy ): void {
		$storage = new NullStorage();
		$storage->set(
			'etch_styles',
			array(
				'component-site-nav' => $legacy ? $this->legacy_payload() : $this->owned_payload(),
				'4ndy1la'            => $this->editor_placeholder(),
			)
		);
		$storage->set( 'etch_builders_site_persistence_resources', $this->ledger( $legacy ? $this->legacy_payload() : $this->owned_payload() ) );
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$styles = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; }' ) );

		self::assertSame( 'component-site-nav', $styles->class_reference( '.site-nav' )->id() );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public function persisted_code_owned_states(): array {
		return array(
			'written by 2.1.1 in default' => array( false ),
			'written by 2.1.0 in its entity collection' => array( true ),
		);
	}

	public function test_a_recorded_style_keeps_its_id_when_only_the_editor_duplicate_is_left(): void {
		$storage = new NullStorage();
		$storage->set( 'etch_styles', array( '4ndy1la' => $this->editor_placeholder() ) );
		$storage->set(
			'etch_builders_site_persistence_resources',
			array(
				'style:component-site-nav' => array(
					'identity' => 'style:component-site-nav',
					'kind'     => 'style',
					'payload'  => $this->owned_payload(),
					'owned'    => true,
				),
			)
		);
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$styles = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; }' ) );

		self::assertSame( 'component-site-nav', $styles->class_reference( '.site-nav' )->id() );
	}

	public function test_two_code_owned_styles_for_one_selector_still_fail_with_guidance(): void {
		$storage = new NullStorage();
		$storage->set(
			'etch_styles',
			array(
				'component-site-nav' => $this->owned_payload(),
				'page-site-nav'      => $this->owned_payload(),
			)
		);
		$storage->set(
			'etch_builders_site_persistence_resources',
			array_merge( $this->ledger( $this->owned_payload() ), $this->ledger( $this->owned_payload(), 'page-site-nav' ) )
		);
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Keep one style for this selector' );

		Style::resolve_id_for_selector( '.site-nav' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_adopts_an_empty_editor_duplicate_restores_dropped_flags_and_then_reports_no_changes(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );

		// The Etch editor relinks a pasted element to a new empty default style
		// and drops the internal flag from every style when it saves.
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $this->editor_placeholder();
		unset( $GLOBALS['etch_builders_site_persistence_options']['etch_styles']['component-site-nav']['overwrite_on_register'] );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['component-site-nav']['readonly'] = false;

		$healed = $persistence->apply( $this->plan() );

		self::assertTrue( $healed->is_success() );
		self::assertSame(
			array(
				'style:component-site-nav' => 'updated',
				'style:4ndy1la'            => 'updated',
			),
			$this->outcomes( $healed )
		);
		$styles = $this->option( 'etch_styles' );
		self::assertTrue( $styles['component-site-nav']['overwrite_on_register'] );
		self::assertSame( 'display: flex', $styles['4ndy1la']['css'], 'Elements the editor linked to the duplicate must render the code-owned CSS.' );
		self::assertSame( 'default', $styles['4ndy1la']['collection'], 'The mirror stays where the Etch editor looks it up.' );

		$again = $persistence->apply( $this->plan() );
		self::assertSame( array(), $again->applied_results() );
		self::assertSame( array(), $again->notices() );

		Environment::configure( $this->wordpress_storage(), new NullMode(), new NullAssetRegistry() );
		self::assertSame( 'component-site-nav', Style::resolve_id_for_selector( '.site-nav' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_duplicate_a_person_filled_with_other_css_is_reported_and_never_overwritten(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$human = array_merge( $this->editor_placeholder(), array( 'css' => 'color: hotpink' ) );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $human;

		$report = $persistence->apply( $this->plan() );

		self::assertTrue( $report->is_success(), 'A human-written duplicate must not fail the apply.' );
		self::assertSame( $human, $this->option( 'etch_styles' )['4ndy1la'] );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_DIVERGENT' ), $this->notice_codes( $report ) );
		self::assertStringContainsString( '4ndy1la', $report->notices()[0]->message() );

		$diagnosis = $persistence->diagnose( $this->plan() );
		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_DIVERGENT' ), $this->notice_codes( $diagnosis ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_builder_preview_placeholder_for_the_selector_is_adopted_not_reported_as_a_persons_style(): void {
		$this->install_wordpress();
		$preview = array(
			'selector'   => '.site-nav',
			'collection' => 'default',
			'css'        => '/* class style registered for builder preview */',
			'type'       => 'class',
			'readonly'   => true,
		);
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles'] = array( 'site-nav' => $preview );

		$report = ( new WordPressSitePersistence() )->apply( $this->plan() );

		self::assertTrue( $report->is_success() );
		self::assertSame( array(), $report->notices() );
		self::assertSame( 'display: flex', $this->option( 'etch_styles' )['site-nav']['css'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_diagnose_reports_an_adoptable_duplicate_without_writing(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $this->editor_placeholder();
		$before = $GLOBALS['etch_builders_site_persistence_options'];

		$diagnosis = $persistence->diagnose( $this->plan() );

		self::assertSame( array( 'ETCH_SITE_PERSISTENCE_STYLE_EDITOR_DUPLICATE_ADOPTABLE' ), $this->notice_codes( $diagnosis ) );
		self::assertSame( $before, $GLOBALS['etch_builders_site_persistence_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_the_code_owned_style_returns_its_mirror_to_an_empty_editor_style(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $this->editor_placeholder();
		$persistence->apply( $this->plan() );

		$persistence->apply( CompiledSitePlan::empty() );

		$styles = $this->option( 'etch_styles' );
		self::assertArrayNotHasKey( 'component-site-nav', $styles );
		self::assertSame( $this->editor_placeholder(), $styles['4ndy1la'] );
		self::assertSame( array(), $this->option( 'etch_builders_site_persistence_style_aliases' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_the_code_owned_style_gives_a_person_back_the_identical_style_they_wrote(): void {
		$this->install_wordpress();
		$written = array( 'type' => 'class', 'selector' => '.site-nav', 'collection' => 'default', 'css' => 'display: flex;', 'readonly' => false );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles'] = array( 'h4nd' => $written );
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		self::assertSame( 'display: flex', $this->option( 'etch_styles' )['h4nd']['css'], 'The identical style was adopted as a mirror.' );

		$persistence->apply( CompiledSitePlan::empty() );

		self::assertSame( $written, $this->option( 'etch_styles' )['h4nd'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_a_code_style_the_editor_resaved_removes_it_and_returns_its_mirror(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $this->editor_placeholder();
		$persistence->apply( $this->plan() );
		// An Etch editor save after the last apply drops the internal flag on every style.
		unset( $GLOBALS['etch_builders_site_persistence_options']['etch_styles']['component-site-nav']['overwrite_on_register'] );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['component-site-nav']['readonly'] = false;

		$report = $persistence->apply( CompiledSitePlan::empty() );

		self::assertTrue( $report->is_success() );
		$styles = $this->option( 'etch_styles' );
		self::assertArrayNotHasKey( 'component-site-nav', $styles );
		self::assertSame( $this->editor_placeholder(), $styles['4ndy1la'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_mirror_keeps_its_css_while_a_code_style_a_person_changed_stays(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['4ndy1la'] = $this->editor_placeholder();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['component-site-nav']['css'] = 'display: grid';

		$persistence->apply( CompiledSitePlan::empty() );

		$styles = $this->option( 'etch_styles' );
		self::assertSame( 'display: grid', $styles['component-site-nav']['css'] );
		self::assertSame( 'display: flex', $styles['4ndy1la']['css'], 'Elements linked to the mirror keep rendering while the selector still has CSS.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_complete_plan_with_nothing_to_write_still_waits_for_the_apply_lock_before_cleaning_up(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_apply_lock'] = 'honestlydesign/etch-builders:' . time();

		$report = $persistence->apply( CompiledSitePlan::empty() );

		self::assertFalse( $report->is_success() );
		self::assertArrayHasKey( 'component-site-nav', $this->option( 'etch_styles' ), 'Another apply holds the lock, so nothing may be cleaned up.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unrecorded_exact_style_in_its_legacy_collection_is_adopted_and_moved_to_default(): void {
		$this->install_wordpress();
		$native = $this->legacy_payload();
		unset( $native['overwrite_on_register'] );
		$native['readonly'] = false;
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles'] = array( 'component-site-nav' => $native );

		$report = ( new WordPressSitePersistence() )->apply( $this->plan() );

		self::assertSame( array( 'style:component-site-nav' => 'created' ), $this->outcomes( $report ) );
		self::assertSame( 'default', $this->option( 'etch_styles' )['component-site-nav']['collection'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unrecorded_identical_default_style_is_not_taken_over_as_code_owned(): void {
		$this->install_wordpress();
		// Without a ledger record or the internal flag, an identical default
		// style may be one a person wrote, so it is not adopted.
		$native = $this->owned_payload();
		unset( $native['overwrite_on_register'] );
		$native['readonly'] = false;
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles'] = array( 'component-site-nav' => $native );

		$report = ( new WordPressSitePersistence() )->apply( $this->plan() );

		self::assertSame( array( 'style:component-site-nav' => 'conflict' ), $this->outcomes( $report ) );
		self::assertSame( $native, $this->option( 'etch_styles' )['component-site-nav'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrading_from_2_1_0_moves_the_code_owned_style_to_default_under_the_same_id_and_keeps_its_mirror(): void {
		$this->install_wordpress();
		// State an apply with 2.1.0 left behind: the code-owned style in its
		// entity collection, an empty duplicate the Etch editor created and
		// that the 2.1.0 apply adopted as a mirror, and a later editor save.
		$legacy = $this->legacy_payload();
		$saved  = $legacy;
		unset( $saved['overwrite_on_register'] );
		$saved['readonly'] = false;
		$mirror = array_merge( $this->editor_placeholder(), array( 'css' => 'display: flex' ) );
		$GLOBALS['etch_builders_site_persistence_options'] = array(
			'etch_styles' => array(
				'component-site-nav' => $saved,
				'4ndy1la'            => $mirror,
				'etch-section-style' => $this->etch_builtin(),
			),
			'etch_builders_site_persistence_resources' => $this->ledger( $legacy ),
			'etch_builders_site_persistence_style_aliases' => array(
				'4ndy1la' => array(
					'canonical' => 'component-site-nav',
					'hash'      => \HonestlyDesign\EtchBuilders\Support\EditorStyleDuplicates::payload_hash( $mirror ),
					'original'  => $this->editor_placeholder(),
				),
			),
		);
		Environment::configure( $this->wordpress_storage(), new NullMode(), new NullAssetRegistry() );

		$styles = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; }' ) );
		self::assertSame( 'component-site-nav', $styles->class_reference( '.site-nav' )->id(), 'Elements reference the style ID, so it must not change.' );

		$persistence = new WordPressSitePersistence();
		$report      = $persistence->apply( $this->site_plan( $styles ) );

		self::assertTrue( $report->is_success() );
		self::assertSame( array( 'style:component-site-nav' => 'updated' ), $this->style_outcomes( $report ) );
		self::assertSame( array(), $report->notices() );
		$native = $this->option( 'etch_styles' );
		self::assertSame( 'default', $native['component-site-nav']['collection'], 'The Etch editor finds the style only in default.' );
		self::assertSame( 'display: flex', $native['component-site-nav']['css'] );
		self::assertSame( $mirror, $native['4ndy1la'], 'The adopted mirror keeps rendering the code-owned CSS.' );
		self::assertSame( $this->etch_builtin(), $native['etch-section-style'] );
		self::assertSame( array( 'component-site-nav', '4ndy1la', 'etch-section-style' ), array_map( 'strval', array_keys( $native ) ), 'No new duplicate is created.' );
		self::assertSame( 'component-site-nav', $this->option( 'etch_builders_site_persistence_style_aliases' )['4ndy1la']['canonical'] );

		$again = $persistence->apply( $this->site_plan( $styles ) );
		self::assertSame( array(), $again->applied_results() );
		self::assertSame( array(), $again->notices() );

		Style::reset();
		self::assertSame(
			'component-site-nav',
			EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; }' ) )->class_reference( '.site-nav' )->id(),
			'A later request still reuses the ID once the style lives in default.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_after_an_editor_save_builders_still_recognizes_its_default_styles(): void {
		$this->install_wordpress();
		Environment::configure( $this->wordpress_storage(), new NullMode(), new NullAssetRegistry() );
		$styles      = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; } .site-nav__link { color: red; }' ) );
		$persistence = new WordPressSitePersistence();
		self::assertTrue( $persistence->apply( $this->site_plan( $styles ) )->is_success() );

		// An Etch editor save keeps only these keys on every style.
		foreach ( $GLOBALS['etch_builders_site_persistence_options']['etch_styles'] as $id => $style ) {
			$GLOBALS['etch_builders_site_persistence_options']['etch_styles'][ $id ] = array_intersect_key(
				$style,
				array_flip( array( 'type', 'selector', 'collection', 'css', 'readonly' ) )
			) + array( 'readonly' => false );
		}

		self::assertSame( array(), $persistence->diagnose( $this->site_plan( $styles ) )->notices(), 'Code-owned styles are not editor duplicates or person-written styles.' );

		Style::reset();
		$reparsed = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; } .site-nav__link { color: red; }' ) );
		self::assertSame( $styles->style_ids(), $reparsed->style_ids() );

		$report = $persistence->apply( $this->site_plan( $reparsed ) );
		self::assertTrue( $report->is_success() );
		self::assertSame( array(), $report->notices() );
		self::assertSame( array( 'updated', 'updated' ), array_values( $this->style_outcomes( $report ) ), 'The dropped internal flag is restored.' );
		self::assertCount( 2, $this->option( 'etch_styles' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_style_is_not_written_without_its_ownership_record(): void {
		$this->install_wordpress();
		$GLOBALS['etch_builders_site_persistence_failing_option'] = 'etch_builders_site_persistence_resources';

		$report = ( new WordPressSitePersistence() )->apply( $this->plan() );

		self::assertFalse( $report->is_success() );
		self::assertArrayNotHasKey( 'component-site-nav', $this->option( 'etch_styles' ), 'A default style without its ledger record could not be told apart from a person\'s style later.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cleanup_decides_what_to_remove_only_once_it_holds_the_apply_lock(): void {
		$this->install_wordpress();
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->plan() );
		$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:component-site-nav', $this->owned_payload() );
		$landing = CompiledSitePlan::from_sections(
			entities: array( CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:slug:landing', array( 'slug' => 'landing', 'blocks' => 'landing' ) ) ),
			styles: array( $style ),
			ownership: array( CompiledSiteOwnership::new( 'page:slug:landing', $style->identity(), 'style' ) )
		);
		// Another request applies a page that now owns the style while this one waits for the lock.
		$GLOBALS['etch_builders_site_persistence_before_add_option']['etch_builders_site_apply_lock'] = static function () use ( $landing ): void {
			self::assertTrue( ( new WordPressSitePersistence() )->apply( $landing )->is_success() );
		};

		$report = $persistence->apply( CompiledSitePlan::empty() );

		self::assertTrue( $report->is_success() );
		self::assertArrayHasKey( 'component-site-nav', $this->option( 'etch_styles' ), 'The style belongs to a live page by the time the lock is held.' );
	}

	/**
	 * A complete plan for one component that owns the given Entity Style Set.
	 */
	private function site_plan( EntityStyleSet $styles ): CompiledSitePlan {
		return SiteDefinition::new()
			->component( Component::new( 'SiteNav', 'Site navigation' )->key( 'SiteNav' )->blocks( TextBlock::new()->content( 'Nav' ) ) )
			->supporting( $styles )
			->compile();
	}

	/**
	 * @return array<string, string>
	 */
	private function style_outcomes( SitePersistenceReport $report ): array {
		return array_filter(
			$this->outcomes( $report ),
			static fn ( string $identity ): bool => str_starts_with( $identity, 'style:' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	private function plan(): CompiledSitePlan {
		$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:component-site-nav', $this->owned_payload() );

		return CompiledSitePlan::from_sections(
			styles: array( $style ),
			ownership: array( CompiledSiteOwnership::new( 'site:root', $style->identity(), 'style' ) )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function owned_payload(): array {
		return array(
			'selector'              => '.site-nav',
			'collection'            => self::COLLECTION,
			'css'                   => 'display: flex',
			'type'                  => 'class',
			'overwrite_on_register' => true,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function legacy_payload(): array {
		return array_merge( $this->owned_payload(), array( 'collection' => self::LEGACY_COLLECTION ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function etch_builtin(): array {
		return array(
			'type'       => 'custom',
			'selector'   => ':where([data-etch-element="section"])',
			'collection' => 'default',
			'css'        => 'inline-size: 100%;',
			'readonly'   => true,
		);
	}

	/**
	 * Resource ledger entry as a site apply records it.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, array<string, mixed>>
	 */
	private function ledger( array $payload, string $style_id = 'component-site-nav' ): array {
		$resource = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:' . $style_id, $payload );
		$record   = SitePersistenceRecord::from_resource(
			$resource,
			true,
			array( CompiledSiteOwnership::new( 'component:SiteNav', $resource->identity(), 'style' ) )
		);

		return array( $resource->identity() => $record->to_array() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function editor_placeholder(): array {
		return array(
			'type'       => 'class',
			'selector'   => '.site-nav',
			'collection' => 'default',
			'css'        => '',
			'readonly'   => false,
		);
	}

	private function install_wordpress(): void {
		WordPressPersistenceStubs::install();
		$GLOBALS['etch_builders_site_persistence_options'] = array();
		$GLOBALS['etch_builders_site_persistence_posts']   = array();
		$GLOBALS['etch_builders_site_persistence_meta']    = array();
	}

	private function wordpress_storage(): StorageInterface {
		return new class() implements StorageInterface {
			public function get( string $key, mixed $default = null ): mixed {
				return \get_option( $key, $default );
			}

			public function set( string $key, mixed $value ): bool {
				return \update_option( $key, $value );
			}

			public function delete( string $key ): bool {
				return \delete_option( $key );
			}
		};
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function option( string $name ): array {
		$value = $GLOBALS['etch_builders_site_persistence_options'][ $name ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string, string>
	 */
	private function outcomes( SitePersistenceReport $report ): array {
		$outcomes = array();
		foreach ( $report->results() as $result ) {
			$outcomes[ $result->identity() ] = $result->outcome()->value;
		}

		return $outcomes;
	}

	/**
	 * @return array<int, string>
	 */
	private function notice_codes( SitePersistenceReport $report ): array {
		return array_map( static fn ( CompiledSiteDiagnostic $notice ): string => $notice->code(), $report->notices() );
	}

	private function write_css( string $css ): string {
		$file_path = tempnam( sys_get_temp_dir(), 'etch-editor-duplicate-' );
		if ( false === $file_path ) {
			self::fail( 'Could not create a temporary CSS file.' );
		}
		file_put_contents( $file_path, $css );
		$this->temporary_files[] = $file_path;

		return $file_path;
	}
}
