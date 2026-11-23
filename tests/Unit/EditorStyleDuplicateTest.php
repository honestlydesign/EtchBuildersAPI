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
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\EntityStyleSet;
use HonestlyDesign\EtchBuilders\Environment;
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
 * collection, so editing a code-owned element creates an empty `default`
 * style with the same selector and links the element to it.
 */
final class EditorStyleDuplicateTest extends TestCase {

	private const COLLECTION = 'OhMyIDEtch:entity:component:SiteNav';

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

	public function test_entity_styles_keep_their_code_owned_id_beside_an_empty_editor_duplicate(): void {
		$storage = new NullStorage();
		$storage->set(
			'etch_styles',
			array(
				'component-site-nav' => $this->owned_payload(),
				'4ndy1la'            => $this->editor_placeholder(),
			)
		);
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$styles = EntityStyleSet::from_file( 'component:SiteNav', $this->write_css( '.site-nav { display: flex; }' ) );

		self::assertSame( 'component-site-nav', $styles->class_reference( '.site-nav' )->id() );
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
				'page-site-nav'      => array_merge( $this->owned_payload(), array( 'collection' => 'OhMyIDEtch:entity:page:home' ) ),
			)
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
	public function test_an_unrecorded_exact_style_in_its_own_collection_is_adopted_after_the_editor_dropped_its_flag(): void {
		$this->install_wordpress();
		$native = $this->owned_payload();
		unset( $native['overwrite_on_register'] );
		$native['readonly'] = false;
		$GLOBALS['etch_builders_site_persistence_options']['etch_styles'] = array( 'component-site-nav' => $native );

		$report = ( new WordPressSitePersistence() )->apply( $this->plan() );

		self::assertSame( array( 'style:component-site-nav' => 'created' ), $this->outcomes( $report ) );
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
