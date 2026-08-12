<?php
/**
 * Schema-backed component class-property guard.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;

/**
 * Resolves component refs and validates only exact catalog class-property paths.
 */
final class ComponentClassPropertyGuard {

	public function __construct(
		private readonly ComponentContractCatalog $catalog,
		private readonly ComponentRefResolverInterface $ref_resolver
	) {
	}

	/**
	 * Validate every etch/component in one parse_blocks() tree.
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks.
	 * @return array<int, string> Rule G errors.
	 */
	public function validate( array $blocks ): array {
		$errors = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( 'etch/component' === ( $block['blockName'] ?? '' ) ) {
				$errors = array_merge( $errors, $this->validate_component( $block ) );
			}

			$inner_blocks = $block['innerBlocks'] ?? null;
			if ( is_array( $inner_blocks ) && array() !== $inner_blocks ) {
				$errors = array_merge( $errors, $this->validate( $inner_blocks ) );
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * @param array<string, mixed> $block Parsed component block.
	 * @return array<int, string>
	 */
	private function validate_component( array $block ): array {
		$attrs = $block['attrs'] ?? null;
		$ref   = is_array( $attrs ) ? ( $attrs['ref'] ?? null ) : null;
		if ( ! is_int( $ref ) || $ref <= 0 ) {
			return array( 'Rule G: Component block has a missing or malformed positive integer ref; an exact Component Contract cannot be resolved.' );
		}

		try {
			$component_key = $this->ref_resolver->key_by_ref( $ref );
		} catch ( Throwable $throwable ) {
			return array(
				sprintf(
					'Rule G: Component ref "%d" could not resolve its component key: %s',
					$ref,
					$throwable->getMessage()
				),
			);
		}

		if ( null === $component_key || '' === $component_key ) {
			return array( sprintf( 'Rule G: Component ref "%d" does not resolve to an exact component key.', $ref ) );
		}

		try {
			$contract = $this->catalog->contract( $component_key );
		} catch ( InvalidArgumentException $exception ) {
			return array(
				sprintf(
					'Rule G: Component "%s" (ref "%d") has no exact Component Contract: %s',
					$component_key,
					$ref,
					$exception->getMessage()
				),
			);
		}

		if ( ! array_key_exists( 'attributes', $attrs ) ) {
			return array();
		}

		$attributes = $attrs['attributes'];
		if ( ! is_array( $attributes ) || ( array() !== $attributes && array_is_list( $attributes ) ) ) {
			return array(
				sprintf(
					'Rule G: Component "%s" attributes payload is malformed; expected an object-shaped map.',
					$component_key
				),
			);
		}

		$errors = array();
		foreach ( $contract->class_property_paths() as $class_path ) {
			$errors = array_merge(
				$errors,
				$this->validate_path(
					$contract,
					$component_key,
					$attributes,
					explode( '.', $class_path ),
					0,
					'',
					''
				)
			);
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * Follow one exact schema class path through stored group/repeater wire values.
	 *
	 * @param array<string, mixed> $container Current decoded object-shaped payload.
	 * @param array<int, string>   $segments Exact value-path segments.
	 * @return array<int, string>
	 */
	private function validate_path(
		ComponentContract $contract,
		string $component_key,
		array $container,
		array $segments,
		int $position,
		string $schema_prefix,
		string $display_prefix
	): array {
		$segment     = $segments[ $position ];
		$is_repeater = str_ends_with( $segment, '[]' );
		$key          = $is_repeater ? substr( $segment, 0, -2 ) : $segment;
		if ( ! array_key_exists( $key, $container ) ) {
			return array();
		}

		$property_path = self::join_path( $schema_prefix, $key );
		$child_prefix  = $is_repeater ? $property_path . '[]' : $property_path;
		$display_path = self::join_path( $display_prefix, $key );
		$value        = $container[ $key ];
		$is_last      = count( $segments ) - 1 === $position;
		if ( $is_last ) {
			return $this->validate_class_value( $component_key, $display_path, $value );
		}

		$property = $contract->property_by_value_path( $property_path );
		if ( $is_repeater ) {
			if ( 'array/repeater' !== $property->property_contract()->type_key() ) {
				return array( $this->malformed( $component_key, $display_path, 'catalog path is marked as a repeater but its exact type is not array/repeater' ) );
			}

			$decoded = $this->decode_repeater( $component_key, $display_path, $value );
			if ( null === $decoded['value'] ) {
				return $decoded['errors'];
			}

			$errors = $decoded['errors'];
			foreach ( $decoded['value'] as $index => $row ) {
				if ( ! is_array( $row ) ) {
					$errors[] = $this->malformed( $component_key, $display_path . '[' . $index . ']', 'repeater row must be an object' );
					continue;
				}

				$errors = array_merge(
					$errors,
					$this->validate_path(
						$contract,
						$component_key,
						$row,
						$segments,
						$position + 1,
						$child_prefix,
						$display_path . '[' . $index . ']'
					)
				);
			}

			return $errors;
		}

		if ( 'object/group' !== $property->property_contract()->type_key() ) {
			return array( $this->malformed( $component_key, $display_path, 'class-property descendant requires an object/group parent' ) );
		}

		$decoded = $this->decode_group( $component_key, $display_path, $value );
		if ( null === $decoded['value'] ) {
			return $decoded['errors'];
		}

		return array_merge(
			$decoded['errors'],
			$this->validate_path(
				$contract,
				$component_key,
				$decoded['value'],
				$segments,
				$position + 1,
				$child_prefix,
				$display_path
			)
		);
	}

	/**
	 * @return array{value: array<string, mixed>|null, errors: array<int, string>}
	 */
	private function decode_group( string $component_key, string $path, mixed $wire ): array {
		if ( $wire instanceof stdClass ) {
			return array( 'value' => get_object_vars( $wire ), 'errors' => array() );
		}

		if ( is_array( $wire ) ) {
			if ( array() !== $wire && array_is_list( $wire ) ) {
				return array(
					'value'  => null,
					'errors' => array( $this->malformed( $component_key, $path, 'group array value must be object-shaped, not a list' ) ),
				);
			}

			return array( 'value' => $wire, 'errors' => array() );
		}

		if ( $this->is_checked_expression( $wire, PropertyInstanceValueKind::GROUP ) ) {
			return array( 'value' => null, 'errors' => array() );
		}

		if ( ! is_string( $wire ) ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'group value must be an object-shaped array, JSON object string, Etch {{...}} object wire, or one checked source expression' ) ),
			);
		}
		$wire = trim( $wire );

		$json = str_starts_with( $wire, '{{' ) && str_ends_with( $wire, '}}' )
			? substr( $wire, 1, -1 )
			: $wire;

		try {
			$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'group value contains invalid JSON: ' . $exception->getMessage() ) ),
			);
		}

		if ( ! $decoded instanceof stdClass ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'group value must decode to an object' ) ),
			);
		}

		return array( 'value' => get_object_vars( $decoded ), 'errors' => array() );
	}

	/**
	 * @return array{value: array<int, array<string, mixed>|null>|null, errors: array<int, string>}
	 */
	private function decode_repeater( string $component_key, string $path, mixed $wire ): array {
		if ( is_array( $wire ) ) {
			if ( ! array_is_list( $wire ) ) {
				return array(
					'value'  => null,
					'errors' => array( $this->malformed( $component_key, $path, 'repeater array value must be a list' ) ),
				);
			}

			return array( 'value' => $this->normalize_repeater_rows( $wire ), 'errors' => array() );
		}

		if ( $this->is_checked_expression( $wire, PropertyInstanceValueKind::REPEATER ) ) {
			return array( 'value' => null, 'errors' => array() );
		}

		if ( ! is_string( $wire ) ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'repeater value must be a list array, JSON list string, Etch {[...]} list wire, or one checked source expression' ) ),
			);
		}
		$wire = trim( $wire );

		$json = str_starts_with( $wire, '{[' ) && str_ends_with( $wire, ']}' )
			? substr( $wire, 1, -1 )
			: $wire;

		try {
			$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'repeater value contains invalid JSON: ' . $exception->getMessage() ) ),
			);
		}

		if ( ! is_array( $decoded ) ) {
			return array(
				'value'  => null,
				'errors' => array( $this->malformed( $component_key, $path, 'repeater value must decode to a list' ) ),
			);
		}

		return array( 'value' => $this->normalize_repeater_rows( $decoded ), 'errors' => array() );
	}

	/**
	 * @param array<int, mixed> $rows Decoded list rows.
	 * @return array<int, array<string, mixed>|null>
	 */
	private function normalize_repeater_rows( array $rows ): array {
		return array_map(
			static function ( mixed $row ): ?array {
				if ( $row instanceof stdClass ) {
					return get_object_vars( $row );
				}

				if ( ! is_array( $row ) || ( array() !== $row && array_is_list( $row ) ) ) {
					return null;
				}

				return $row;
			},
			$rows
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function validate_class_value( string $component_key, string $path, mixed $value ): array {
		if ( '' === $value || array() === $value ) {
			return array();
		}

		if ( $this->is_checked_expression( $value, PropertyInstanceValueKind::CLASS_STYLE_SET ) ) {
			return array();
		}

		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				return array( $this->malformed( $component_key, $path, 'class array value must be a list of exact opaque style-ID strings' ) );
			}

			$style_ids = $value;
		} elseif ( is_string( $value ) ) {
			if ( '' === trim( $value ) || trim( $value ) !== $value ) {
				return array( $this->malformed( $component_key, $path, 'class value must be an exact space-delimited opaque style-ID string, an ID list, an explicit empty value, or one checked source expression' ) );
			}

			if ( str_contains( $value, '{' ) || str_contains( $value, '}' ) ) {
				return array(
					sprintf(
						'Rule G: Component "%s" class property "%s" uses an unchecked dynamic expression. Use ComponentExpression::source() for a finite source path or require explicit runtime verification outside the Golden Path.',
						$component_key,
						$path
					),
				);
			}

			$style_ids = array_values(
				array_filter(
					array_map( 'trim', explode( ' ', $value ) ),
					static fn ( string $style_id ): bool => '' !== $style_id
				)
			);
		} else {
			return array( $this->malformed( $component_key, $path, 'class value must be an exact space-delimited opaque style-ID string, an explicit empty string, or one checked source expression' ) );
		}

		$errors = array();
		foreach ( $style_ids as $style_id ) {
			if ( ! is_string( $style_id ) || '' === $style_id || trim( $style_id ) !== $style_id ) {
				$errors[] = $this->malformed( $component_key, $path, 'class ID lists must contain only exact non-empty strings' );
				continue;
			}

			try {
				$reference = ClassStyleReference::registered( $style_id );
				if ( ! $reference->has_explicit_class_type() ) {
					throw new InvalidArgumentException(
						sprintf(
							'Class Style ID "%s" must declare explicit type=class for Etch component ClassProperty rendering.',
							$style_id
						)
					);
				}
			} catch ( InvalidArgumentException $exception ) {
				$errors[] = sprintf(
					'Rule G: Component "%s" class property "%s" has invalid opaque Class Style ID "%s". %s',
					$component_key,
					$path,
					$style_id,
					$exception->getMessage()
				);
			}
		}

		return $errors;
	}

	private function is_checked_expression( mixed $wire, PropertyInstanceValueKind $kind ): bool {
		if ( ! is_string( $wire ) || strlen( $wire ) < 3 || '{' !== $wire[0] || '}' !== $wire[ strlen( $wire ) - 1 ] ) {
			return false;
		}

		try {
			$expression = ComponentExpression::source( substr( $wire, 1, -1 ), $kind );
		} catch ( InvalidArgumentException $exception ) {
			return false;
		}

		return $wire === $expression->encode();
	}

	private function malformed( string $component_key, string $path, string $detail ): string {
		return sprintf(
			'Rule G: Component "%s" class-property path "%s" has malformed Etch wire: %s.',
			$component_key,
			$path,
			$detail
		);
	}

	private static function join_path( string $prefix, string $segment ): string {
		return '' === $prefix ? $segment : $prefix . '.' . $segment;
	}
}
