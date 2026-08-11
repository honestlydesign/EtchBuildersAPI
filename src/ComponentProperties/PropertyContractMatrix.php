<?php
/**
 * Executable Etch property contract matrix.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ArrayProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\BooleanProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\NumberProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ObjectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ClassProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ColorProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ConditionProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\GroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ImageProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\LoopProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\RepeaterGroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\SelectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\UrlProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\WpMediaIdProperty;
use InvalidArgumentException;
use LogicException;

/**
 * Owns the audited relation between Etch definitions and authored instance values.
 */
final class PropertyContractMatrix {

	/**
	 * Cached immutable contracts in canonical order.
	 *
	 * @var array<int, PropertyContract>|null
	 */
	private static ?array $contracts = null;

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Return every audited contract in deterministic canonical order.
	 *
	 * @return array<int, PropertyContract>
	 */
	public static function all(): array {
		if ( null === self::$contracts ) {
			self::$contracts = self::build_contracts();
		}

		return self::$contracts;
	}

	/**
	 * Require the exact primitive/specialized pair without primitive fallback.
	 *
	 * @throws InvalidArgumentException When the pair is not in the audited matrix.
	 */
	public static function contract_for_type( string $primitive, ?string $specialized = null ): PropertyContract {
		foreach ( self::all() as $contract ) {
			if ( $primitive === $contract->primitive()->value && $specialized === $contract->specialized() ) {
				return $contract;
			}
		}

		$type_label = null === $specialized ? $primitive : $primitive . '/' . $specialized;

		throw new InvalidArgumentException(
			sprintf( 'Unsupported Etch property type pair "%s". Query PropertyContractMatrix::all() for exact supported pairs.', $type_label )
		);
	}

	/**
	 * Require the contract owned by one exact typed definition builder class.
	 *
	 * @throws InvalidArgumentException When the builder is not in the audited matrix.
	 */
	public static function contract_for_definition( ComponentPropertyInterface $definition ): PropertyContract {
		$definition_builder = $definition::class;

		foreach ( self::all() as $contract ) {
			if ( $definition_builder === $contract->definition_builder() ) {
				return $contract;
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'Unsupported component property definition builder "%s". Query PropertyContractMatrix::all() for exact supported builders.',
				$definition_builder
			)
		);
	}

	/**
	 * Build and internally validate the canonical audited table.
	 *
	 * @return array<int, PropertyContract>
	 */
	private static function build_contracts(): array {
		$contracts = array(
			self::contract( PropertyPrimitive::STRING, null, StringProperty::class, PropertyInstanceValueKind::STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::NUMBER, null, NumberProperty::class, PropertyInstanceValueKind::NUMERIC_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::BOOLEAN, null, BooleanProperty::class, PropertyInstanceValueKind::BOOLEAN, PropertyWireShape::BOOLEAN_EXPRESSION_ATTRIBUTE ),
			self::contract( PropertyPrimitive::OBJECT, null, ObjectProperty::class, PropertyInstanceValueKind::OBJECT, PropertyWireShape::OBJECT_JSON_EXPRESSION_ATTRIBUTE ),
			self::contract( PropertyPrimitive::ARRAY, null, ArrayProperty::class, PropertyInstanceValueKind::ARRAY, PropertyWireShape::ARRAY_JSON_EXPRESSION_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'color', ColorProperty::class, PropertyInstanceValueKind::COLOR_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'condition', ConditionProperty::class, PropertyInstanceValueKind::TRANSPARENT_CHILDREN, PropertyWireShape::TRANSPARENT_CHILD_ATTRIBUTES ),
			self::contract( PropertyPrimitive::STRING, 'array', LoopProperty::class, PropertyInstanceValueKind::LOOP_REFERENCE_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'url', UrlProperty::class, PropertyInstanceValueKind::URL_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'image', ImageProperty::class, PropertyInstanceValueKind::IMAGE_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'select', SelectProperty::class, PropertyInstanceValueKind::SELECT_OPTION_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::STRING, 'wpMediaId', WpMediaIdProperty::class, PropertyInstanceValueKind::WORDPRESS_MEDIA_ID_STRING, PropertyWireShape::PLAIN_STRING_ATTRIBUTE ),
			self::contract( PropertyPrimitive::ARRAY, 'class', ClassProperty::class, PropertyInstanceValueKind::CLASS_STYLE_SET, PropertyWireShape::CLASS_STYLE_ID_LIST_ATTRIBUTE ),
			self::contract( PropertyPrimitive::ARRAY, 'repeater', RepeaterGroupProperty::class, PropertyInstanceValueKind::REPEATER, PropertyWireShape::ARRAY_JSON_EXPRESSION_ATTRIBUTE ),
			self::contract( PropertyPrimitive::OBJECT, 'group', GroupProperty::class, PropertyInstanceValueKind::GROUP, PropertyWireShape::OBJECT_JSON_EXPRESSION_ATTRIBUTE ),
		);

		$seen_types    = array();
		$seen_builders = array();
		foreach ( $contracts as $contract ) {
			if ( isset( $seen_types[ $contract->type_key() ] ) ) {
				throw new LogicException( 'Duplicate Property Contract Matrix type pair: ' . $contract->type_key() );
			}

			if ( isset( $seen_builders[ $contract->definition_builder() ] ) ) {
				throw new LogicException( 'Duplicate Property Contract Matrix definition builder: ' . $contract->definition_builder() );
			}

			$seen_types[ $contract->type_key() ]               = true;
			$seen_builders[ $contract->definition_builder() ] = true;
		}

		return $contracts;
	}

	/**
	 * Create one supported single-kind contract.
	 *
	 * @param class-string<ComponentPropertyInterface> $definition_builder Definition builder class.
	 */
	private static function contract(
		PropertyPrimitive $primitive,
		?string $specialized,
		string $definition_builder,
		PropertyInstanceValueKind $instance_value_kind,
		PropertyWireShape $wire_shape
	): PropertyContract {
		return new PropertyContract(
			$primitive,
			$specialized,
			$definition_builder,
			array( $instance_value_kind ),
			$wire_shape,
			PropertyContractStatus::SUPPORTED
		);
	}
}
