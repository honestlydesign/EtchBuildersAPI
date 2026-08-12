<?php
/**
 * Typed component instance value.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropValueEncoder;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropValueInterface;
use InvalidArgumentException;
use LogicException;
use ReflectionReference;
use stdClass;

/**
 * Retains one matrix value kind until the component wire encoder boundary.
 */
final class ComponentInstanceValue implements ComponentPropValueInterface {

	private function __construct(
		private readonly PropertyInstanceValueKind $kind,
		private readonly mixed $value
	) {
	}

	public static function string( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::STRING, $value );
	}

	public static function numeric_string( string $value ): self {
		if ( '' === $value || trim( $value ) !== $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			throw new InvalidArgumentException( 'Component numeric-string value must be a valid finite numeric string.' );
		}

		return new self( PropertyInstanceValueKind::NUMERIC_STRING, $value );
	}

	public static function boolean( bool $value ): self {
		return new self( PropertyInstanceValueKind::BOOLEAN, $value );
	}

	/**
	 * @param array<int|string, mixed>|stdClass $value Object payload.
	 */
	public static function object( array|stdClass $value ): self {
		$snapshot = self::snapshot_json_value( $value, 'object' );
		if ( ! is_array( $snapshot ) && ! $snapshot instanceof stdClass ) {
			throw new InvalidArgumentException( 'Component object value must normalize to an object payload.' );
		}
		ComponentPropValueEncoder::literal_object( $snapshot );

		return new self( PropertyInstanceValueKind::OBJECT, $snapshot );
	}

	/**
	 * @param array<int, mixed> $value Array payload.
	 */
	public static function array( array $value ): self {
		if ( ! array_is_list( $value ) ) {
			throw new InvalidArgumentException( 'Component array value must be a list.' );
		}

		$snapshot = self::snapshot_json_value( $value, 'array' );
		if ( ! is_array( $snapshot ) || ! array_is_list( $snapshot ) ) {
			throw new InvalidArgumentException( 'Component array value must normalize to a list.' );
		}
		ComponentPropValueEncoder::literal_array( $snapshot );

		return new self( PropertyInstanceValueKind::ARRAY, $snapshot );
	}

	public static function color( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::COLOR_STRING, $value );
	}

	public static function loop_reference( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::LOOP_REFERENCE_STRING, $value );
	}

	public static function url( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::URL_STRING, $value );
	}

	public static function image( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::IMAGE_STRING, $value );
	}

	public static function select_option( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::SELECT_OPTION_STRING, $value );
	}

	public static function wordpress_media_id( string $value ): self {
		return self::literal_string( PropertyInstanceValueKind::WORDPRESS_MEDIA_ID_STRING, $value );
	}

	public static function empty_repeater(): self {
		return new self( PropertyInstanceValueKind::REPEATER, array() );
	}

	public function kind(): PropertyInstanceValueKind {
		return $this->kind;
	}

	/**
	 * Encode only at the existing component prop wire boundary.
	 */
	public function encode(): string {
		return match ( $this->kind ) {
			PropertyInstanceValueKind::STRING,
			PropertyInstanceValueKind::NUMERIC_STRING,
			PropertyInstanceValueKind::COLOR_STRING,
			PropertyInstanceValueKind::LOOP_REFERENCE_STRING,
			PropertyInstanceValueKind::URL_STRING,
			PropertyInstanceValueKind::IMAGE_STRING,
			PropertyInstanceValueKind::SELECT_OPTION_STRING,
			PropertyInstanceValueKind::WORDPRESS_MEDIA_ID_STRING => $this->string_value(),
			PropertyInstanceValueKind::BOOLEAN => ComponentPropValueEncoder::boolean( $this->boolean_value() ),
			PropertyInstanceValueKind::OBJECT => ComponentPropValueEncoder::literal_object( $this->object_value() ),
			PropertyInstanceValueKind::ARRAY => ComponentPropValueEncoder::literal_array( $this->list_value() ),
			PropertyInstanceValueKind::REPEATER => ComponentPropValueEncoder::repeater( $this->repeater_value() ),
			PropertyInstanceValueKind::GROUP,
			PropertyInstanceValueKind::CLASS_STYLE_SET,
			PropertyInstanceValueKind::TRANSPARENT_CHILDREN => throw new LogicException(
				'Schema-backed literal values cannot directly encode group, class-style-set, or transparent-children kinds.'
			),
		};
	}

	private function string_value(): string {
		if ( ! is_string( $this->value ) ) {
			throw new LogicException( 'Component instance string kind contains a non-string payload.' );
		}

		return $this->value;
	}

	private function boolean_value(): bool {
		if ( ! is_bool( $this->value ) ) {
			throw new LogicException( 'Component instance boolean kind contains a non-boolean payload.' );
		}

		return $this->value;
	}

	/**
	 * @return array<int|string, mixed>|stdClass
	 */
	private function object_value(): array|stdClass {
		if ( ! is_array( $this->value ) && ! $this->value instanceof stdClass ) {
			throw new LogicException( 'Component instance object kind contains a non-object payload.' );
		}

		return $this->value;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function list_value(): array {
		if ( ! is_array( $this->value ) || ! array_is_list( $this->value ) ) {
			throw new LogicException( 'Component instance array kind contains a non-list payload.' );
		}

		return $this->value;
	}

	/**
	 * @return array<int, array<int|string, mixed>|\HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup|stdClass>
	 */
	private function repeater_value(): array {
		if ( ! is_array( $this->value ) || ! array_is_list( $this->value ) ) {
			throw new LogicException( 'Component instance repeater kind contains a non-list payload.' );
		}

		foreach ( $this->value as $row ) {
			if ( ! is_array( $row )
				&& ! $row instanceof \HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup
				&& ! $row instanceof stdClass
			) {
				throw new LogicException( 'Component instance repeater kind contains an invalid row payload.' );
			}
		}

		/** @var array<int, array<int|string, mixed>|\HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup|stdClass> $value */
		$value = $this->value;

		return $value;
	}

	/**
	 * Detach caller-owned references and reject values Etch cannot persist as JSON.
	 */
	private static function snapshot_json_value( array|stdClass $value, string $kind ): mixed {
		self::assert_supported_json_value( $value, $kind, array(), array() );

		return self::copy_json_value( $value );
	}

	/**
	 * Reject unsafe hooks, recursion, non-finite numbers, and expression-shaped literals.
	 *
	 * @param array<string, true> $active_references Array reference IDs on the active path.
	 * @param array<int, true>    $active_objects stdClass IDs on the active path.
	 */
	private static function assert_supported_json_value(
		mixed $value,
		string $kind,
		array $active_references,
		array $active_objects
	): void {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return;
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component %s value must contain finite, non-recursive JSON data.', $kind )
				);
			}
			return;
		}

		if ( is_string( $value ) ) {
			self::assert_json_literal_string( $value );
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( is_string( $key ) ) {
					self::assert_json_literal_string( $key );
				}

				$child_references = $active_references;
				$reference        = ReflectionReference::fromArrayElement( $value, $key );
				if ( null !== $reference ) {
					$reference_id = bin2hex( $reference->getId() );
					if ( isset( $active_references[ $reference_id ] ) ) {
						throw new InvalidArgumentException(
							sprintf( 'Component %s value must contain finite, non-recursive JSON data.', $kind )
						);
					}
					$child_references[ $reference_id ] = true;
				}

				self::assert_supported_json_value( $value[ $key ], $kind, $child_references, $active_objects );
			}
			return;
		}

		if ( $value instanceof stdClass ) {
			$object_id = spl_object_id( $value );
			if ( isset( $active_objects[ $object_id ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component %s value must contain finite, non-recursive JSON data.', $kind )
				);
			}
			$active_objects[ $object_id ] = true;

			foreach ( get_object_vars( $value ) as $key => $item ) {
				self::assert_json_literal_string( (string) $key );
				self::assert_supported_json_value( $item, $kind, $active_references, $active_objects );
			}
			return;
		}

		throw new InvalidArgumentException(
			sprintf( 'Component %s value may contain only scalar, null, array, and stdClass values.', $kind )
		);
	}

	private static function literal_string( PropertyInstanceValueKind $kind, string $value ): self {
		self::assert_literal_string( $value );

		return new self( $kind, $value );
	}

	private static function assert_literal_string( string $value ): void {
		if ( str_contains( $value, '{' ) || str_contains( $value, '}' ) ) {
			throw new InvalidArgumentException(
				'Expression-shaped strings are not allowed in schema-backed literal values; use the checked expression lane when available.'
			);
		}
	}

	private static function assert_json_literal_string( string $value ): void {
		self::assert_literal_string( $value );

		if ( str_ends_with( $value, '\\' ) ) {
			throw new InvalidArgumentException(
				'Schema-backed literal JSON strings and object keys cannot end in a backslash with the current Etch expression parser.'
			);
		}
	}

	/**
	 * Deep-copy accepted persisted data without collapsing stdClass into a list.
	 */
	private static function copy_json_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$result[ $key ] = self::copy_json_value( $item );
			}

			return $result;
		}

		if ( $value instanceof stdClass ) {
			$result = new stdClass();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$result->{$key} = self::copy_json_value( $item );
			}

			return $result;
		}

		return $value;
	}
}
