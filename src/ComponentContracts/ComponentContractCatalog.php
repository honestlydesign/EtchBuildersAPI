<?php
/**
 * Immutable catalog of Etch component authoring contracts.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use InvalidArgumentException;

/**
 * Provides exact component-key lookup over schema-derived contract records.
 */
final class ComponentContractCatalog {

	/**
	 * @var array<int, ComponentContract>
	 */
	private readonly array $contracts;

	/**
	 * @var array<string, ComponentContract>
	 */
	private readonly array $contracts_by_key;

	public static function from_contracts( ComponentContract ...$contracts ): self {
		return new self( array_values( $contracts ) );
	}

	/**
	 * @param array<int, ComponentContract> $contracts Ordered component contracts.
	 */
	private function __construct( array $contracts ) {
		$by_key = array();
		foreach ( $contracts as $contract ) {
			$key = $contract->component_key();
			if ( isset( $by_key[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Component Contract Catalog has duplicate component key "%s".', $key ) );
			}

			$by_key[ $key ] = $contract;
		}

		$this->contracts        = array_values( $contracts );
		$this->contracts_by_key = $by_key;
	}

	public function has( string $component_key ): bool {
		return isset( $this->contracts_by_key[ $component_key ] );
	}

	/**
	 * Require one exact component key.
	 *
	 * @throws InvalidArgumentException When the key is absent.
	 */
	public function contract( string $component_key ): ComponentContract {
		if ( ! isset( $this->contracts_by_key[ $component_key ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Component Contract Catalog has no component key "%s".', $component_key ) );
		}

		return $this->contracts_by_key[ $component_key ];
	}

	/**
	 * @return array<int, ComponentContract>
	 */
	public function all(): array {
		return $this->contracts;
	}

	/**
	 * Return deterministic machine-readable catalog data.
	 *
	 * @return array{components: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'components' => array_map(
				static fn ( ComponentContract $contract ): array => $contract->to_array(),
				$this->contracts
			),
		);
	}
}
