<?php
/**
 * Executable property contract matrix tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
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
use PHPUnit\Framework\TestCase;

/**
 * Verifies the complete audited Etch definition/value/wire relation.
 */
final class PropertyContractMatrixTest extends TestCase {

	public function test_matrix_serializes_the_complete_audited_table_deterministically(): void {
		self::assertSame( $this->expected_contracts(), $this->serialized_contracts() );
	}

	public function test_every_type_pair_and_definition_builder_is_unique(): void {
		$type_keys = array();
		$builders  = array();

		foreach ( PropertyContractMatrix::all() as $contract ) {
			$type_keys[] = $contract->type_key();
			$builders[]  = $contract->definition_builder();
		}

		self::assertCount( count( array_unique( $type_keys ) ), $type_keys );
		self::assertCount( count( array_unique( $builders ) ), $builders );
	}

	public function test_exact_type_lookup_does_not_fall_back_to_a_primitive(): void {
		$url = PropertyContractMatrix::contract_for_type( 'string', 'url' );
		self::assertSame( UrlProperty::class, $url->definition_builder() );
		self::assertSame( 'url-string', $url->instance_value_kinds()[0]->value );
		self::assertSame( 'plain-string-attribute', $url->wire_shape()->value );

		$number = PropertyContractMatrix::contract_for_type( 'number' );
		self::assertSame( NumberProperty::class, $number->definition_builder() );
		self::assertSame( 'numeric-string', $number->instance_value_kinds()[0]->value );
		self::assertSame( 'plain-string-attribute', $number->wire_shape()->value );
	}

	/**
	 * @dataProvider unknown_type_pair_provider
	 */
	public function test_unknown_or_mismatched_type_pairs_fail_closed( string $primitive, ?string $specialized ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported Etch property type pair' );

		PropertyContractMatrix::contract_for_type( $primitive, $specialized );
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public function unknown_type_pair_provider(): array {
		return array(
			'raw url primitive'       => array( 'url', null ),
			'number specialization'   => array( 'string', 'number' ),
			'url on number primitive' => array( 'number', 'url' ),
			'unknown specialization'  => array( 'string', 'invented' ),
			'empty specialization'    => array( 'string', '' ),
			'wrong primitive case'    => array( 'STRING', null ),
			'slash-encoded primitive' => array( 'string/url', null ),
		);
	}

	public function test_definition_lookup_resolves_every_supported_typed_builder(): void {
		$definitions = array(
			StringProperty::new( 'String' ),
			NumberProperty::new( 'Number' ),
			BooleanProperty::new( 'Boolean' ),
			ObjectProperty::new( 'Object' ),
			ArrayProperty::new( 'Array' ),
			ColorProperty::new( 'Color' ),
			ConditionProperty::new( 'Condition' ),
			LoopProperty::new( 'Loop' ),
			UrlProperty::new( 'URL' ),
			ImageProperty::new( 'Image' ),
			SelectProperty::new( 'Select' ),
			WpMediaIdProperty::new( 'Media' ),
			ClassProperty::new( 'Class' ),
			RepeaterGroupProperty::new( 'Repeater' ),
			GroupProperty::new( 'Group' ),
		);

		foreach ( $definitions as $index => $definition ) {
			self::assertSame(
				$definition::class,
				PropertyContractMatrix::contract_for_definition( $definition )->definition_builder(),
				'Unexpected contract at audited definition index ' . $index
			);
		}
	}

	public function test_unknown_definition_builder_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( UnknownMatrixProperty::class );

		PropertyContractMatrix::contract_for_definition( UnknownMatrixProperty::new( 'Unknown' ) );
	}

	public function test_contract_records_are_observationally_immutable(): void {
		$contract = PropertyContractMatrix::contract_for_type( 'array', 'class' );
		$kinds    = $contract->instance_value_kinds();
		$payload  = $contract->to_array();

		$kinds[]           = $kinds[0];
		$payload['status'] = 'pending';

		self::assertCount( 1, $contract->instance_value_kinds() );
		self::assertSame( 'class-style-set', $contract->instance_value_kinds()[0]->value );
		self::assertSame( 'array/class', $contract->type_key() );
		self::assertSame( 'class-style-id-list-attribute', $contract->to_array()['wire_shape'] );
		self::assertSame( 'supported', $contract->status()->value );
		self::assertCount( 2, $kinds );
		self::assertSame( 'pending', $payload['status'] );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function serialized_contracts(): array {
		return array_map(
			static fn ( $contract ): array => $contract->to_array(),
			PropertyContractMatrix::all()
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function expected_contracts(): array {
		return array(
			$this->contract( 'string', null, StringProperty::class, 'string', 'plain-string-attribute' ),
			$this->contract( 'number', null, NumberProperty::class, 'numeric-string', 'plain-string-attribute' ),
			$this->contract( 'boolean', null, BooleanProperty::class, 'boolean', 'boolean-expression-attribute' ),
			$this->contract( 'object', null, ObjectProperty::class, 'object', 'object-json-expression-attribute' ),
			$this->contract( 'array', null, ArrayProperty::class, 'array', 'array-json-expression-attribute' ),
			$this->contract( 'string', 'color', ColorProperty::class, 'color-string', 'plain-string-attribute' ),
			$this->contract( 'string', 'condition', ConditionProperty::class, 'transparent-children', 'transparent-child-attributes' ),
			$this->contract( 'string', 'array', LoopProperty::class, 'loop-reference-string', 'plain-string-attribute' ),
			$this->contract( 'string', 'url', UrlProperty::class, 'url-string', 'plain-string-attribute' ),
			$this->contract( 'string', 'image', ImageProperty::class, 'image-string', 'plain-string-attribute' ),
			$this->contract( 'string', 'select', SelectProperty::class, 'select-option-string', 'plain-string-attribute' ),
			$this->contract( 'string', 'wpMediaId', WpMediaIdProperty::class, 'wordpress-media-id-string', 'plain-string-attribute' ),
			$this->contract( 'array', 'class', ClassProperty::class, 'class-style-set', 'class-style-id-list-attribute' ),
			$this->contract( 'array', 'repeater', RepeaterGroupProperty::class, 'repeater', 'array-json-expression-attribute' ),
			$this->contract( 'object', 'group', GroupProperty::class, 'group', 'object-json-expression-attribute' ),
		);
	}

	/**
	 * @param class-string<ComponentPropertyInterface> $definition_builder Definition builder class.
	 * @return array<string, mixed>
	 */
	private function contract(
		string $primitive,
		?string $specialized,
		string $definition_builder,
		string $instance_value_kind,
		string $wire_shape
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		return array(
			'type'                 => $type,
			'definition_builder'   => $definition_builder,
			'instance_value_kinds' => array( $instance_value_kind ),
			'wire_shape'           => $wire_shape,
			'status'               => 'supported',
		);
	}
}

/**
 * Valid typed definition that is intentionally absent from the matrix.
 */
final class UnknownMatrixProperty extends BaseProperty {

	public static function new( string $name ): self {
		return new self( $name );
	}

	public function default( mixed $value ): self {
		$this->default_value = $value;
		$this->has_default   = true;

		return $this;
	}

	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
