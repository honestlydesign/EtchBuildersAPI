<?php
/**
 * Contract-significant public WordPress block shape.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Keeps required block attributes deterministic while excluding callbacks,
 * implementation classes, descriptions, and unrelated registry fields.
 */
final class ContractLabBlockShape {

	/**
	 * @param array<int, ContractLabBlockAttributeShape> $attributes
	 */
	private function __construct(
		private readonly string $name,
		private readonly array $attributes
	) {
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_registry( array $record ): self {
		$name       = $record['name'] ?? null;
		$attributes = $record['attributes'] ?? null;
		if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D', $name ) || ! is_array( $attributes ) || ( array() !== $attributes && array_is_list( $attributes ) ) ) {
			throw new ContractLabObservationException( 'malformed', 'Public block registry records must contain a namespaced name and attribute object.' );
		}

		$normalized = array();
		foreach ( $attributes as $attribute_name => $attribute_record ) {
			if ( ! is_string( $attribute_name ) || ! is_array( $attribute_record ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Public block "%s" has a malformed attribute record.', $name ) );
			}
			$normalized[] = ContractLabBlockAttributeShape::from_registry( $attribute_name, $attribute_record );
		}
		usort( $normalized, static fn ( ContractLabBlockAttributeShape $left, ContractLabBlockAttributeShape $right ): int => strcmp( $left->to_array()['name'], $right->to_array()['name'] ) );

		return new self( $name, $normalized );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'attributes', 'name' ) !== $keys || ! is_string( $record['name'] ?? null ) || ! is_array( $record['attributes'] ?? null ) || ! array_is_list( $record['attributes'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime shape block has an invalid field set.' );
		}

		$attributes = array_map(
			static function ( mixed $attribute ): ContractLabBlockAttributeShape {
				if ( ! is_array( $attribute ) ) {
					throw new ContractLabObservationException( 'malformed', 'Runtime shape block attributes must be records.' );
				}
				return ContractLabBlockAttributeShape::from_array( $attribute );
			},
			$record['attributes']
		);
		$canonical = new self( $record['name'], $attributes );
		if ( $canonical->to_array() !== $record ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime shape block "%s" is not canonical.', $record['name'] ) );
		}

		return $canonical;
	}

	public function name(): string {
		return $this->name;
	}

	/**
	 * @return array{ name: string, attributes: array<int, array<string, mixed>> }
	 */
	public function to_array(): array {
		return array(
			'name'       => $this->name,
			'attributes' => array_map( static fn ( ContractLabBlockAttributeShape $attribute ): array => $attribute->to_array(), $this->attributes ),
		);
	}
}
