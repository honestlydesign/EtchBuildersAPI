<?php
/**
 * Contract-significant public WordPress block attribute shape.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Projects only the attribute name, admitted type(s), and default value.
 */
final class ContractLabBlockAttributeShape {

	/**
	 * @param array<int, string> $types
	 */
	private function __construct(
		private readonly string $name,
		private readonly array $types,
		private readonly bool $has_default,
		private readonly mixed $default
	) {
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_registry( string $name, array $record ): self {
		if ( '' === $name || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $name ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress block attribute names must be stable identifiers.' );
		}
		$type = $record['type'] ?? null;
		$types = is_string( $type ) ? array( $type ) : $type;
		if ( ! is_array( $types ) || ! array_is_list( $types ) || array() === $types ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress block attribute "%s" must declare a type or type list.', $name ) );
		}

		$normalized_types = array();
		foreach ( $types as $type_name ) {
			if ( ! is_string( $type_name ) || 1 !== preg_match( '/^[a-z][a-z0-9-]*$/D', $type_name ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress block attribute "%s" has an invalid type.', $name ) );
			}
			$normalized_types[ $type_name ] = true;
		}
		$normalized_types = array_keys( $normalized_types );
		sort( $normalized_types );

		$has_default = array_key_exists( 'default', $record );
		$default     = null;
		if ( $has_default ) {
			if ( is_object( $record['default'] ) || is_resource( $record['default'] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress block attribute "%s" default is not persisted data.', $name ) );
			}
			if ( is_array( $record['default'] ) ) {
				AcyclicArrayGuard::assert_acyclic( $record['default'] );
				$default = ImmutableArray::copy( $record['default'], 'WordPress block attribute defaults must contain only persisted data.' );
			} elseif ( is_string( $record['default'] ) || is_int( $record['default'] ) || is_float( $record['default'] ) || is_bool( $record['default'] ) || null === $record['default'] ) {
				$default = $record['default'];
			} else {
				throw new ContractLabObservationException( 'malformed', sprintf( 'WordPress block attribute "%s" default is not persisted data.', $name ) );
			}
		}

		return new self( $name, $normalized_types, $has_default, $default );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		$keys = array_keys( $record );
		sort( $keys );
		$expected = array( 'has_default', 'name', 'types' );
		if ( isset( $record['has_default'] ) && true === $record['has_default'] ) {
			$expected[] = 'default';
		}
		sort( $expected );
		if ( $keys !== $expected || ! is_string( $record['name'] ?? null ) || ! is_array( $record['types'] ?? null ) || ! is_bool( $record['has_default'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime shape block attribute has an invalid field set.' );
		}

		$input = array( 'type' => $record['types'] );
		if ( true === $record['has_default'] ) {
			$input['default'] = $record['default'];
		}
		$attribute = self::from_registry( $record['name'], $input );
		if ( $attribute->to_array() !== $record ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime shape block attribute "%s" is not canonical.', $record['name'] ) );
		}

		return $attribute;
	}

	/**
	 * @return array{name: string, types: array<int, string>, has_default: bool, default?: mixed}
	 */
	public function to_array(): array {
		$record = array(
			'name'        => $this->name,
			'types'       => $this->types,
			'has_default' => $this->has_default,
		);
		if ( $this->has_default ) {
			$record['default'] = $this->default;
		}

		return $record;
	}
}
