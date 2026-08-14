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
	use HonestlyDesign\EtchBuilders\CompiledSiteEntityPersistenceIntent;
	use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
	use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
	use HonestlyDesign\EtchBuilders\CompiledSitePlan;
	use HonestlyDesign\EtchBuilders\CompiledSiteResource;
	use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
	use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
	use HonestlyDesign\EtchBuilders\RegistrationResult;
	use HonestlyDesign\EtchBuilders\SitePersistenceReport;
	use HonestlyDesign\EtchBuilders\SitePersistenceResult;
	use HonestlyDesign\EtchBuilders\SitePersistenceOutcome;
	use HonestlyDesign\EtchBuilders\SiteHomePolicy;
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
			$store       = new RecordingSitePersistenceStore();
			$persistence = new InMemorySitePersistence( $store );
			$plan        = $this->plan();

			$created = $persistence->apply( $plan );
			$again   = $persistence->apply( $plan );

			self::assertTrue( $created->is_success() );
			self::assertSame( array( 'created', 'created' ), $this->outcomes( $created ) );
			self::assertTrue( $again->is_success() );
			self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( array( 'component:Hero', 'style:hero' ), $this->identities( $again ) );
			self::assertSame( 2, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
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

		public function test_exact_native_dependency_is_verified_without_claiming_or_writing_it(): void {
			$entity = $this->native_loop_entity();
			$store  = new InMemorySitePersistenceStore();
			$store->seed( SitePersistenceRecord::from_entity( $entity, false ) );

			$report = ( new InMemorySitePersistence( $store ) )->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertTrue( $report->is_success() );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_VERIFIED', $report->results()[0]->code() );
			$stored = $store->find( $entity->identity() );
			self::assertNotNull( $stored );
			self::assertFalse( $stored->is_owned() );
		}

		public function test_missing_native_dependency_fails_closed_without_creating_it(): void {
			$store  = new CountingSitePersistenceStore();
			$entity = $this->native_loop_entity();

			$report = ( new InMemorySitePersistence( $store ) )->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'conflict' ), $this->outcomes( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_MISSING', $report->results()[0]->code() );
			self::assertSame( 1, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

		public function test_drifted_native_dependency_fails_closed_without_overwriting_it(): void {
			$entity = $this->native_loop_entity();
			$drifted = CompiledSiteEntity::new(
				CompiledSiteEntityType::LOOP_PRESET,
				$entity->identity(),
				array_merge( $entity->payload(), array( 'name' => 'Changed upstream' ) )
			);
			$store = new InMemorySitePersistenceStore();
			$store->seed( SitePersistenceRecord::from_entity( $drifted, false ) );

			$report = ( new InMemorySitePersistence( $store ) )->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_DRIFT', $report->results()[0]->code() );
			$stored = $store->find( $entity->identity() );
			self::assertNotNull( $stored );
			self::assertSame( 'Changed upstream', $stored->payload()['name'] );
			self::assertFalse( $stored->is_owned() );
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

		public function test_report_classifies_applied_unchanged_conflicted_skipped_and_failed_results(): void {
			$report = SitePersistenceReport::new(
				array(
					SitePersistenceResult::new( 'component:created', SitePersistenceOutcome::CREATED, 'created', 'created' ),
					SitePersistenceResult::new( 'component:updated', SitePersistenceOutcome::UPDATED, 'updated', 'updated' ),
					SitePersistenceResult::new( 'component:unchanged', SitePersistenceOutcome::UNCHANGED, 'unchanged', 'unchanged' ),
					SitePersistenceResult::new( 'component:conflict', SitePersistenceOutcome::CONFLICT, 'conflict', 'conflict' ),
					SitePersistenceResult::new( 'component:skipped', SitePersistenceOutcome::SKIPPED, 'skipped', 'skipped' ),
					SitePersistenceResult::new( 'component:failed', SitePersistenceOutcome::FAILED, 'failed', 'failed' ),
				)
			);

			self::assertSame( array( 'component:created', 'component:updated' ), $this->result_identities( $report->applied_results() ) );
			self::assertSame( array( 'component:unchanged' ), $this->result_identities( $report->unchanged_results() ) );
			self::assertSame( array( 'component:conflict' ), $this->result_identities( $report->conflicted_results() ) );
			self::assertSame( array( 'component:skipped' ), $this->result_identities( $report->skipped_results() ) );
			self::assertSame( array( 'component:failed' ), $this->result_identities( $report->failed_results() ) );
			self::assertTrue( $report->results()[0]->is_applied() );
			self::assertFalse( $report->results()[4]->is_applied() );
			self::assertFalse( $report->is_success() );
		}

		public function test_store_skip_result_preserves_identity_reason_and_is_not_applied(): void {
			$report = ( new InMemorySitePersistence( new SkippingSitePersistenceStore() ) )->apply(
				CompiledSitePlan::from_sections( entities: array( $this->entity( 'skipped' ) ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( array( 'skipped' ), $this->outcomes( $report ) );
			self::assertSame( array( 'component:Hero' ), $this->identities( $report ) );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_SKIPPED', $report->results()[0]->code() );
			self::assertSame( 'Dev-only entity is not active in this runtime.', $report->results()[0]->message() );
			self::assertSame( array( 'component:Hero' ), $this->result_identities( $report->skipped_results() ) );
			self::assertFalse( $report->results()[0]->is_applied() );
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
			self::assertSame( array(), $report->applied_results() );
			self::assertSame( 0, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

		public function test_invalid_ownership_reference_blocks_all_writes(): void {
			$store = new CountingSitePersistenceStore();
			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:hero', array( 'css' => 'color:red;' ) );
			$plan  = CompiledSitePlan::from_sections(
				styles: array( $style ),
				ownership: array( CompiledSiteOwnership::new( 'component:Missing', 'style:hero', 'style' ) )
			);

			$report = ( new InMemorySitePersistence( $store ) )->apply( $plan );

			self::assertFalse( $report->is_success() );
			self::assertTrue( $report->was_blocked() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_OWNERSHIP_INVALID', $report->blocking_diagnostics()[0]->code() );
			self::assertSame( 0, $store->find_calls );
		}

		public function test_catalog_is_reported_as_non_runtime_failure_instead_of_silent_success(): void {
			$catalog = CompiledSiteEntity::new(
				CompiledSiteEntityType::COMPONENT_CONTRACT_CATALOG,
				'component_contract_catalog:default',
				array( 'components' => array() )
			);

			$report = ( new InMemorySitePersistence() )->apply( CompiledSitePlan::from_sections( entities: array( $catalog ) ) );

			self::assertFalse( $report->is_success() );
			self::assertSame( 'failed', $report->results()[0]->outcome()->value );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_CATALOG_NOT_RUNTIME', $report->results()[0]->code() );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_loop_preset_uses_native_id_key_while_preserving_its_reference_key(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_loops' => array( 'foreign' => array( 'key' => 'foreign', 'config' => array( 'type' => 'main-query' ) ) ),
			);
			$loop = CompiledSiteEntity::new(
				CompiledSiteEntityType::LOOP_PRESET,
				'loop_preset:recent-posts',
				array( 'id' => 'recent-id', 'name' => 'Recent Posts', 'key' => 'recent-posts', 'global' => true, 'config' => array( 'type' => 'main-query', 'args' => array() ) )
			);

			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $loop ) ) );
			$again       = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $loop ) ) );

			self::assertSame( array( 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $again ) );
			/** @var array<string, mixed> $loops */
			$loops = $GLOBALS['etch_builders_site_persistence_options']['etch_loops'];
			self::assertArrayHasKey( 'recent-id', $loops );
			self::assertIsArray( $loops['recent-id'] );
			self::assertArrayNotHasKey( 'id', $loops['recent-id'] );
			self::assertArrayHasKey( 'foreign', $loops );

			$loops['recent-id']['_preset_hash'] = 'etch-owned-metadata';
			$GLOBALS['etch_builders_site_persistence_options']['etch_loops'] = $loops;
			$with_etch_metadata = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $loop ) ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $with_etch_metadata ) );

			$drifted_loop = CompiledSiteEntity::new(
				CompiledSiteEntityType::LOOP_PRESET,
				'loop_preset:recent-posts',
				array(
					'name'   => 'Recent Posts',
					'key'    => 'recent-posts',
					'global' => true,
					'config' => array( 'type' => 'main-query', 'args' => array( 'post_type' => 'post' ) ),
					'id'     => 'recent-id',
				)
			);
			$repaired = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $drifted_loop ) ) );
			self::assertSame( array( 'updated' ), $this->outcomes( $repaired ) );
			self::assertSame( array( 'post_type' => 'post' ), $GLOBALS['etch_builders_site_persistence_options']['etch_loops']['recent-id']['config']['args'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_wordpress_adapter_verifies_the_exact_etch_native_loop_without_mutating_metadata(): void {
			$this->install_wordpress_option_stubs();
			$native = array(
				'name'    => 'Posts',
				'key'     => 'posts',
				'global'  => true,
				'config'  => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post' ) ),
				'_preset_hash' => 'etch-owned-metadata',
			);
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_loops' => array( 'k7mrbkq' => $native ),
			);
			$GLOBALS['etch_builders_site_persistence_write_calls'] = array();
			$entity = $this->native_loop_entity();

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertTrue( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_VERIFIED', $report->results()[0]->code() );
			self::assertSame( $native, $GLOBALS['etch_builders_site_persistence_options']['etch_loops']['k7mrbkq'] );
			self::assertArrayNotHasKey( 'etch_builders_site_persistence_entities', $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_write_calls'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_wordpress_adapter_rejects_ambiguous_native_loop_keys_without_mutation(): void {
			$this->install_wordpress_option_stubs();
			$native = array(
				'name'   => 'Posts',
				'key'    => 'posts',
				'global' => true,
				'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post' ) ),
			);
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_loops' => array( 'first' => $native, 'second' => $native ),
			);
			$GLOBALS['etch_builders_site_persistence_write_calls'] = array();
			$entity = $this->native_loop_entity();

			$report = ( new WordPressSitePersistence() )->apply(
				CompiledSitePlan::from_sections( entities: array( $entity ) )
			);

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_MISSING', $report->results()[0]->code() );
			self::assertSame( array( 'first' => $native, 'second' => $native ), $GLOBALS['etch_builders_site_persistence_options']['etch_loops'] );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_write_calls'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_wordpress_adapter_rejects_wrong_native_id_and_kind_without_mutation(): void {
			$this->install_wordpress_option_stubs();
			$native = array(
				'name'   => 'Posts',
				'key'    => 'posts',
				'global' => true,
				'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post' ) ),
			);
			$entity = $this->native_loop_entity();

			foreach ( array(
				'wrong-id'   => $native,
				'k7mrbkq'    => array_merge( $native, array( 'config' => array( 'type' => 'main-query', 'args' => array() ) ) ),
			) as $option_key => $stored_native ) {
				$GLOBALS['etch_builders_site_persistence_options'] = array( 'etch_loops' => array( $option_key => $stored_native ) );
				$GLOBALS['etch_builders_site_persistence_write_calls'] = array();
				$before = $GLOBALS['etch_builders_site_persistence_options'];

				$report = ( new WordPressSitePersistence() )->apply(
					CompiledSitePlan::from_sections( entities: array( $entity ) )
				);

				self::assertFalse( $report->is_success() );
				self::assertSame( 'ETCH_SITE_PERSISTENCE_NATIVE_DRIFT', $report->results()[0]->code(), $option_key );
				self::assertSame( $before, $GLOBALS['etch_builders_site_persistence_options'], $option_key );
				self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_write_calls'], $option_key );
			}
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_post_handler_rechecks_the_native_post_type_before_writing(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$post = CompiledSiteEntity::new(
				CompiledSiteEntityType::POST,
				'post:book:article',
				array( 'post_type' => 'book', 'slug' => 'article', 'post_title' => 'Article', 'blocks' => '<!-- wp:etch/text -->article<!-- /wp:etch/text -->' )
			);
			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $post ) ) );
			$again       = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $post ) ) );

			self::assertSame( array( 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( 'book', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_type );
			self::assertSame( 'Article', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_title );

			$GLOBALS['etch_builders_site_persistence_posts'][1]->post_type = 'post';
			$recreated = $persistence->apply( CompiledSitePlan::from_sections( entities: array( $post ) ) );

			self::assertTrue( $recreated->is_success() );
			self::assertSame( 'created', $recreated->results()[0]->outcome()->value );
			self::assertSame( 'post', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_type );
			self::assertSame( 'book', $GLOBALS['etch_builders_site_persistence_posts'][2]->post_type );
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
			self::assertCount( 2, $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertCount( 1, $GLOBALS['etch_builders_site_persistence_posts'] );
			/** @var array<int, array<string, mixed>> $meta */
			$meta = $GLOBALS['etch_builders_site_persistence_meta'];
			self::assertSame( 'Hero', $meta[1]['etch_component_html_key'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_default_wordpress_adapter_persists_pages_templates_and_home_policy_natively(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();
			$GLOBALS['etch_builders_site_persistence_taxonomy_available'] = true;

			$page = CompiledSiteEntity::new(
				CompiledSiteEntityType::PAGE,
				'page:slug:home',
				array( 'slug' => 'home', 'blocks' => '<!-- wp:etch/text -->home<!-- /wp:etch/text -->', 'post_title' => 'Home', 'post_status' => 'publish' )
			);
			$template = CompiledSiteEntity::new(
				CompiledSiteEntityType::TEMPLATE,
				'template:slug:index',
				array( 'slug' => 'index', 'blocks' => '<!-- wp:etch/text -->index<!-- /wp:etch/text -->' )
			);
			$plan = CompiledSitePlan::from_sections(
				entities: array( $page, $template ),
				home_page_policy: SiteHomePolicy::page( 'home' )
			);

			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( $plan );
			$again       = $persistence->apply( $plan );

			self::assertSame( array( 'created', 'created', 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged', 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( 'page', $GLOBALS['etch_builders_site_persistence_posts'][1]->post_type );
			self::assertSame( 'wp_template', $GLOBALS['etch_builders_site_persistence_posts'][2]->post_type );
			self::assertSame( 'page', $GLOBALS['etch_builders_site_persistence_options']['show_on_front'] );
			self::assertSame( 1, $GLOBALS['etch_builders_site_persistence_options']['page_on_front'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_native_styles_and_stylesheet_assets_preserve_plan_ownership(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:hero', array( 'selector' => '.hero', 'css' => 'color:red;' ) );
			$asset = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:stylesheet:site:root:global:hash',
				array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { color: red; }' )
			);
			$ownership = array(
				CompiledSiteOwnership::new( 'site:root', 'style:hero', 'style' ),
				CompiledSiteOwnership::new( 'site:root', $asset->identity(), 'stylesheet' ),
			);
			$plan = CompiledSitePlan::from_sections( styles: array( $style ), assets: array( $asset ), ownership: $ownership );

			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( $plan );
			$again       = $persistence->apply( $plan );

			self::assertSame( array( 'created', 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertSame( 'color:red;', $GLOBALS['etch_builders_site_persistence_options']['etch_styles']['hero']['css'] );
			self::assertSame( "body { color: red; }\n", $GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['global']['css'] );
			$stored = $GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_persistence_resources'][ $asset->identity() ];
			self::assertSame( array( 'owner' => 'site:root', 'resource' => $asset->identity(), 'role' => 'stylesheet' ), $stored['ownership'][0] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_recorded_style_orphans_are_deleted_but_unrecorded_native_styles_survive(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_styles' => array(
					'external' => array(
						'selector'   => '.external',
						'collection' => 'User styles',
						'css'        => 'color: blue;',
						'type'       => 'class',
					),
				),
			);
			$GLOBALS['etch_builders_site_persistence_posts'] = array();
			$GLOBALS['etch_builders_site_persistence_meta']  = array();

			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:stale', array( 'selector' => '.stale', 'css' => 'color:red;', 'type' => 'class' ) );
			$plan  = CompiledSitePlan::from_sections(
				styles: array( $style ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $style->identity(), 'style' ) )
			);
			$persistence = new WordPressSitePersistence();

			self::assertSame( array( 'created' ), $this->outcomes( $persistence->apply( $plan ) ) );
			$removed = $persistence->apply( CompiledSitePlan::empty() );

			self::assertSame( array(), $this->outcomes( $removed ) );
			self::assertArrayNotHasKey( 'stale', $GLOBALS['etch_builders_site_persistence_options']['etch_styles'] );
			self::assertArrayHasKey( 'external', $GLOBALS['etch_builders_site_persistence_options']['etch_styles'] );
			self::assertArrayNotHasKey( $style->identity(), $GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_persistence_resources'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_recorded_style_cleanup_releases_a_user_modified_native_style_without_deleting_it(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:mutable', array( 'selector' => '.mutable', 'css' => 'color:red;', 'type' => 'class' ) );
			$plan  = CompiledSitePlan::from_sections(
				styles: array( $style ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $style->identity(), 'style' ) )
			);
			$persistence = new WordPressSitePersistence();

			$persistence->apply( $plan );
			$GLOBALS['etch_builders_site_persistence_options']['etch_styles']['mutable']['css'] = 'user changed';
			$removed = $persistence->apply( CompiledSitePlan::empty() );

			self::assertSame( array(), $this->outcomes( $removed ) );
			self::assertSame( 'user changed', $GLOBALS['etch_builders_site_persistence_options']['etch_styles']['mutable']['css'] );
			self::assertArrayNotHasKey( $style->identity(), $GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_persistence_resources'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_recorded_stylesheet_cleanup_removes_only_the_exact_fragment_key(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$asset = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:stylesheet:site:root:global:stale',
				array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { color: red; }' )
			);
			$plan = CompiledSitePlan::from_sections(
				assets: array( $asset ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $asset->identity(), 'stylesheet' ) )
			);
			$persistence = new WordPressSitePersistence();
			$persistence->apply( $plan );

			$owned_key = array_key_first( $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'] );
			$GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global']['site:root:foreign'] = array( 'css' => '.foreign { color: blue; }', 'file_path' => '/tmp/foreign.css' );
			$removed = $persistence->apply( CompiledSitePlan::empty() );

			self::assertSame( array(), $this->outcomes( $removed ) );
			self::assertArrayNotHasKey( $owned_key, $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'] );
			self::assertArrayHasKey( 'site:root:foreign', $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'] );
			self::assertSame( ".foreign { color: blue; }\n", $GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['global']['css'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_recorded_stylesheet_cleanup_preserves_a_modified_owned_fragment(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$asset = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:stylesheet:site:root:global:modified',
				array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { color: red; }' )
			);
			$plan = CompiledSitePlan::from_sections(
				assets: array( $asset ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $asset->identity(), 'stylesheet' ) )
			);
			$persistence = new WordPressSitePersistence();
			$persistence->apply( $plan );

			$key = array_key_first( $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'] );
			$GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'][ $key ]['css'] = 'user changed';
			$removed = $persistence->apply( CompiledSitePlan::empty() );

			self::assertSame( array(), $this->outcomes( $removed ) );
			self::assertSame( 'user changed', $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheet_fragments']['global'][ $key ]['css'] );
			self::assertSame( "body { color: red; }\n", $GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['global']['css'] );
			self::assertArrayNotHasKey( $asset->identity(), $GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_persistence_resources'] );
			self::assertArrayNotHasKey( 'global', $GLOBALS['etch_builders_site_persistence_options']['oh_my_id_etch_builder_stylesheets'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_legacy_ownership_migration_requires_an_explicit_exact_plan(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_styles' => array(
					'omide-legacy' => array(
						'selector'   => '.legacy',
						'collection' => 'OhMyIDEtch:Legacy',
						'css'        => 'color:red;',
						'type'       => 'class',
					),
				),
			);
			$GLOBALS['etch_builders_site_persistence_posts'] = array();
			$GLOBALS['etch_builders_site_persistence_meta']  = array();

			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:omide-legacy', array( 'selector' => '.legacy', 'collection' => 'OhMyIDEtch:Legacy', 'css' => 'color:red;', 'type' => 'class' ) );
			$plan  = CompiledSitePlan::from_sections(
				styles: array( $style ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $style->identity(), 'style' ) )
			);
			$persistence = new WordPressSitePersistence();

			$migrated = $persistence->migrate_legacy_ownership( $plan );
			self::assertTrue( $migrated->is_success() );
			self::assertArrayHasKey( $style->identity(), $GLOBALS['etch_builders_site_persistence_options']['etch_builders_site_persistence_resources'] );

			self::assertSame( array(), $this->outcomes( $persistence->apply( CompiledSitePlan::empty() ) ) );
			self::assertArrayNotHasKey( 'omide-legacy', $GLOBALS['etch_builders_site_persistence_options']['etch_styles'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_legacy_ownership_migration_does_not_adopt_an_unmarked_bem_style(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array(
				'etch_styles' => array(
					'stack' => array( 'selector' => '.stack', 'collection' => 'User styles', 'css' => 'display:grid;', 'type' => 'class' ),
				),
			);
			$GLOBALS['etch_builders_site_persistence_posts'] = array();
			$GLOBALS['etch_builders_site_persistence_meta']  = array();

			$style = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:stack', array( 'selector' => '.stack', 'collection' => 'User styles', 'css' => 'display:grid;', 'type' => 'class' ) );
			$plan  = CompiledSitePlan::from_sections(
				styles: array( $style ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $style->identity(), 'style' ) )
			);

			$migrated = ( new WordPressSitePersistence() )->migrate_legacy_ownership( $plan );

			self::assertFalse( $migrated->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_LEGACY_OWNERSHIP_UNPROVEN', $migrated->get_error_code() );
			self::assertArrayNotHasKey( 'etch_builders_site_persistence_resources', $GLOBALS['etch_builders_site_persistence_options'] );
			self::assertArrayHasKey( 'stack', $GLOBALS['etch_builders_site_persistence_options']['etch_styles'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_applying_one_stylesheet_asset_does_not_rewrite_an_unrelated_historical_stylesheet(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$first = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:stylesheet:site:root:global:first',
				array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { color: red; }' )
			);
			$second = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:stylesheet:site:root:editor:second',
				array( 'type' => 'stylesheet', 'id' => 'editor', 'path' => '/tmp/editor.css', 'css' => '.editor { color: blue; }' )
			);

			$persistence = new WordPressSitePersistence();
			$first_plan = CompiledSitePlan::from_sections(
				assets: array( $first ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $first->identity(), 'stylesheet' ) )
			);
			$second_plan = CompiledSitePlan::from_sections(
				assets: array( $second ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $second->identity(), 'stylesheet' ) )
			);

			self::assertSame( array( 'created' ), $this->outcomes( $persistence->apply( $first_plan ) ) );
			$GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['global']['css'] = 'historical native CSS';

			self::assertSame( array( 'created' ), $this->outcomes( $persistence->apply( $second_plan ) ) );
			self::assertSame( 'historical native CSS', $GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['global']['css'] );
			self::assertSame( ".editor { color: blue; }\n", $GLOBALS['etch_builders_site_persistence_options']['etch_global_stylesheets']['editor']['css'] );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_javascript_assets_fail_explicitly_before_an_asset_write(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();
			$GLOBALS['etch_builders_site_persistence_posts']  = array();
			$GLOBALS['etch_builders_site_persistence_meta']   = array();

			$asset = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				'asset:javascript:site:script:hash',
				array( 'type' => 'javascript', 'id' => 'script', 'path' => '/tmp/site.js' )
			);
			$plan = CompiledSitePlan::from_sections(
				assets: array( $asset ),
				ownership: array( CompiledSiteOwnership::new( 'site:root', $asset->identity(), 'global_asset' ) )
			);

			$report = ( new WordPressSitePersistence() )->apply( $plan );

			self::assertFalse( $report->is_success() );
			self::assertSame( 'ETCH_SITE_PERSISTENCE_ASSET_UNSUPPORTED', $report->results()[0]->code() );
			self::assertSame( array(), $GLOBALS['etch_builders_site_persistence_options'] );
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

		/**
		 * @param array<int, SitePersistenceResult> $results
		 * @return array<int, string>
		 */
		private function result_identities( array $results ): array {
			return array_map(
				static fn ( SitePersistenceResult $result ): string => $result->identity(),
				$results
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

		if ( ! function_exists( 'etch_builders_site_persistence_record_write' ) ) {
			function etch_builders_site_persistence_record_write( string $operation ): void {
				if ( ! isset( $GLOBALS['etch_builders_site_persistence_write_calls'] ) || ! is_array( $GLOBALS['etch_builders_site_persistence_write_calls'] ) ) {
					$GLOBALS['etch_builders_site_persistence_write_calls'] = array();
				}

				$GLOBALS['etch_builders_site_persistence_write_calls'][ $operation ] = ( $GLOBALS['etch_builders_site_persistence_write_calls'][ $operation ] ?? 0 ) + 1;
			}
		}

		if ( ! function_exists( 'add_option' ) ) {
			function add_option( string $option, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
				etch_builders_site_persistence_record_write( 'add_option' );
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
				etch_builders_site_persistence_record_write( 'update_option' );
				$GLOBALS['etch_builders_site_persistence_options'][ $option ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			function delete_option( string $option ): bool {
				etch_builders_site_persistence_record_write( 'delete_option' );
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

		if ( ! function_exists( 'get_post' ) ) {
			function get_post( int $post_id ): ?object {
				$post = $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ] ?? null;

				return is_object( $post ) ? $post : null;
			}
		}

		if ( ! function_exists( 'post_type_exists' ) ) {
			function post_type_exists( string $post_type ): bool {
				return in_array( $post_type, array( 'post', 'page', 'wp_template', 'book' ), true );
			}
		}

		if ( ! function_exists( 'get_stylesheet' ) ) {
			function get_stylesheet(): string {
				return 'test-theme';
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
				etch_builders_site_persistence_record_write( 'wp_insert_post' );
				if ( $GLOBALS['etch_builders_site_persistence_throw_insert'] ?? false ) {
					throw new RuntimeException( 'insert failed' );
				}

				$id              = count( $GLOBALS['etch_builders_site_persistence_posts'] ?? array() ) + 1;
				$post_data['ID'] = $id;
				$GLOBALS['etch_builders_site_persistence_posts'][ $id ] = (object) $post_data;

				return $id;
			}
		}

		if ( ! function_exists( 'wp_update_post' ) ) {
			function wp_update_post( array $post_data, bool $wp_error = false ): int {
				etch_builders_site_persistence_record_write( 'wp_update_post' );
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
				etch_builders_site_persistence_record_write( 'wp_delete_post' );
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
				etch_builders_site_persistence_record_write( 'update_post_meta' );
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
				etch_builders_site_persistence_record_write( 'wp_set_object_terms' );
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

		private function native_loop_entity(): CompiledSiteEntity {
			return CompiledSiteEntity::new(
				CompiledSiteEntityType::LOOP_PRESET,
				'loop_preset:posts',
				array(
					'id'     => 'k7mrbkq',
					'name'   => 'Posts',
					'key'    => 'posts',
					'global' => true,
					'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post' ) ),
				),
				CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE
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
	 * Store that explicitly declines one compiled record without treating it as a failure.
	 */
	final class SkippingSitePersistenceStore implements SitePersistenceStoreInterface {

		public function find( string $identity ): ?SitePersistenceRecord {
			return null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_SKIPPED', 'Dev-only entity is not active in this runtime.' );
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			return $this->create( $record );
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

		public int $create_calls = 0;

		public int $update_calls = 0;

		public function find( string $identity ): ?SitePersistenceRecord {
			return $this->records[ $identity ] ?? null;
		}

		public function create( SitePersistenceRecord $record ): RegistrationResult {
			++$this->create_calls;
			$this->records[ $record->identity() ] = $record;
			$this->created[]                     = $record->identity();

			return RegistrationResult::success();
		}

		public function update( SitePersistenceRecord $record ): RegistrationResult {
			++$this->update_calls;
			$this->records[ $record->identity() ] = $record;

			return RegistrationResult::success();
		}
	}
}
