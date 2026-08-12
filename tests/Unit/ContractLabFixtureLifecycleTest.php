<?php
/**
 * Deterministic Contract Lab fixture lifecycle tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabBindingResolution;
use HonestlyDesign\EtchBuilders\ContractLabDoctorResult;
use HonestlyDesign\EtchBuilders\ContractLabFixtureCatalog;
use HonestlyDesign\EtchBuilders\ContractLabFixtureDefinition;
use HonestlyDesign\EtchBuilders\ContractLabFixtureException;
use HonestlyDesign\EtchBuilders\ContractLabFixtureLifecycle;
use HonestlyDesign\EtchBuilders\ContractLabFixtureRecord;
use HonestlyDesign\EtchBuilders\ContractLabFixtureScope;
use HonestlyDesign\EtchBuilders\ContractLabLock;
use HonestlyDesign\EtchBuilders\Support\InMemoryContractLabFixtureStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves that fixture mutation is explicit, deterministic, and fail-closed.
 */
final class ContractLabFixtureLifecycleTest extends TestCase {

	private ContractLabFixtureScope $scope;

	private ContractLabLock $lock;

	private string $lock_path;

	protected function setUp(): void {
		$this->lock_path = sys_get_temp_dir() . '/contract-lab-fixtures-' . uniqid( '', true ) . '/lock.json';
		$this->lock      = ContractLabLock::acquire( $this->lock_path, 900, 1000 );
		$this->scope     = ContractLabFixtureScope::new(
			ContractLabBindingResolution::verified(
				'jTAefG5iA',
				'http://etch-builders-contract-lab.local',
				'/tmp/contract-lab/app/public',
				'wp',
				'marker-123',
				array(
					array( 'kind' => 'wordpress-site', 'identity' => 'jTAefG5iA' ),
					array( 'kind' => 'marker-option', 'identity' => ContractLabBinding::MARKER_OPTION ),
					array( 'kind' => 'fixture-namespace', 'identity' => ContractLabBinding::FIXTURE_NAMESPACE ),
				)
			),
			ContractLabDoctorResult::from_findings( array() ),
			$this->lock
		);
	}

	protected function tearDown(): void {
		$this->lock->release();
		if ( is_file( $this->lock_path ) ) {
			unlink( $this->lock_path );
		}
		$directory = dirname( $this->lock_path );
		if ( is_dir( $directory ) ) {
			rmdir( $directory );
		}
	}

