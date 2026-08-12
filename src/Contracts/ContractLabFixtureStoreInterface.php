<?php
/**
 * Explicit storage boundary for maintainer-owned Contract Lab fixtures.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ContractLabFixtureDefinition;
use HonestlyDesign\EtchBuilders\ContractLabFixtureRecord;
use HonestlyDesign\EtchBuilders\ContractLabFixtureScope;

/**
 * A store adapter may expose only records carrying explicit fixture metadata.
 * Unrelated WordPress resources must never be returned as fixture records.
 */
interface ContractLabFixtureStoreInterface {

	/**
	 * Return every record carrying the maintainer fixture metadata.
	 *
	 * A malformed or ambiguous owned record must fail closed in the adapter
	 * rather than being omitted from this list.
	 *
	 * @return array<int, ContractLabFixtureRecord>
	 */
	public function records( ContractLabFixtureScope $scope ): array;

	/**
	 * Create exactly one new fixture from its deterministic definition.
	 */
	public function create( ContractLabFixtureScope $scope, ContractLabFixtureDefinition $definition ): ContractLabFixtureRecord;

	/**
	 * Delete exactly the supplied, previously observed record.
	 *
	 * The adapter must re-check resource identity and payload ownership before
	 * deleting the underlying resource.
	 */
	public function delete( ContractLabFixtureScope $scope, ContractLabFixtureRecord $record ): void;
}
