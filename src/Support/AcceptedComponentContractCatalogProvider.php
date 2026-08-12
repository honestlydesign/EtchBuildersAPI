<?php
/**
 * Accepted Component Contract Catalog provider.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Eagerly validates an accepted array or JSON projection and snapshots the model.
 */
final class AcceptedComponentContractCatalogProvider implements ComponentContractCatalogProviderInterface {

	private function __construct( private readonly ComponentContractCatalog $catalog ) {
	}

	/**
	 * @param array<string, mixed> $accepted_catalog Accepted catalog projection.
	 */
	public static function from_array( array $accepted_catalog ): self {
		return new self( ComponentContractCatalog::from_array( $accepted_catalog ) );
	}

	public static function from_json( string $accepted_catalog_json ): self {
		if ( '' === trim( $accepted_catalog_json ) ) {
			throw new InvalidArgumentException( 'Accepted catalog must be a valid JSON object.' );
		}

		try {
			$decoded_object = json_decode( $accepted_catalog_json, false, 512, JSON_THROW_ON_ERROR );
			$lossless_object = json_decode(
				$accepted_catalog_json,
				false,
				512,
				JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING
			);
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException( 'Accepted catalog must be a valid JSON object.', 0, $exception );
		}
		self::assert_no_lossy_integers( $decoded_object, $lossless_object );

		if ( ! $decoded_object instanceof stdClass || ! property_exists( $decoded_object, 'components' ) ) {
			throw new InvalidArgumentException( 'Accepted catalog must be a JSON object with a components field.' );
		}
		self::assert_json_list_shapes( $decoded_object );

		$accepted_catalog = self::json_value_to_php( $decoded_object );
		if ( ! is_array( $accepted_catalog ) ) {
			throw new InvalidArgumentException( 'Accepted catalog must be a JSON object with a components field.' );
		}

		$provider = self::from_array( $accepted_catalog );

		try {
			$canonical_json = json_encode(
				$provider->catalog()->to_array(),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
			$canonical_object = json_decode( $canonical_json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException( 'Accepted catalog canonical JSON comparison failed.', 0, $exception );
		}

		if ( ! self::json_values_equal( $decoded_object, $canonical_object ) ) {
			throw new InvalidArgumentException(
				'Accepted catalog JSON must match the canonical model projection without object/list substitutions.'
			);
		}

		return $provider;
	}

	public function catalog(): ComponentContractCatalog {
		return $this->catalog;
	}

	/**
	 * Convert decoded JSON without collapsing a sequential-key object into a PHP list.
	 */
	private static function json_value_to_php( mixed $value ): mixed {
		if ( $value instanceof stdClass ) {
			$fields = get_object_vars( $value );
			$keys   = array_keys( $fields );

			if ( self::has_sequential_numeric_object_keys( $keys ) ) {
				uksort(
					$fields,
					static fn ( string|int $left, string|int $right ): int => (int) $right <=> (int) $left
				);
			}

			$result = array();
			foreach ( $fields as $key => $item ) {
				$result[ $key ] = self::json_value_to_php( $item );
			}

			return $result;
		}

		if ( is_array( $value ) ) {
			return array_map( self::json_value_to_php( ... ), $value );
		}

		return $value;
	}

	/**
	 * @param array<int, string|int> $keys JSON object member keys.
	 */
	private static function has_sequential_numeric_object_keys( array $keys ): bool {
		if ( count( $keys ) < 2 ) {
			return false;
		}

		$numeric_keys = array();
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( ! ctype_digit( $key ) || (string) (int) $key !== $key ) {
				return false;
			}
			$numeric_keys[] = (int) $key;
		}

		sort( $numeric_keys );

		return range( 0, count( $numeric_keys ) - 1 ) === $numeric_keys;
	}

	/**
	 * Reject JSON integers that the current PHP runtime decoded as rounded floats.
	 */
	private static function assert_no_lossy_integers( mixed $decoded, mixed $lossless ): void {
		if ( is_float( $decoded ) && is_string( $lossless ) ) {
			throw new InvalidArgumentException(
				'Accepted catalog JSON contains an integer outside the exact PHP integer range.'
			);
		}

		if ( $decoded instanceof stdClass && $lossless instanceof stdClass ) {
			$lossless_fields = get_object_vars( $lossless );
			foreach ( get_object_vars( $decoded ) as $key => $item ) {
				if ( array_key_exists( $key, $lossless_fields ) ) {
					self::assert_no_lossy_integers( $item, $lossless_fields[ $key ] );
				}
			}
			return;
		}

		if ( is_array( $decoded ) && is_array( $lossless ) ) {
			foreach ( $decoded as $index => $item ) {
				if ( array_key_exists( $index, $lossless ) ) {
					self::assert_no_lossy_integers( $item, $lossless[ $index ] );
				}
			}
		}
	}

	/**
	 * Preserve object-versus-list meaning for every structural catalog field.
	 */
	private static function assert_json_list_shapes( stdClass $catalog ): void {
		if ( ! is_array( $catalog->components ) ) {
			throw new InvalidArgumentException( 'Accepted catalog components must be a JSON list.' );
		}

		foreach ( $catalog->components as $component ) {
			if ( ! $component instanceof stdClass ) {
				throw new InvalidArgumentException( 'Accepted catalog components must be JSON objects.' );
			}

			foreach ( array( 'properties', 'slots', 'class_property_paths', 'recipe_ids' ) as $list_field ) {
				if ( ! property_exists( $component, $list_field ) || ! is_array( $component->{$list_field} ) ) {
					throw new InvalidArgumentException( sprintf( 'Accepted component %s must be a JSON list.', $list_field ) );
				}
			}

			foreach ( $component->properties as $property ) {
				if ( ! $property instanceof stdClass ) {
					throw new InvalidArgumentException( 'Accepted component properties must be JSON objects.' );
				}

				if ( property_exists( $property, 'property_contract' ) ) {
					$contract = $property->property_contract;
					if ( ! $contract instanceof stdClass ) {
						throw new InvalidArgumentException( 'Accepted property contract must be a JSON object.' );
					}

					if ( property_exists( $contract, 'type' ) && ! $contract->type instanceof stdClass ) {
						throw new InvalidArgumentException( 'Accepted property contract type must be a JSON object.' );
					}

					if ( property_exists( $contract, 'instance_value_kinds' ) && ! is_array( $contract->instance_value_kinds ) ) {
						throw new InvalidArgumentException( 'Accepted property contract instance_value_kinds must be a JSON list.' );
					}
				}
			}
		}
	}

	/**
	 * Compare decoded JSON while ignoring object-key order but preserving shapes.
	 */
	private static function json_values_equal( mixed $accepted, mixed $canonical ): bool {
		if ( $accepted instanceof stdClass || $canonical instanceof stdClass ) {
			if ( ! $accepted instanceof stdClass || ! $canonical instanceof stdClass ) {
				return false;
			}

			$accepted_fields  = get_object_vars( $accepted );
			$canonical_fields = get_object_vars( $canonical );
			$accepted_keys    = array_keys( $accepted_fields );
			$canonical_keys   = array_keys( $canonical_fields );
			sort( $accepted_keys );
			sort( $canonical_keys );
			if ( $accepted_keys !== $canonical_keys ) {
				return false;
			}

			foreach ( $accepted_keys as $key ) {
				if ( ! self::json_values_equal( $accepted_fields[ $key ], $canonical_fields[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_array( $accepted ) || is_array( $canonical ) ) {
			if ( ! is_array( $accepted ) || ! is_array( $canonical ) || count( $accepted ) !== count( $canonical ) ) {
				return false;
			}

			foreach ( $accepted as $index => $value ) {
				if ( ! array_key_exists( $index, $canonical ) || ! self::json_values_equal( $value, $canonical[ $index ] ) ) {
					return false;
				}
			}

			return true;
		}

		return $accepted === $canonical;
	}

}
