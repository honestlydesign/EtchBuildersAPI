<?php
/**
 * Compatibility adapter tests for existing Site registration entry points.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit {

	use HonestlyDesign\EtchBuilders\Component;
	use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceInterface;
	use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
	use HonestlyDesign\EtchBuilders\CompiledSitePlan;
	use HonestlyDesign\EtchBuilders\Pattern;
	use HonestlyDesign\EtchBuilders\PatternUse;
	use HonestlyDesign\EtchBuilders\RegistrationResult;
	use HonestlyDesign\EtchBuilders\SiteCompatibilityAdapter;
	use HonestlyDesign\EtchBuilders\SiteDefinition;
	use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
	use HonestlyDesign\EtchBuilders\SitePersistenceReport;
	use HonestlyDesign\EtchBuilders\SitePersistenceResult;
	use HonestlyDesign\EtchBuilders\SitePersistenceOutcome;
	use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
	use HonestlyDesign\EtchBuilders\Support\InMemorySitePersistence;
	use PHPUnit\Framework\TestCase;

	/**
	 * Verifies that the temporary bridge composes the existing compiler and
	 * persistence ports without recreating either responsibility.
	 */
	final class SiteCompatibilityAdapterTest extends TestCase {

		public function test_existing_definition_boundary_is_compiled_and_delegated_as_one_plan(): void {
			$pattern = Pattern::new( 'Hero', 'Compatibility pattern.' )
				->key( 'Hero' )
				->blocks( TextBlock::new()->content( 'Hero.' ) );
			$component = Component::new( 'Shell', 'Compatibility component.' )
				->key( 'Shell' )
				->blocks( TextBlock::new()->content( 'Shell.' ) )
				->pattern_use( PatternUse::registered( $pattern ) );
			$definition = SiteDefinition::new()
				->component( $component )
				->pattern( $pattern );
			$persistence = new CapturingSitePersistence();

			$report = SiteCompatibilityAdapter::new( $persistence )->register( $definition );

			self::assertSame( $persistence->report, $report );
			self::assertSame( 1, $persistence->apply_calls );
			self::assertNotNull( $persistence->plan );
			self::assertFalse( $persistence->plan->has_errors() );
			self::assertSame( array( 'component:Shell', 'pattern:Hero' ), $persistence->plan->resolved_identities() );
			self::assertSame( array( 'component:Shell', 'pattern:Hero' ), array_map( static fn ( SitePersistenceResult $result ): string => $result->identity(), $report->results() ) );
		}

		public function test_current_registration_behavior_is_preserved_through_the_bridge(): void {
			$definition = SiteDefinition::new()->pattern(
				Pattern::new( 'Hero', 'Compatibility pattern.' )
					->key( 'Hero' )
					->blocks( TextBlock::new()->content( 'Hero.' ) )
			);
			$adapter = SiteCompatibilityAdapter::new( new InMemorySitePersistence() );

			$created   = $adapter->register( $definition );
			$unchanged = $adapter->register( $definition );

			self::assertTrue( $created->is_success() );
			self::assertSame( array( 'created' ), array_map( static fn ( SitePersistenceResult $result ): string => $result->outcome()->value, $created->results() ) );
			self::assertTrue( $unchanged->is_success() );
			self::assertSame( array( 'unchanged' ), array_map( static fn ( SitePersistenceResult $result ): string => $result->outcome()->value, $unchanged->results() ) );
		}

		public function test_blocking_compilation_diagnostics_are_checked_before_any_store_write(): void {
			$missing_pattern = Pattern::new( 'Missing', 'Missing compatibility pattern.' )
				->key( 'Missing' )
				->blocks( TextBlock::new()->content( 'Missing.' ) );
			$component = Component::new( 'Shell', 'Compatibility component.' )
				->key( 'Shell' )
				->blocks( TextBlock::new()->content( 'Shell.' ) )
				->pattern_use( PatternUse::registered( $missing_pattern ) );
			$store = new NoWriteSitePersistenceStore();

			$report = SiteCompatibilityAdapter::new( new InMemorySitePersistence( $store ) )->register(
				SiteDefinition::new()->component( $component )
			);

			self::assertFalse( $report->is_success() );
			self::assertTrue( $report->was_blocked() );
			self::assertSame( 'ETCH_SITE_PATTERN_MISSING', $report->blocking_diagnostics()[0]->code() );
			self::assertSame( 0, $store->find_calls );
			self::assertSame( 0, $store->create_calls );
			self::assertSame( 0, $store->update_calls );
		}

	}

	/**
	 * Persistence spy that proves the adapter delegates one compiled plan.
	 */
	final class CapturingSitePersistence implements SitePersistenceInterface {

		public int $apply_calls = 0;

		public ?CompiledSitePlan $plan = null;

		public ?SitePersistenceReport $report = null;

		public function apply( CompiledSitePlan $plan ): SitePersistenceReport {
			++$this->apply_calls;
			$this->plan   = $plan;
			$this->report = SitePersistenceReport::new(
				array(
					SitePersistenceResult::new( 'component:Shell', SitePersistenceOutcome::CREATED, 'created', 'created' ),
					SitePersistenceResult::new( 'pattern:Hero', SitePersistenceOutcome::CREATED, 'created', 'created' ),
				)
			);

			return $this->report;
		}
	}

	/**
	 * Store spy proving blocked plans never reach the persistence port.
	 */
	final class NoWriteSitePersistenceStore implements SitePersistenceStoreInterface {

		public int $find_calls = 0;

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
}
