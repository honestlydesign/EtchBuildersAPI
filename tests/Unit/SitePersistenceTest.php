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
		public function test_default_wordpress_adapter_persists_through_its_isolated_option_store(): void {
			$this->install_wordpress_option_stubs();
			$GLOBALS['etch_builders_site_persistence_options'] = array();

			$persistence = new WordPressSitePersistence();
			$created     = $persistence->apply( $this->plan() );
			$again       = $persistence->apply( $this->plan() );

			self::assertSame( array( 'created', 'created' ), $this->outcomes( $created ) );
			self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $again ) );
			self::assertCount( 2, $GLOBALS['etch_builders_site_persistence_options'] );
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
	}
PHP
			);
		}

		private function entity( string $content ): CompiledSiteEntity {
			return CompiledSiteEntity::new(
				CompiledSiteEntityType::COMPONENT,
				'component:Hero',
				array( 'blocks' => '<!-- wp:etch/text -->' . $content . '<!-- /wp:etch/text -->' )
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
