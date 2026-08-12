<?php
/**
 * Deterministic in-memory Contract Lab fixture store for pure tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabFixtureDefinition;
use HonestlyDesign\EtchBuilders\ContractLabFixtureException;
use HonestlyDesign\EtchBuilders\ContractLabFixtureRecord;
use HonestlyDesign\EtchBuilders\ContractLabFixtureScope;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabFixtureStoreInterface;

/**
 * Models the exact create/delete boundary without WordPress or LocalWP.
 */
final class InMemoryContractLabFixtureStore implements ContractLabFixtureStoreInterface {

	/** @var array<string, ContractLabFixtureRecord> */
	private array $records = array();

	private int $next_resource_id = 100;

	/**
	 * @return array<int, ContractLabFixtureRecord>
	 */
	public function records( ContractLabFixtureScope $scope ): array {
		$scope->assert_active();
		return array_values( $this->records );
	}

	public function create( ContractLabFixtureScope $scope, ContractLabFixtureDefinition $definition ): ContractLabFixtureRecord {
		$scope->assert_active();
		$key = $this->key( $definition->logical_id() );
		if ( isset( $this->records[ $key ] ) ) {
			throw new ContractLabFixtureException( 'conflict', sprintf( 'Contract Lab fixture "%s" already exists.', $definition->logical_id() ) );
		}

		$resource_id  = (string) $this->next_resource_id++;
		$resource_url = 'http://etch-builders-contract-lab.local/contract-fixture/' . $definition->logical_id();
		$record       = ContractLabFixtureRecord::new( $definition, $scope->marker_id(), $resource_id, $resource_url, $definition->fingerprint() );
		$this->records[ $key ] = $record;

		return $record;
	}

	public function delete( ContractLabFixtureScope $scope, ContractLabFixtureRecord $record ): void {
		$scope->assert_active();
		$key     = $this->key( $record->logical_id() );
		$current = $this->records[ $key ] ?? null;
		if ( null === $current || $current->to_array() !== $record->to_array() ) {
			throw new ContractLabFixtureException( 'modified', sprintf( 'Contract Lab fixture "%s" changed before cleanup.', $record->logical_id() ) );
		}

		unset( $this->records[ $key ] );
	}

	/**
	 * Seed adapter state to model existing or externally changed resources.
	 */
	public function seed( ContractLabFixtureRecord $record ): void {
		$this->records[ $this->key( $record->logical_id() ) ] = $record;
	}

	private function key( string $logical_id ): string {
		return ContractLabBinding::FIXTURE_NAMESPACE . ':' . $logical_id;
	}
}
