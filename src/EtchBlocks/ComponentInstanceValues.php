<?php
/**
 * Schema-backed component instance value compiler.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentPropertyPathContract;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentExpression;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractStatus;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use InvalidArgumentException;

/**
 * Validates exact instance paths and assembles Etch's grouped wire attributes.
 */
final class ComponentInstanceValues {

	/**
	 * @var array<string, mixed>
	 */
	private array $tree = array();

	private function __construct( private readonly ComponentContract $contract ) {
	}

	public static function for_component(
		string $component_key,
		ComponentContractCatalogProviderInterface $provider
	): self {
		return new self( $provider->catalog()->contract( $component_key ) );
	}

	public function component_key(): string {
		return $this->contract->component_key();
	}

	/**
	 * Add one exact concrete instance path.
	 */
	public function set( string $path, ComponentInstanceValue $value ): self {
		$tokens   = $this->parse_path( $path );
		$property = $this->resolve_path( $path, $tokens );

		if ( PropertyContractStatus::SUPPORTED !== $property->property_contract()->status() ) {
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" path "%s" is not supported for authored instance values.',
					$this->component_key(),
					$path
				)
			);
		}

		$expected_kinds = $property->property_contract()->instance_value_kinds();
		if ( ! in_array( $value->kind(), $expected_kinds, true ) ) {
			$expected = implode( '", "', array_map( static fn ( $kind ): string => $kind->value, $expected_kinds ) );
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" path "%s" expects instance value kind "%s"; got "%s".',
					$this->component_key(),
					$path,
					$expected,
					$value->kind()->value
				)
			);
		}

		$this->insert( $path, $tokens, $value );

		return $this;
	}

	/**
	 * Add one exact concrete array/class instance path.
	 */
	public function set_class_styles( string $path, ClassStyleSet $classes ): self {
		$tokens   = $this->parse_path( $path );
		$property = $this->resolve_path( $path, $tokens );

		if ( 'array/class' !== $property->property_contract()->type_key() ) {
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" path "%s" must be an exact array/class property; got "%s".',
					$this->component_key(),
					$path,
					$property->property_contract()->type_key()
				)
			);
		}

		$this->insert( $path, $tokens, $classes );

		return $this;
	}

	/**
	 * Add one checked standalone expression to an exact concrete instance path.
	 */
	public function set_expression( string $path, ComponentExpression $expression ): self {
		$tokens   = $this->parse_path( $path );
		$property = $this->resolve_path( $path, $tokens );

		if ( PropertyContractStatus::SUPPORTED !== $property->property_contract()->status() ) {
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" path "%s" is not supported for authored instance expressions.',
					$this->component_key(),
					$path
				)
			);
		}

		$expected_kinds = $property->property_contract()->instance_value_kinds();
		if ( ! in_array( $expression->expected_kind(), $expected_kinds, true ) ) {
			$expected = implode( '", "', array_map( static fn ( $kind ): string => $kind->value, $expected_kinds ) );
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" path "%s" expects expression result kind "%s"; got "%s".',
					$this->component_key(),
					$path,
					$expected,
					$expression->expected_kind()->value
				)
			);
		}

		$this->insert( $path, $tokens, $expression );

		return $this;
	}

	/**
	 * Compile deterministic top-level component attributes.
	 *
	 * @return array<string, string>
	 */
	public function encode_attributes(): array {
		$attributes = array();
		foreach ( $this->ordered_child_keys( '' ) as $root_key ) {
			if ( ! array_key_exists( $root_key, $this->tree ) ) {
				continue;
			}

			$value    = $this->tree[ $root_key ];
			$property = $this->property_at( $root_key );
			if ( $value instanceof ComponentInstanceValue ) {
				$attributes[ $root_key ] = $value->encode();
				continue;
			}

			if ( $value instanceof ClassStyleSet ) {
				$attributes[ $root_key ] = ComponentPropValueEncoder::class( $value->ids() );
				continue;
			}

			if ( $value instanceof ComponentExpression ) {
				$attributes[ $root_key ] = $value->encode();
				continue;
			}

			if ( ! is_array( $value ) ) {
				throw new InvalidArgumentException( 'Schema-backed component values contain an invalid internal node.' );
			}

			$attributes[ $root_key ] = match ( $property->property_contract()->type_key() ) {
				'object/group'  => $this->build_group( $value, $root_key )->encode(),
				'array/repeater' => $this->build_repeater( $value, $root_key )->encode(),
				default => throw new InvalidArgumentException(
					sprintf( 'Component "%s" path "%s" is a leaf and cannot contain child assignments.', $this->component_key(), $root_key )
				),
			};
		}

		return $attributes;
	}

	/**
	 * @return array<int, array{key: string, index: int|null}>
	 */
	private function parse_path( string $path ): array {
		if ( '' === $path || trim( $path ) !== $path ) {
			throw new InvalidArgumentException( 'Component instance value path must be a non-empty exact string.' );
		}

		$tokens = array();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( 1 !== preg_match( '/^([A-Za-z_][A-Za-z0-9_]*)(?:\[(0|[1-9][0-9]*)\])?$/D', $segment, $matches ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component instance value path "%s" has invalid segment "%s".', $path, $segment )
				);
			}

			$index = null;
			if ( isset( $matches[2] ) ) {
				$index_literal = $matches[2];
				$maximum       = (string) PHP_INT_MAX;
				if ( strlen( $index_literal ) > strlen( $maximum )
					|| ( strlen( $index_literal ) === strlen( $maximum ) && strcmp( $index_literal, $maximum ) > 0 )
				) {
					throw new InvalidArgumentException(
						sprintf( 'Component instance value path "%s" repeater row index must fit the current PHP integer range.', $path )
					);
				}
				$index = (int) $index_literal;
			}

			$tokens[] = array(
				'key'   => $matches[1],
				'index' => $index,
			);
		}

		return $tokens;
	}

	/**
	 * @param array<int, array{key: string, index: int|null}> $tokens Parsed path.
	 */
	private function resolve_path( string $original_path, array $tokens ): ComponentPropertyPathContract {
		$prefix = '';
		$last   = count( $tokens ) - 1;

		foreach ( $tokens as $position => $token ) {
			$property_path = self::join_path( $prefix, $token['key'] );
			$property      = $this->property_at( $property_path, $original_path );
			$is_last       = $last === $position;

			if ( null !== $token['index'] ) {
				if ( $is_last ) {
					throw new InvalidArgumentException(
						sprintf( 'Component instance value path "%s" must end at a property, not a repeater row.', $original_path )
					);
				}

				if ( 'array/repeater' !== $property->property_contract()->type_key() ) {
					throw new InvalidArgumentException(
						sprintf( 'Component "%s" path "%s" is not a repeater and cannot use a row index.', $this->component_key(), $property_path )
					);
				}

				$prefix = $property_path . '[]';
				continue;
			}

			if ( $is_last ) {
				return $property;
			}

			$type_key = $property->property_contract()->type_key();
			if ( 'array/repeater' === $type_key ) {
				throw new InvalidArgumentException(
					sprintf( 'Component "%s" repeater path "%s" requires a concrete row index such as [0].', $this->component_key(), $property_path )
				);
			}

			if ( 'object/group' !== $type_key ) {
				throw new InvalidArgumentException(
					sprintf( 'Component "%s" path "%s" is a leaf and cannot contain child assignments.', $this->component_key(), $property_path )
				);
			}

			$prefix = $property_path;
		}

		throw new InvalidArgumentException( 'Component instance value path could not be resolved.' );
	}

	private function property_at( string $value_path, ?string $original_path = null ): ComponentPropertyPathContract {
		foreach ( $this->contract->properties() as $property ) {
			if ( $value_path === $property->value_path() ) {
				return $property;
			}
		}

		foreach ( $this->contract->properties() as $property ) {
			if ( $value_path === $property->declaration_path() && null === $property->value_path() ) {
				throw new InvalidArgumentException(
					sprintf(
						'Component "%s" path "%s" is declaration-only transparent and cannot receive an instance value.',
						$this->component_key(),
						$original_path ?? $value_path
					)
				);
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'Component "%s" has no exact instance value path "%s".',
				$this->component_key(),
				$original_path ?? $value_path
			)
		);
	}

	/**
	 * @param array<int, array{key: string, index: int|null}> $tokens Parsed path.
	 */
	private function insert( string $path, array $tokens, ComponentInstanceValue|ClassStyleSet|ComponentExpression $value ): void {
		$node =& $this->tree;
		$last = count( $tokens ) - 1;

		foreach ( $tokens as $position => $token ) {
			$key     = $token['key'];
			$is_last = $last === $position;

			if ( $is_last ) {
				if ( array_key_exists( $key, $node ) ) {
					$message = self::is_assignment( $node[ $key ] )
						? 'already has an assignment'
						: 'conflicts with existing child assignments';
					throw new InvalidArgumentException( sprintf( 'Component instance value path "%s" %s.', $path, $message ) );
				}

				$node[ $key ] = $value;
				break;
			}

			if ( ! array_key_exists( $key, $node ) ) {
				$node[ $key ] = array();
			} elseif ( self::is_assignment( $node[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component instance value path "%s" conflicts with an existing assignment.', $path )
				);
			}

			if ( ! is_array( $node[ $key ] ) ) {
				throw new InvalidArgumentException( 'Schema-backed component values contain an invalid internal node.' );
			}

			if ( null === $token['index'] ) {
				$node =& $node[ $key ];
				continue;
			}

			$index = $token['index'];
			if ( ! array_key_exists( $index, $node[ $key ] ) ) {
				$node[ $key ][ $index ] = array();
			} elseif ( self::is_assignment( $node[ $key ][ $index ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component instance value path "%s" conflicts with an existing assignment.', $path )
				);
			}

			$node =& $node[ $key ][ $index ];
		}

		unset( $node );
	}

	/**
	 * @param array<string, mixed> $node Group node.
	 */
	private function build_group( array $node, string $base_path ): ComponentPropGroup {
		$group = ComponentPropGroup::new();
		foreach ( $this->ordered_child_keys( $base_path ) as $key ) {
			if ( ! array_key_exists( $key, $node ) ) {
				continue;
			}

			$value = $node[ $key ];
			if ( $value instanceof ComponentExpression ) {
				$group->value( $key, $value->encode() );
				continue;
			}

			if ( $value instanceof ComponentInstanceValue || $value instanceof ClassStyleSet ) {
				$group->value( $key, $value );
				continue;
			}

			if ( ! is_array( $value ) ) {
				throw new InvalidArgumentException( 'Schema-backed component values contain an invalid group node.' );
			}

			$property_path = self::join_path( $base_path, $key );
			$property      = $this->property_at( $property_path );
			$group->value(
				$key,
				match ( $property->property_contract()->type_key() ) {
					'object/group'  => $this->build_group( $value, $property_path ),
					'array/repeater' => $this->build_repeater( $value, $property_path ),
					default => throw new InvalidArgumentException(
						sprintf( 'Component "%s" path "%s" is a leaf and cannot contain child assignments.', $this->component_key(), $property_path )
					),
				}
			);
		}

		return $group;
	}

	/**
	 * @param array<int, mixed> $rows Repeater rows.
	 */
	private function build_repeater( array $rows, string $base_path ): ComponentPropRepeater {
		$indexes = array_keys( $rows );
		sort( $indexes );
		foreach ( $indexes as $expected_index => $actual_index ) {
			if ( $expected_index !== $actual_index ) {
				throw new InvalidArgumentException(
					sprintf( 'Component "%s" repeater path "%s" requires contiguous rows starting at index 0.', $this->component_key(), $base_path )
				);
			}
		}

		$repeater = ComponentPropRepeater::new();
		foreach ( $indexes as $index ) {
			$row = $rows[ $index ];
			if ( ! is_array( $row ) || array() === $row ) {
				throw new InvalidArgumentException(
					sprintf( 'Component "%s" repeater path "%s" contains an empty or invalid row.', $this->component_key(), $base_path )
				);
			}

			$repeater->item( $this->build_group( $row, $base_path . '[]' ) );
		}

		return $repeater;
	}

	/**
	 * Return direct effective child keys in canonical schema order.
	 *
	 * @return array<int, string>
	 */
	private function ordered_child_keys( string $base_path ): array {
		$keys   = array();
		$seen   = array();
		$prefix = '' === $base_path ? '' : $base_path . '.';

		foreach ( $this->contract->properties() as $property ) {
			$value_path = $property->value_path();
			if ( null === $value_path || ( '' !== $prefix && ! str_starts_with( $value_path, $prefix ) ) ) {
				continue;
			}

			$remainder = '' === $prefix ? $value_path : substr( $value_path, strlen( $prefix ) );
			if ( '' === $remainder ) {
				continue;
			}

			$separator = strpos( $remainder, '.' );
			$segment   = false === $separator ? $remainder : substr( $remainder, 0, $separator );
			$key       = str_ends_with( $segment, '[]' ) ? substr( $segment, 0, -2 ) : $segment;
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$keys[]       = $key;
		}

		return $keys;
	}

	private static function join_path( string $prefix, string $key ): string {
		return '' === $prefix ? $key : $prefix . '.' . $key;
	}

	private static function is_assignment( mixed $value ): bool {
		return $value instanceof ComponentInstanceValue
			|| $value instanceof ClassStyleSet
			|| $value instanceof ComponentExpression;
	}
}
