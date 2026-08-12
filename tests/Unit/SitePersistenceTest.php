<?php
/**
 * Compiled Site Plan persistence seam tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit {

	use HonestlyDesign\EtchBuilders\CompiledSiteDiagnostic;
	use HonestlyDesign\EtchBuilders\CompiledSiteDiagnosticSeverity;
	use HonestlyDesign\EtchBuilders\CompiledSiteDependency;
	use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
	use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
	use HonestlyDesign\EtchBuilders\CompiledSitePlan;
	use HonestlyDesign\EtchBuilders\CompiledSiteResource;
	use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
	use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
	use HonestlyDesign\EtchBuilders\RegistrationResult;
	use HonestlyDesign\EtchBuilders\SitePersistenceReport;
	use HonestlyDesign\EtchBuilders\SitePersistenceResult;
	use HonestlyDesign\EtchBuilders\Support\InMemorySitePersistence;
	use HonestlyDesign\EtchBuilders\Support\InMemorySitePersistenceStore;
	use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistence;
	use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
	use PHPUnit\Framework\TestCase;

	/**
	 * Contract tests for the compiled-plan persistence boundary.
	 */
	final class SitePersistenceTest extends TestCase {

		public function test_first_apply_creates_records_and_identical_apply_is_unchanged(): void {
			$persistence = new InMemorySitePersistence();
			$plan        = $this->plan();

			$created = $persistence->apply( $plan );
			$again   = $persistence->apply( $plan );

			self::assertTrue( $created->is_success() );
			self::assertSame( array( 'created', 'created' ), $this->outcomes( $created ) );
			self::assertTrue( $again->is_success() );
			self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( array( 'component:Hero', 'style:hero' ), $this->identities( $again ) );
		}

		public function test_pattern_dependencies_are_applied_before_their_consumers(): void {
			$store = new RecordingSitePersistenceStore();
			$plan  = CompiledSitePlan::from_sections(
				entities: array( $this->entity( 'consumer' ), $this->pattern_entity() ),
				dependencies: array( CompiledSiteDependency::new( 'component:Hero', 'pattern:Hero', 'pattern' ) )
			);

			$report = ( new InMemorySitePersistence( $store ) )->apply( $plan );

			self::assertTrue( $report->is_success() );
			self::assertSame( array( 'pattern:Hero', 'component:Hero' ), $store->created );
			self::assertSame( array( 'created', 'created' ), $this->outcomes( $report ) );
		}

		public function test_unresolved_pattern_dependency_blocks_all_writes(): void {
			$store = new CountingSitePersistenceStore();
			$plan  = CompiledSitePlan::from_sections(
				entities: array( $this->entity( 'consumer' ) ),
				dependencies: array( CompiledSiteDependency::new( 'component:Hero', 'pattern:Missing', 'pattern' ) )
			);

			$report = ( new InMemorySitePersistence( $store ) )->apply( $plan );

			self::assertFalse( $report->is_success() );
			self::assertTrue( $report->was_blocked() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_DEPENDENCY_INVALID', $report->blocking_diagnostics()[0]->code() );
			self::assertSame( 0, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

		public function test_pattern_dependency_cycle_blocks_all_writes(): void {
			$store = new CountingSitePersistenceStore();
			$plan  = CompiledSitePlan::from_sections(
				entities: array( $this->pattern_entity( 'First' ), $this->pattern_entity( 'Second' ) ),
				dependencies: array(
					CompiledSiteDependency::new( 'pattern:First', 'pattern:Second', 'pattern' ),
					CompiledSiteDependency::new( 'pattern:Second', 'pattern:First', 'pattern' ),
				)
			);

			$report = ( new InMemorySitePersistence( $store ) )->apply( $plan );

			self::assertFalse( $report->is_success() );
			self::assertTrue( $report->was_blocked() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_DEPENDENCY_CYCLE', $report->blocking_diagnostics()[0]->code() );
			self::assertSame( 0, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

		public function test_changed_owned_record_is_updated(): void {
			$persistence = new InMemorySitePersistence();
			$persistence->apply( $this->plan( 'before' ) );

			$report = $persistence->apply( $this->plan( 'after' ) );

			self::assertTrue( $report->is_success() );
			self::assertSame( array( 'updated', 'updated' ), $this->outcomes( $report ) );
		}

		public function test_external_record_is_a_conflict_and_is_not_overwritten(): void {
			$entity = $this->entity( 'incoming' );
			$store  = new InMemorySitePersistenceStore();
			$store->seed( SitePersistenceRecord::from_entity( $this->entity( 'external' ), false ) );
			$persistence = new InMemorySitePersistence( $store );

			$report = $persistence->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'conflict' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_CONFLICT', $report->results()[0]->code() );
			$stored = $store->find( $entity->identity() );
			self::assertNotNull( $stored );
			self::assertFalse( $stored->is_owned() );
		}

		public function test_identical_external_record_is_still_a_conflict(): void {
			$entity = $this->entity( 'identical' );
			$store  = new InMemorySitePersistenceStore();
			$store->seed( SitePersistenceRecord::from_entity( $entity, false ) );
			$persistence = new InMemorySitePersistence( $store );

			$report = $persistence->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'conflict' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_CONFLICT', $report->results()[0]->code() );
		}

		public function test_store_failure_is_returned_as_a_typed_failed_outcome(): void {
			$store       = new FailingSitePersistenceStore();
			$persistence = new InMemorySitePersistence( $store );

			$report = $persistence->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'failure' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'failed' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_TEST_PERSISTENCE_FAILURE', $report->results()[0]->code() );
		}

		public function test_entity_failure_is_reported_without_false_success_for_other_entities(): void {
			$store = new SelectiveSitePersistenceStore( 'component:Hero' );
			$plan  = CompiledSitePlan::from_sections(
				entities: array( $this->entity( 'failure' ), $this->pattern_entity() )
			);

			$report = ( new InMemorySitePersistence( $store ) )->apply( $plan );

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'failed', 'created' ), $this->outcomes( $report ) );
			self::assertSame( array( 'component:Hero', 'pattern:Hero' ), $this->identities( $report ) );
		}

		public function test_store_conflict_result_remains_a_typed_conflict_outcome(): void {
			$persistence = new InMemorySitePersistence( new ConflictSitePersistenceStore() );

			$report = $persistence->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'race' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'conflict' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_CONFLICT', $report->results()[0]->code() );
		}

		public function test_blocking_diagnostics_reject_plan_before_store_is_touched(): void {
			$store       = new CountingSitePersistenceStore();
			$persistence = new InMemorySitePersistence( $store );
			$diagnostic  = CompiledSiteDiagnostic::new(
				'ETCH_SITE_BLOCKED',
				CompiledSiteDiagnosticSeverity::ERROR,
				'Compilation is not eligible for persistence.',
				'page:home'
			);

			$report = $persistence->apply(
				CompiledSitePlan::from_sections( diagnostics: array( $diagnostic ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertTrue( $report->was_blocked() );
			self::assertSame( array( $diagnostic ), $report->blocking_diagnostics() );
			self::assertSame( 0, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

		public function test_wordpress_adapter_shares_the_same_port_and_outcomes(): void {
			$store       = new RecordingSitePersistenceStore();
			$persistence = new WordPressSitePersistence( $store );
			$report      = $persistence->apply( $this->plan() );

			self::assertTrue( $report->is_success() );
			self::assertSame( array( 'component:Hero', 'style:hero' ), $store->created );
			self::assertSame( array( 'created', 'created' ), $this->outcomes( $report ) );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_default_wordpress_adapter_persists_native_component_and_option_resource_records(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( $this->plan() );
			$again       = $persistence->apply( $this->plan() );

			self::assertSame( array( 'created', 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
			/** @var array<int, array<string, mixed>> $meta */
			$meta = $GLOBALS['etch_builders_site_persistence_meta'];
			self::assertSame( 'Hero', $meta[1]['etch_component_html_key'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_default_wordpress_adapter_persists_patterns_as_native_wp_blocks(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$plan       = CompiledSitePlan::from_sections( entities: array( $this->pattern_entity() ) );
			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( $plan );
			$again       = $persistence->apply( $plan );

			self::assertSame( array( 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $again ) );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
			/** @var array<int, array<string, mixed>> $meta */
			$meta = $GLOBALS['etch_builders_site_persistence_meta'];
			self::assertSame( 'Hero', $meta[1]['oh_my_id_etch_pattern_key'] );
			self::assertSame( 'unsynced', $meta[1]['wp_pattern_sync_status'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_default_wordpress_adapter_conflicts_with_unowned_native_component(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array(
				1 => (object) array(
					'ID'           => 1,
					'post_type'    => 'wp_block',
					'post_name'    => 'legacy-component-hero',
					'post_title'   => 'External Hero',
					'post_excerpt' => 'External',
					'post_content' => 'external content',
				),
			);
			$GLOBALS['etch_builders_site_persistence_meta'] = array( 1 => array( 'etch_component_html_key' => 'Hero' ) );

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'incoming' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'conflict' ), $this->outcomes( $report ) );
			self::assertSame( 'External Hero', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_title );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_metadata_failure_is_reported_and_new_post_is_rolled_back(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_fail_meta'] = 'etch_builders_site_persistence_owner';

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'failure' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'failed' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_META_FAILED', $report->results()[0]->code() );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_posts'] );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_options'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_identity_can_be_recreated_after_the_post_is_deleted(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$persistence = new WordPressSitePersistence();
			$first       = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->entity( 'first' ) ) ) );
			wp_delete_post( 1, true );
			$recreated = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->entity( 'second' ) ) ) );

			self::assertSame( array( 'created' ), $this->outcomes( $first ) );
			self::assertSame( array( 'created' ), $this->outcomes( $recreated ) );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_identity_claim_is_released_when_wordpress_throws_after_claiming(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_throw_insert'] = true;

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'throws' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_EXCEPTION', $report->results()[0]->code() );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_rollback_failure_is_reported_as_partial_write(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_fail_meta'] = 'etch_builders_site_persistence_owner';
			$GLOBALS['etch_builders_site_persistence_fail_delete'] = true;

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'partial' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', $report->results()[0]->code() );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pattern_categories_require_the_native_taxonomy(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->pattern_entity( 'Hero', 'pattern', array( 'Hero Blocks' ) ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_TAXONOMY_UNAVAILABLE', $report->results()[0]->code() );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pattern_categories_use_native_term_names_and_are_idempotent(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_taxonomy_available'] = true;

			$plan       = CompiledSitePlan::from_sections( entities: array( $this->pattern_entity( 'Hero', 'pattern', array( 'Hero Blocks' ) ) ) );
			$persistence = new WordPressSitePersistence();
			$created    = $persistence->apply( $plan );
			self::assertSame( 'honestlydesign/etch-builders', $GLOBALS['etch_builders_site_persistence_meta'][1]['etch_builders_site_persistence_owner'] );
			$again      = $persistence->apply( $plan );

			self::assertSame( array( 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( array( 'Hero Blocks' ), $GLOBALS['etch_builders_site_persistence_term_inputs'] );
			self::assertSame( 'hero-blocks', $GLOBALS['etch_builders_site_persistence_terms'][1][0]->slug );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pattern_category_failure_is_reported_and_new_post_is_rolled_back(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_taxonomy_available'] = true;
			$GLOBALS['etch_builders_site_persistence_fail_terms'] = true;

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->pattern_entity( 'Hero', 'pattern', array( 'Hero Blocks' ) ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'term_failed', $report->results()[0]->code() );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_posts'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_drift_is_updated_and_then_becomes_unchanged(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$persistence = new WordPressSitePersistence();
			$first       = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->entity( 'initial' ) ) ) );
			$GLOBALS['etch_builders_site_persistence_posts'][1]->post_title = 'Manually changed';
			$GLOBALS['etch_builders_site_persistence_posts'][1]->post_status = 'draft';
			$repaired = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->entity( 'repaired' ) ) ) );
			$again    = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $this->entity( 'repaired' ) ) ) );

			self::assertSame( array( 'created' ), $this->outcomes( $first ) );
			self::assertSame( array( 'updated' ), $this->outcomes( $repaired ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( 'Hero', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_title );
		}

		public function test_port_rejects_raw_arrays_at_the_public_boundary(): void {
			$persistence = new InMemorySitePersistence();

			$this->expectException( \TypeError::class );
			/** @phpstan-ignore-next-line argument.type */
			$persistence->apply( array() );
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

		/**
		 * @return array<int, string>
		 */
		private function identities( SitePersistenceReport $report ): array {
			return array_map(
				static fn ( SitePersistenceResult $result ): string => $result->identity(),
				$report->results()
			);
		}

		private function install_wordpress_option_stubs(): void {
			eval(
				<<<'PHP'
	namespace {
		if ( ! class_exists( 'WP_Error', false ) ) {
			final class WP_Error {
				public function __construct( private string $code, private string $message ) {}

				public function get_error_code(): string {
					return $this->code;
				}

				public function get_error_message(): string {
					return $this->message;
				}
			}
		}

		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}

		if ( ! function_exists( 'is_wp_error' ) ) {
			function is_wp_error( mixed $value ): bool {
				return $value instanceof WP_Error;
			}
		}

		if ( ! function_exists( 'sanitize_key' ) ) {
			function sanitize_key( string $key ): string {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
			}
		}

		if ( ! function_exists( 'sanitize_text_field' ) ) {
			function sanitize_text_field( mixed $value ): string {
				return trim( strip_tags( (string) $value ) );
			}
		}

		if ( ! function_exists( 'wp_slash' ) ) {
			function wp_slash( mixed $value ): mixed {
				return $value;
			}
		}

		if ( ! function_exists( 'sanitize_title' ) ) {
			function sanitize_title( string $title ): string {
				$title = strtolower( trim( $title ) );

				return trim( preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '', '-' );
			}
		}

		if ( ! function_exists( 'get_option' ) ) {
			function get_option( string $option, mixed $default = false ): mixed {
				$options = $GLOBALS['etch_builders_site_persistence_options'] ?? array();

				return array_key_exists( $option, $options ) ? $options[ $option ] : $default;
			}
		}

		if ( ! function_exists( 'add_option' ) ) {
			function add_option( string $option, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
				$options = $GLOBALS['etch_builders_site_persistence_options'] ?? array();
				if ( array_key_exists( $option, $options ) ) {
					return false;
				}

				$GLOBALS['etch_builders_site_persistence_options'][ $option ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			function update_option( string $option, mixed $value, bool $autoload = true ): bool {
				$GLOBALS['etch_builders_site_persistence_options'][ $option ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			function delete_option( string $option ): bool {
				$exists = array_key_exists( $option, $GLOBALS['etch_builders_site_persistence_options'] ?? array() );
				unset( $GLOBALS['etch_builders_site_persistence_options'][ $option ] );

				return $exists;
			}
		}

		if ( ! function_exists( 'get_page_by_path' ) ) {
			function get_page_by_path( string $slug, mixed $output = OBJECT, string $post_type = 'page' ): ?object {
				foreach ( $GLOBALS['etch_builders_site_persistence_posts'] ?? array() as $post ) {
					if ( $post->post_name === $slug && $post->post_type === $post_type ) {
						return $post;
					}
				}

				return null;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			function get_posts( array $args ): array {
				$posts     = $GLOBALS['etch_builders_site_persistence_posts'] ?? array();
				$post_type = $args['post_type'] ?? null;
				$meta_key  = $args['meta_key'] ?? null;
				$meta_value = $args['meta_value'] ?? null;
				$matches   = array();

				foreach ( $posts as $post_id => $post ) {
					if ( null !== $post_type && ( $post->post_type ?? null ) !== $post_type ) {
						continue;
					}
					if ( null !== $meta_key && ( $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $meta_key ] ?? null ) !== $meta_value ) {
						continue;
					}

					$matches[] = $post;
				}

				return $matches;
			}
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			function wp_insert_post( array $post_data, bool $wp_error = false ): int {
				if ( $GLOBALS['etch_builders_site_persistence_throw_insert'] ?? false ) {
					throw new RuntimeException( 'insert failed' );
				}

				$id             = count( $GLOBALS['etch_builders_site_persistence_posts'] ?? array() ) + 1;
				$post_data['ID'] = $id;
				$GLOBALS['etch_builders_site_persistence_posts'][ $id ] = (object) $post_data;

				return $id;
			}
		}

		if ( ! function_exists( 'wp_update_post' ) ) {
			function wp_update_post( array $post_data, bool $wp_error = false ): int {
				$id = (int) ( $post_data['ID'] ?? 0 );
				if ( $id <= 0 || ! isset( $GLOBALS['etch_builders_site_persistence_posts'][ $id ] ) ) {
					return 0;
				}

				$GLOBALS['etch_builders_site_persistence_posts'][ $id ] = (object) array_merge(
					(array) $GLOBALS['etch_builders_site_persistence_posts'][ $id ],
					$post_data
				);

				return $id;
			}
		}

		if ( ! function_exists( 'wp_delete_post' ) ) {
			function wp_delete_post( int $post_id, bool $force_delete = false ): ?object {
				if ( $GLOBALS['etch_builders_site_persistence_fail_delete'] ?? false ) {
					return null;
				}

				$post = $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ] ?? null;
				unset( $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ], $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ] );

				return is_object( $post ) ? $post : null;
			}
		}

		if ( ! function_exists( 'update_post_meta' ) ) {
			function update_post_meta( int $post_id, string $key, mixed $value ): bool {
				if ( ( $GLOBALS['etch_builders_site_persistence_fail_meta'] ?? null ) === $key ) {
					return false;
				}

				$GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'get_post_meta' ) ) {
			function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
				return $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] ?? '';
			}
		}

		if ( ! function_exists( 'taxonomy_exists' ) ) {
			function taxonomy_exists( string $taxonomy ): bool {
				return (bool) ( $GLOBALS['etch_builders_site_persistence_taxonomy_available'] ?? false );
			}
		}

		if ( ! function_exists( 'wp_set_object_terms' ) ) {
			function wp_set_object_terms( int $post_id, array $terms, string $taxonomy ): array|WP_Error {
				if ( $GLOBALS['etch_builders_site_persistence_fail_terms'] ?? false ) {
					return new WP_Error( 'term_failed', 'term synchronization failed' );
				}

				$GLOBALS['etch_builders_site_persistence_term_inputs'] = $terms;
				$GLOBALS['etch_builders_site_persistence_terms'][ $post_id ] = array_map(
					static fn ( string $term ): object => (object) array( 'slug' => sanitize_title( $term ) ),
					$terms
				);

				return array( 1 );
			}
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			function get_the_terms( int $post_id, string $taxonomy ): array|WP_Error {
				return $GLOBALS['etch_builders_site_persistence_terms'][ $post_id ] ?? array();
			}
		}
	}
PHP
			);
		}

		private function entity( string $content ): CompiledSiteEntity {
			return CompiledSiteEntity::new(
				CompiledSiteEntityType::COMPONENT,
				'component:Hero',
				array(
					'name'        => 'Hero',
					'description' => 'Hero component',
					'blocks'      => '<!-- wp:etch/text -->' . $content . '<!-- /wp:etch/text -->',
					'properties'  => array(),
				)
			);
		}

		private function pattern_entity( string $key = 'Hero', string $content = 'pattern', array $categories = array() ): CompiledSiteEntity {
			return CompiledSiteEntity::new(
				CompiledSiteEntityType::PATTERN,
				'pattern:' . $key,
				array(
					'name'        => $key,
					'description' => $key . ' pattern',
					'blocks'      => '<!-- wp:etch/text -->' . $content . '<!-- /wp:etch/text -->',
					'categories'  => $categories,
				)
			);
		}

		private function plan( string $content = 'hero' ): CompiledSitePlan {
			$entity = $this->entity( $content );
			$style  = CompiledSiteResource::new(
				CompiledSiteResourceType::STYLE,
				'style:hero',
				array( 'selector' => '.hero', 'css' => $content )
			);

			return CompiledSitePlan::from_sections(
				entities: array( $entity ),
				styles: array( $style )
			);
		}
	}

	/**
	 * Store that fails only one identity while allowing the rest to persist.
	 */
	final class SelectiveSitePersistenceStore implements SitePersistenceStoreInterface {

		public function __construct( private string $failed_identity ) {
		}

		public function find( string $identity ): ?SitePersistenceRecord {
			return null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			if ( $record->identity() === $this->failed_identity ) {
				return RegistrationResult::error( 'ETCH_TEST_PERSISTENCE_FAILURE', 'The selected identity failed.' );
			}

			return RegistrationResult::success();
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::success();
		}
	}

	/**
	 * Failing store used to prove adapter error mapping without WordPress.
	 */
	final class FailingSitePersistenceStore implements SitePersistenceStoreInterface {

		public function find( string $identity ): ?SitePersistenceRecord {
			return null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::error( 'ETCH_TEST_PERSISTENCE_FAILURE', 'The test store rejected the create.' );
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::error( 'ETCH_TEST_PERSISTENCE_FAILURE', 'The test store rejected the update.' );
		}
	}

	/**
	 * Store used to prove adapter conflict mapping at the write boundary.
	 */
	final class ConflictSitePersistenceStore implements SitePersistenceStoreInterface {

		public function find( string $identity ): ?SitePersistenceRecord {
			return null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The identity was claimed concurrently.' );
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The identity was claimed concurrently.' );
		}
	}

	/**
	 * Store spy proving blocked plans do not even read persistence state.
	 */
	final class CountingSitePersistenceStore implements SitePersistenceStoreInterface {

		public int $find_calls   = 0;
		public int $create_calls = 0;
		public int $update_calls = 0;

		public function find( string $identity ): ?SitePersistenceRecord {
			++$this->find_calls;
			return null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			++$this->create_calls;
			return RegistrationResult::success();
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			++$this->update_calls;
			return RegistrationResult::success();
		}
	}

	/**
	 * Store spy proving the WordPress adapter uses the shared store contract.
	 */
	final class RecordingSitePersistenceStore implements SitePersistenceStoreInterface {

		/** @var array<string, SitePersistenceRecord> */
		private array $records = array();

		/** @var array<int, string> */
		public array $created = array();

		public function find( string $identity ): ?SitePersistenceRecord {
			return $this->records[ $identity ] ?? null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			$this->records[ $record->identity() ] = $record;
			$this->created[]                     = $record->identity();

			return RegistrationResult::success();
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			$this->records[ $record->identity() ] = $record;

			return RegistrationResult::success();
		}
	}
}
