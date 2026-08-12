<?php
/**
 * Ordered deterministic Contract Lab fixture catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Keeps fixture order and logical identities explicit for seed/cleanup.
 */
final class ContractLabFixtureCatalog {

	/**
	 * @param array<int, ContractLabFixtureDefinition> $definitions
	 */
	private function __construct( private readonly array $definitions ) {
	}

	/**
	 * @param array<int, ContractLabFixtureDefinition> $definitions
	 */
	public static function new( array $definitions ): self {
		if ( ! array_is_list( $definitions ) ) {
			throw new InvalidArgumentException( 'Contract Lab fixture catalog must be an ordered list.' );
		}

		$seen = array();
		foreach ( $definitions as $definition ) {
			if ( ! $definition instanceof ContractLabFixtureDefinition ) {
				throw new InvalidArgumentException( 'Contract Lab fixture catalog must contain fixture definitions.' );
			}
			if ( isset( $seen[ $definition->logical_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab fixture catalog contains a duplicate logical identity "%s".', $definition->logical_id() ) );
			}
			$seen[ $definition->logical_id() ] = true;
		}

		return new self( array_values( $definitions ) );
	}

	/**
	 * @return array<int, ContractLabFixtureDefinition>
	 */
	public function definitions(): array {
		return $this->definitions;
	}

	public function find( string $logical_id ): ?ContractLabFixtureDefinition {
		foreach ( $this->definitions as $definition ) {
			if ( $definition->logical_id() === $logical_id ) {
				return $definition;
			}
		}

		return null;
	}
}