	public function test_catalog_is_canonical_and_runtime_identity_is_symbolized(): void {
		$definition = $this->definition();
		$catalog    = ContractLabFixtureCatalog::new( array( $definition ) );

		self::assertSame(
			array(
				'fixture_version' => '1',
				'namespace'       => ContractLabBinding::FIXTURE_NAMESPACE,
				'logical_id'      => 'document-home',
				'kind'            => 'document',
				'payload'         => array(
					'title'   => 'Contract Lab Home',
					'content' => '<!-- wp:paragraph --><p>Contract Lab</p><!-- /wp:paragraph -->',
				),
				'fingerprint'     => $definition->fingerprint(),
			),
			$definition->to_array()
		);
		self::assertSame( $definition, $catalog->find( 'document-home' ) );

		$run = ContractLabFixtureLifecycle::seed( $this->scope, new InMemoryContractLabFixtureStore(), $catalog );
		self::assertSame( 'seeded', $run->status() );
		self::assertSame(
			array(
				'record_version' => '1',
				'namespace'      => ContractLabBinding::FIXTURE_NAMESPACE,
				'owner'          => ContractLabBinding::FIXTURE_NAMESPACE,
				'logical_id'     => 'document-home',
				'kind'           => 'document',
				'symbolic_id'    => 'fixture:document-home',
				'symbolic_url'   => 'fixture-url:document-home',
				'payload_digest' => $definition->fingerprint(),
			),
			$run->to_array()['fixtures'][0]
		);
		self::assertStringNotContainsString( '100', json_encode( $run->to_array(), JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'etch-builders-contract-lab.local', json_encode( $run->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_seed_is_idempotent_and_preserves_generated_resource_mapping(): void {
		$store   = new InMemoryContractLabFixtureStore();
		$catalog = ContractLabFixtureCatalog::new( array( $this->definition() ) );

		$first  = ContractLabFixtureLifecycle::seed( $this->scope, $store, $catalog );
		$second = ContractLabFixtureLifecycle::seed( $this->scope, $store, $catalog );

		self::assertSame( $first->to_array(), $second->to_array() );
		self::assertCount( 1, $store->records( $this->scope ) );
		self::assertSame( '100', $store->records( $this->scope )[0]->resource_id() );
		self::assertSame( 'http://etch-builders-contract-lab.local/contract-fixture/document-home', $store->records( $this->scope )[0]->resource_url() );
	}

	public function test_cleanup_deletes_only_known_explicitly_owned_fixtures(): void {
		$store = new InMemoryContractLabFixtureStore();
		$store->seed( $this->record( '100', 'http://etch-builders-contract-lab.local/contract-fixture/document-home' ) );
		$store->seed(
			ContractLabFixtureRecord::from_values(
				'other-namespace',
				'other-owner',
				'marker-foreign',
				'unrelated-record',
				'document',
				'900',
				'http://etch-builders-contract-lab.local/unrelated',
				'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
			)
		);

		$catalog = ContractLabFixtureCatalog::new( array( $this->definition() ) );
		$run     = ContractLabFixtureLifecycle::cleanup( $this->scope, $store, $catalog );

		self::assertSame( 'cleaned', $run->status() );
		self::assertSame( array( 'document-home' ), array_map( static fn ( ContractLabFixtureRecord $record ): string => $record->logical_id(), $run->records() ) );
		self::assertCount( 1, $store->records( $this->scope ) );
		self::assertSame( 'unrelated-record', $store->records( $this->scope )[0]->logical_id() );
	}

	/**
	 * @dataProvider unsafe_lifecycle_provider
	 * @param callable(ContractLabFixtureScope, InMemoryContractLabFixtureStore, ContractLabFixtureCatalog): void $operation
	 */
	public function test_seed_and_cleanup_refuse_unknown_or_modified_owned_state( callable $operation, string $message ): void {
		$store   = new InMemoryContractLabFixtureStore();
		$catalog = ContractLabFixtureCatalog::new( array( $this->definition() ) );

		$this->expectException( ContractLabFixtureException::class );
		$this->expectExceptionMessage( $message );
		$operation( $this->scope, $store, $catalog );
	}

	/**
	 * @return array<string, array{callable(ContractLabFixtureScope, InMemoryContractLabFixtureStore, ContractLabFixtureCatalog): void, string}>
	 */
	public function unsafe_lifecycle_provider(): array {
		return array(
			'unknown owned logical identity' => array(
				function ( ContractLabFixtureScope $scope, InMemoryContractLabFixtureStore $store, ContractLabFixtureCatalog $catalog ): void {
					$store->seed(
						ContractLabFixtureRecord::new(
							ContractLabFixtureDefinition::new( 'unknown-owned', 'document', array( 'title' => 'Unknown' ) ),
							'marker-123',
							'101',
							'http://etch-builders-contract-lab.local/contract-fixture/unknown-owned',
							'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
						)
					);
					ContractLabFixtureLifecycle::cleanup( $scope, $store, $catalog );
				},
				'unknown owned fixture',
			),
			'externally modified fixture' => array(
				function ( ContractLabFixtureScope $scope, InMemoryContractLabFixtureStore $store, ContractLabFixtureCatalog $catalog ): void {
					$store->seed(
						ContractLabFixtureRecord::new(
							ContractLabFixtureDefinition::new( 'document-home', 'document', array( 'title' => 'Contract Lab Home', 'content' => '<!-- wp:paragraph --><p>Contract Lab</p><!-- /wp:paragraph -->' ) ),
							'marker-123',
							'100',
							'http://etch-builders-contract-lab.local/contract-fixture/document-home',
							'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc'
						)
					);
					ContractLabFixtureLifecycle::seed( $scope, $store, $catalog );
				},
				'externally modified',
			),
			'foreign owner' => array(
				function ( ContractLabFixtureScope $scope, InMemoryContractLabFixtureStore $store, ContractLabFixtureCatalog $catalog ): void {
					$definition = ContractLabFixtureDefinition::new( 'document-home', 'document', array( 'title' => 'Contract Lab Home', 'content' => '<!-- wp:paragraph --><p>Contract Lab</p><!-- /wp:paragraph -->' ) );
					$record     = ContractLabFixtureRecord::new( $definition, 'marker-123', '100', 'http://etch-builders-contract-lab.local/contract-fixture/document-home', $definition->fingerprint() );
					$store->seed( ContractLabFixtureRecord::from_values( $record->namespace(), 'another-owner', $record->marker_id(), $record->logical_id(), $record->kind(), $record->resource_id(), $record->resource_url(), $record->payload_fingerprint() ) );
					ContractLabFixtureLifecycle::cleanup( $scope, $store, $catalog );
				},
				'ownership',
			),
		);
	}

	public function test_invalid_catalog_and_record_shapes_fail_before_mutation(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate logical identity' );
		ContractLabFixtureCatalog::new( array( $this->definition(), $this->definition() ) );
	}

	private function definition(): ContractLabFixtureDefinition {
		return ContractLabFixtureDefinition::new(
			'document-home',
			'document',
			array(
				'title'   => 'Contract Lab Home',
				'content' => '<!-- wp:paragraph --><p>Contract Lab</p><!-- /wp:paragraph -->',
			)
		);
	}

	private function record( string $resource_id, string $resource_url, ?string $payload_fingerprint = null ): ContractLabFixtureRecord {
		$definition = $this->definition();

		return ContractLabFixtureRecord::new(
			$definition,
			'marker-123',
			$resource_id,
			$resource_url,
			$payload_fingerprint ?? $definition->fingerprint()
		);
	}
}
