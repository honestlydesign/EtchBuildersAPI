<?php
/**
 * One schema-derived component property path contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContract;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use InvalidArgumentException;
use JsonException;
use ReflectionReference;

/**
 * Relates one declared component property to its effective authored value path.
 */
final class ComponentPropertyPathContract {

	private readonly string $declaration_path;

	private readonly ?string $value_path;

	private readonly PropertyContract $property_contract;

	private readonly bool $has_default;

	private readonly mixed $default_value;

	/**
	 * Constructor.
	 *
	 * A null value path marks a transparent definition, such as a condition,
	 * which contributes child values but is not itself authored as an attribute.
	 */
	public function __construct(
		string $declaration_path,
		?string $value_path,
		PropertyContract $property_contract,
		bool $has_default,
		mixed $default_value = null
	) {
		$property_contract = PropertyContractMatrix::contract_for_type(
			$property_contract->primitive()->value,
			$property_contract->specialized()
		);

		self::assert_path( $declaration_path, 'declaration' );
		if ( str_contains( $declaration_path, '[]' ) ) {
			throw new InvalidArgumentException( 'Component property declaration path cannot contain repeater markers.' );
		}

		if ( null !== $value_path ) {
			self::assert_path( $value_path, 'value' );
		}

		$is_condition = 'string/condition' === $property_contract->type_key();
		if ( $is_condition && null !== $value_path ) {
			throw new InvalidArgumentException( 'A transparent condition property cannot declare a value path.' );
		}

		if ( ! $is_condition && null === $value_path ) {
			throw new InvalidArgumentException( 'A non-condition component property must declare a value path.' );
		}

		if ( null !== $value_path ) {
			self::assert_value_path_is_derived_from_declaration( $declaration_path, $value_path );
		}

		$this->declaration_path  = $declaration_path;
		$this->value_path        = $value_path;
		$this->property_contract = $property_contract;
		$this->has_default       = $has_default;
		$this->default_value     = $has_default
			? self::normalize_persistable_value( $default_value, $declaration_path )
			: null;
	}

	public function declaration_path(): string {
		return $this->declaration_path;
	}

	public function value_path(): ?string {
		return $this->value_path;
	}

	public function property_contract(): PropertyContract {
		return $this->property_contract;
	}

	public function has_default(): bool {
		return $this->has_default;
	}

	public function default_value(): mixed {
		return $this->default_value;
	}

	public function is_class_property(): bool {
		return 'array/class' === $this->property_contract->type_key();
	}

	/**
	 * Return a deterministic machine-readable record.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$record = array(
			'declaration_path'  => $this->declaration_path,
			'value_path'        => $this->value_path,
			'property_contract' => $this->property_contract->to_array(),
			'has_default'       => $this->has_default,
		);

		if ( $this->has_default ) {
			$record['default'] = $this->default_value;
		}

		return $record;
	}

	/**
	 * Reject paths that cannot be addressed unambiguously by the catalog.
	 */
	private static function assert_path( string $path, string $kind ): void {
		if ( '' === $path || trim( $path ) !== $path ) {
			throw new InvalidArgumentException( sprintf( 'Component property %s path must be a non-empty exact string.', $kind ) );
		}

		$segments = explode( '.', $path );
		foreach ( $segments as $segment ) {
			$base = str_ends_with( $segment, '[]' ) ? substr( $segment, 0, -2 ) : $segment;
			if ( '' === $base || 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $base ) ) {
				throw new InvalidArgumentException( sprintf( 'Component property %s path "%s" is invalid.', $kind, $path ) );
			}
		}
	}

	/**
	 * Require the value path to preserve declared keys in order.
	 *
	 * Declaration-only segments represent transparent condition nodes. Repeater
	 * markers may annotate retained value segments but may not invent new keys.
	 */
	private static function assert_value_path_is_derived_from_declaration( string $declaration_path, string $value_path ): void {
		$declaration_segments = explode( '.', $declaration_path );
		$value_segments       = array_map(
			static fn ( string $segment ): string => str_ends_with( $segment, '[]' ) ? substr( $segment, 0, -2 ) : $segment,
			explode( '.', $value_path )
		);

		$last_value_segment = $value_segments[ count( $value_segments ) - 1 ];
		if ( $last_value_segment !== $declaration_segments[ count( $declaration_segments ) - 1 ]
			|| str_ends_with( $value_path, '[]' )
		) {
			throw new InvalidArgumentException( 'Component property value path must be derived from its declaration path.' );
		}

		$declaration_index = 0;
		foreach ( $value_segments as $value_segment ) {
			while ( isset( $declaration_segments[ $declaration_index ] )
				&& $declaration_segments[ $declaration_index ] !== $value_segment
			) {
				++$declaration_index;
			}

			if ( ! isset( $declaration_segments[ $declaration_index ] ) ) {
				throw new InvalidArgumentException( 'Component property value path must be derived from its declaration path.' );
			}

			++$declaration_index;
		}
	}

	/**
	 * Detach PHP reference cells and retain only immutable persisted data.
	 */
	private static function normalize_persistable_value( mixed $value, string $path ): mixed {
		self::assert_persistable_value( $value, $path, array() );

		try {
			$encoded = json_encode(
				$value,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException(
				sprintf( 'Component property "%s" default must be finite, non-recursive persisted data.', $path ),
				0,
				$exception
			);
		}

		try {
			return json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			throw new InvalidArgumentException(
				sprintf( 'Component property "%s" default could not be normalized as persisted data.', $path ),
				0,
				$exception
			);
		}
	}

	/**
	 * Reject mutable objects and non-persistable values after recursion screening.
	 *
	 * @param array<string, true> $active_references Reference IDs on the active traversal path.
	 */
	private static function assert_persistable_value( mixed &$value, string $path, array $active_references ): void {
		if ( null === $value || is_string( $value ) || is_int( $value ) || is_bool( $value ) || is_float( $value ) ) {
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				$child_references = $active_references;
				$reference        = ReflectionReference::fromArrayElement( $value, $key );
				if ( null !== $reference ) {
					$reference_id = bin2hex( $reference->getId() );
					if ( isset( $active_references[ $reference_id ] ) ) {
						throw new InvalidArgumentException(
							sprintf( 'Component property "%s" default must be finite, non-recursive persisted data.', $path )
						);
					}

					$child_references[ $reference_id ] = true;
				}

				self::assert_persistable_value( $value[ $key ], $path, $child_references );
			}
			return;
		}

		throw new InvalidArgumentException(
			sprintf( 'Component property "%s" default must contain only persisted scalar, null, or array data.', $path )
		);
	}
}
