<?php
/**
 * Fail-closed deterministic Contract Lab fixture lifecycle.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\ContractLabFixtureStoreInterface;

/**
 * Coordinates explicit fixture ownership without guessing from names,
 * prefixes, IDs, URLs, or unrelated WordPress state.
 */
final class ContractLabFixtureLifecycle {

	private function __construct() {
	}

	/**
	 * Seed each catalog definition once and retain existing generated mappings.
	 */
	public static function seed( ContractLabFixtureScope $scope, ContractLabFixtureStoreInterface $store, ContractLabFixtureCatalog $catalog ): ContractLabFixtureRun {
		$scope->assert_active();
		$existing = self::index_and_validate( $scope, $store->records( $scope ), $catalog );
		$records  = array();

		foreach ( $catalog->definitions() as $definition ) {
			$logical_id = $definition->logical_id();
			if ( isset( $existing[ $logical_id ] ) ) {
				$record = $existing[ $logical_id ];
				if ( ! $record->matches_definition( $definition, $scope->marker_id() ) ) {
					throw new ContractLabFixtureException( 'modified', sprintf( 'Contract Lab fixture "%s" is externally modified.', $logical_id ) );
				}
				$records[] = $record;
				continue;
			}

			$record = $store->create( $scope, $definition );
			self::assert_created_record( $record, $definition, $scope->marker_id() );
			$records[] = $record;
		}

		return ContractLabFixtureRun::new( 'seeded', $records );
	}

	/**
	 * Delete only known, explicitly owned fixtures whose payload is unchanged.
	 */
	public static function cleanup( ContractLabFixtureScope $scope, ContractLabFixtureStoreInterface $store, ContractLabFixtureCatalog $catalog ): ContractLabFixtureRun {
		$scope->assert_active();
		$existing = self::index_and_validate( $scope, $store->records( $scope ), $catalog );
		$deleted  = array();

		foreach ( $existing as $record ) {
			$definition = $catalog->find( $record->logical_id() );
			if ( null === $definition ) {
				throw new ContractLabFixtureException( 'unknown', sprintf( 'Contract Lab cleanup found an unknown owned fixture "%s".', $record->logical_id() ) );
			}
			if ( ! $record->matches_definition( $definition, $scope->marker_id() ) ) {
				throw new ContractLabFixtureException( 'modified', sprintf( 'Contract Lab fixture "%s" is externally modified.', $record->logical_id() ) );
			}
			$deleted[] = $record;
		}

		foreach ( $deleted as $record ) {
			$store->delete( $scope, $record );
		}

		return ContractLabFixtureRun::new( 'cleaned', $deleted );
	}

	/**
	 * @param array<int, ContractLabFixtureRecord> $records
	 * @return array<string, ContractLabFixtureRecord>
	 */
	private static function index_and_validate( ContractLabFixtureScope $scope, array $records, ContractLabFixtureCatalog $catalog ): array {
		if ( ! array_is_list( $records ) ) {
			throw new ContractLabFixtureException( 'invalid', 'Contract Lab fixture store must return an ordered record list.' );
		}

		$indexed = array();
		foreach ( $records as $record ) {
			if ( ! $record instanceof ContractLabFixtureRecord ) {
				throw new ContractLabFixtureException( 'invalid', 'Contract Lab fixture store returned an invalid record.' );
			}
			if ( ContractLabBinding::FIXTURE_NAMESPACE !== $record->namespace() ) {
				continue;
			}
			if ( ! $record->is_explicitly_owned( $scope->marker_id() ) ) {
				throw new ContractLabFixtureException( 'ownership', sprintf( 'Contract Lab fixture "%s" has unproven ownership.', $record->logical_id() ) );
			}
			if ( null === $catalog->find( $record->logical_id() ) ) {
				throw new ContractLabFixtureException( 'unknown', sprintf( 'Contract Lab cleanup found an unknown owned fixture "%s".', $record->logical_id() ) );
			}
			if ( isset( $indexed[ $record->logical_id() ] ) ) {
				throw new ContractLabFixtureException( 'invalid', sprintf( 'Contract Lab fixture store returned duplicate logical identity "%s".', $record->logical_id() ) );
			}
			$indexed[ $record->logical_id() ] = $record;
		}

		return $indexed;
	}

	private static function assert_created_record( ContractLabFixtureRecord $record, ContractLabFixtureDefinition $definition, string $marker_id ): void {
		if ( ! $record->matches_definition( $definition, $marker_id ) ) {
			throw new ContractLabFixtureException( 'invalid', sprintf( 'Contract Lab fixture store returned an unowned or non-deterministic record for "%s".', $definition->logical_id() ) );
		}
	}
}
