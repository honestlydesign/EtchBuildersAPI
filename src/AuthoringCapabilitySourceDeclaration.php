<?php
/**
 * Source symbol selections for one Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Keeps the hand-curated source boundary separate from generated interface facts.
 */
final class AuthoringCapabilitySourceDeclaration {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	/**
	 * @param array<int, AuthoringCapabilitySourceSymbol> $symbols
	 */
	private function __construct(
		private readonly string $capability_id,
		private readonly array $symbols
	) {
	}

	/**
	 * Declare the exact source symbols for one capability.
	 */
	public static function for_capability(
		string $capability_id,
		AuthoringCapabilitySourceSymbol ...$symbols
	): self {
		$capability_id = trim( $capability_id );
		if ( 1 !== preg_match( self::ID_PATTERN, $capability_id ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source capability ID "%s" must be stable.', $capability_id ) );
		}

		if ( array() === $symbols ) {
			throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" requires at least one source symbol.', $capability_id ) );
		}

		$seen = array();
		foreach ( $symbols as $symbol ) {
			if ( isset( $seen[ $symbol->identity() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" has duplicate source symbol "%s".', $capability_id, $symbol->identity() ) );
			}

			$seen[ $symbol->identity() ] = true;
		}

		return new self( $capability_id, array_values( $symbols ) );
	}

	/**
	 * Rehydrate a source declaration without accepting generated signatures.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'id', 'symbols' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring source declaration must contain exactly id and symbols.' );
		}

		if ( ! is_string( $record['id'] ) || ! is_array( $record['symbols'] ) || ! array_is_list( $record['symbols'] ) ) {
			throw new InvalidArgumentException( 'Authoring source declaration has invalid field shapes.' );
		}

		$symbols = array();
		foreach ( $record['symbols'] as $symbol_record ) {
			if ( ! is_array( $symbol_record ) ) {
				throw new InvalidArgumentException( 'Authoring source declaration symbols must be object records.' );
			}

			$symbols[] = AuthoringCapabilitySourceSymbol::from_array( $symbol_record );
		}

		return self::for_capability( $record['id'], ...$symbols );
	}

	public function capability_id(): string {
		return $this->capability_id;
	}

	/**
	 * @return array<int, AuthoringCapabilitySourceSymbol>
	 */
	public function symbols(): array {
		return $this->symbols;
	}

	/**
	 * @return array{id: string, symbols: array<int, array{class: string, method: string}>}
	 */
	public function to_array(): array {
		return array(
			'id'      => $this->capability_id,
			'symbols' => array_map(
				static fn ( AuthoringCapabilitySourceSymbol $symbol ): array => $symbol->to_array(),
				$this->symbols
			),
		);
	}
}
