<?php
/**
 * Component Contract Catalog provider adapter tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentPropertyPathContract;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies pure and accepted runtime adapters cross one catalog port.
 */
final class ComponentContractCatalogProviderTest extends TestCase {

	public function test_in_memory_provider_exposes_the_same_immutable_catalog(): void {
		$catalog  = $this->catalog();
		$provider = InMemoryComponentContractCatalogProvider::from_catalog( $catalog );

		self::assertInstanceOf( ComponentContractCatalogProviderInterface::class, $provider );
		self::assertSame( $catalog, $provider->catalog() );
		self::assertSame( $catalog->to_array(), $provider->catalog()->to_array() );

		$empty = InMemoryComponentContractCatalogProvider::empty();
		self::assertSame( array( 'components' => array() ), $empty->catalog()->to_array() );
	}

	public function test_accepted_array_and_json_round_trip_the_model_projection(): void {
		$expected = $this->catalog()->to_array();
		$array    = AcceptedComponentContractCatalogProvider::from_array( $expected );
		$json     = AcceptedComponentContractCatalogProvider::from_json(
			(string) json_encode( $expected, JSON_THROW_ON_ERROR )
		);

		self::assertInstanceOf( ComponentContractCatalogProviderInterface::class, $array );
		self::assertInstanceOf( ComponentContractCatalogProviderInterface::class, $json );
		self::assertSame( $expected, $array->catalog()->to_array() );
		self::assertSame( $expected, $json->catalog()->to_array() );
	}

	public function test_accepted_projection_allows_reordered_json_object_keys(): void {
		$expected = $this->catalog()->to_array();
		$component = $expected['components'][0];
		$property  = $component['properties'][0];
		$contract  = $property['property_contract'];

		$property['property_contract'] = array(
			'status'               => $contract['status'],
			'wire_shape'           => $contract['wire_shape'],
			'instance_value_kinds' => $contract['instance_value_kinds'],
			'definition_builder'   => $contract['definition_builder'],
			'type'                 => $contract['type'],
		);

		$component['properties'][0] = array(
			'has_default'      => $property['has_default'],
			'default'          => $property['default'],
			'property_contract' => $property['property_contract'],
			'value_path'       => $property['value_path'],
			'declaration_path' => $property['declaration_path'],
		);

		$reordered = array(
			'components' => array(
				array(
					'class_property_paths' => $component['class_property_paths'],
					'recipe_ids'           => $component['recipe_ids'],
					'status'               => $component['status'],
					'slots'                => $component['slots'],
					'properties'           => $component['properties'],
					'component_key'        => $component['component_key'],
				),
			),
		);

		$provider = AcceptedComponentContractCatalogProvider::from_array( $reordered );

		self::assertSame( $expected, $provider->catalog()->to_array() );
	}

	public function test_json_numeric_object_default_is_independent_of_member_order(): void {
		$contract = ComponentContract::from_schema(
			'NumericObject',
			array(
				array(
					'name'    => 'Settings',
					'key'     => 'settings',
					'type'    => array( 'primitive' => 'object' ),
					'default' => array( 1 => 'b', 0 => 'a' ),
				),
			),
			array()
		);
		$catalog   = ComponentContractCatalog::from_contracts( $contract );
		$canonical = (string) json_encode( $catalog->to_array(), JSON_THROW_ON_ERROR );
		$reordered = str_replace( '{"1":"b","0":"a"}', '{"0":"a","1":"b"}', $canonical );
		$accepted  = AcceptedComponentContractCatalogProvider::from_json( $reordered )->catalog();

		self::assertSame(
			array( 1 => 'b', 0 => 'a' ),
			$accepted->contract( 'NumericObject' )->property_by_value_path( 'settings' )->default_value()
		);
	}

	public function test_accepted_provider_snapshots_input_state(): void {
		$recipe_id = 'component.feature-card';
		$payload   = $this->catalog()->to_array();
		$payload['components'][0]['status']        = 'supported';
		$payload['components'][0]['recipe_ids'][0] =& $recipe_id;
		$provider = AcceptedComponentContractCatalogProvider::from_array( $payload );

		$payload['components'][0]['properties'][0]['default'] = 'changed';
		$payload['components'][0]['slots'][0]                 = 'changed';
		$recipe_id                                            = 'invalid recipe ID';

		self::assertSame( 'Hello', $provider->catalog()->contract( 'FeatureCard' )->property_by_value_path( 'title' )->default_value() );
		self::assertSame( array( 'default', 'actions' ), $provider->catalog()->contract( 'FeatureCard' )->slots() );
		self::assertSame( array( 'component.feature-card' ), $provider->catalog()->contract( 'FeatureCard' )->recipe_ids() );
	}

	public function test_accepted_provider_does_not_write_through_caller_owned_references(): void {
		$contract = ComponentContract::from_schema(
			'ReferencedDefault',
			array(
				array(
					'name'    => 'Settings',
					'key'     => 'settings',
					'type'    => array( 'primitive' => 'object' ),
					'default' => array( 1 => array( 'nested' => array( 'value' => 'before' ) ), 0 => 'a' ),
				),
			),
			array()
		);
		$payload  = ComponentContractCatalog::from_contracts( $contract )->to_array();
		$external = array( 1 => array( 'nested' => array( 'value' => 'before' ) ), 0 => 'a' );
		$payload['components'][0]['properties'][0]['default'] =& $external;

		AcceptedComponentContractCatalogProvider::from_array( $payload );

		self::assertSame( array( 1 => array( 'nested' => array( 'value' => 'before' ) ), 0 => 'a' ), $external );

		$copy                         = $external;
		$copy[1]['nested']['value'] = 'after';

		self::assertSame( 'before', $external[1]['nested']['value'] );
	}

	public function test_json_provider_rejects_integer_overflow_without_rounding(): void {
		$canonical_json = (string) json_encode( $this->catalog()->to_array(), JSON_THROW_ON_ERROR );
		$overflow_json  = str_replace( '"default":"Hello"', '"default":12345678901234567890', $canonical_json );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'integer outside the exact PHP integer range' );

		AcceptedComponentContractCatalogProvider::from_json( $overflow_json );
	}

	public function test_accepted_provider_round_trips_transparent_conditions_inside_repeaters(): void {
		$contract = ComponentContract::from_schema(
			'ConditionalRepeater',
			array(
				array(
					'name'       => 'Items',
					'key'        => 'items',
					'type'       => array( 'primitive' => 'array', 'specialized' => 'repeater' ),
					'properties' => array(
						array(
							'name'       => 'Visibility',
							'key'        => 'visibility',
							'type'       => array( 'primitive' => 'string', 'specialized' => 'condition' ),
							'properties' => array(
								array(
									'name' => 'Item Class',
									'key'  => 'itemClass',
									'type' => array( 'primitive' => 'array', 'specialized' => 'class' ),
								),
							),
						),
					),
				),
			),
			array()
		);
		$catalog  = ComponentContractCatalog::from_contracts( $contract );
		$accepted = AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )->catalog();

		self::assertSame( $catalog->to_array(), $accepted->to_array() );
		self::assertSame(
			'items[].itemClass',
			$accepted->contract( 'ConditionalRepeater' )->property_by_declaration_path( 'items.visibility.itemClass' )->value_path()
		);
		self::assertSame( array( 'items[].itemClass' ), $accepted->contract( 'ConditionalRepeater' )->class_property_paths() );
	}

	/**
	 * @dataProvider invalid_accepted_catalog_provider
	 * @param array<string, mixed> $payload Accepted catalog candidate.
	 */
	public function test_accepted_provider_rejects_malformed_or_forged_records( array $payload, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		AcceptedComponentContractCatalogProvider::from_array( $payload );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function invalid_accepted_catalog_provider(): array {
		$extra_top = $this->catalog()->to_array();
		$extra_top['invented'] = true;

		$forged_builder = $this->catalog()->to_array();
		$forged_builder['components'][0]['properties'][0]['property_contract']['definition_builder'] = 'Invented\\UrlProperty';

		$forged_class_paths = $this->catalog()->to_array();
		$forged_class_paths['components'][0]['class_property_paths'] = array( 'title' );

		$contradictory_default = $this->catalog()->to_array();
		$contradictory_default['components'][0]['properties'][0]['has_default'] = false;

		$unknown_component_field = $this->catalog()->to_array();
		$unknown_component_field['components'][0]['implementation'] = '<!-- wp:etch/text /-->';

		$orphan_child = $this->catalog()->to_array();
		array_splice( $orphan_child['components'][0]['properties'], 1, 1 );

		$wrong_child_path = $this->catalog()->to_array();
		$wrong_child_path['components'][0]['properties'][2]['value_path'] = 'rootClass';

		$duplicate_slots = $this->catalog()->to_array();
		$duplicate_slots['components'][0]['slots'][] = 'default';

		$reentered_subtree = $this->catalog()->to_array();
		$properties = $reentered_subtree['components'][0]['properties'];
		$reentered_subtree['components'][0]['properties'] = array( $properties[1], $properties[0], $properties[2] );

		return array(
			'extra catalog field' => array( $extra_top, 'exactly the keys: components' ),
			'forged builder' => array( $forged_builder, 'canonical Property Contract Matrix record' ),
			'forged class paths' => array( $forged_class_paths, 'class_property_paths must match' ),
			'contradictory default' => array( $contradictory_default, 'has_default=false cannot include default' ),
			'copied implementation' => array( $unknown_component_field, 'component record must contain exactly' ),
			'orphan child' => array( $orphan_child, 'missing parent declaration path "styling"' ),
			'wrong child value path' => array( $wrong_child_path, 'does not match its structural parent' ),
			'duplicate normalized slot' => array( $duplicate_slots, 'canonical model projection' ),
			'reentered completed subtree' => array( $reentered_subtree, 're-enters a completed declaration subtree' ),
		);
	}

	public function test_accepted_array_rejects_recursive_property_contract_values(): void {
		$payload     = $this->catalog()->to_array();
		$recursive   = array();
		$recursive[] = &$recursive;
		$payload['components'][0]['properties'][0]['property_contract']['instance_value_kinds'] = $recursive;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-recursive arrays' );

		AcceptedComponentContractCatalogProvider::from_array( $payload );
	}

	public function test_direct_property_hydrator_rejects_recursive_arrays(): void {
		$record      = $this->catalog()->to_array()['components'][0]['properties'][0];
		$recursive   = array();
		$recursive[] = &$recursive;
		$record['property_contract']['instance_value_kinds'] = $recursive;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-recursive arrays' );

		ComponentPropertyPathContract::from_array( $record );
	}

	public function test_direct_component_hydrator_rejects_recursive_arrays(): void {
		$record      = $this->catalog()->to_array()['components'][0];
		$recursive   = array();
		$recursive[] = &$recursive;
		$record['class_property_paths'] = $recursive;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-recursive arrays' );

		ComponentContract::from_array( $record );
	}

	public function test_direct_catalog_hydrator_rejects_recursive_arrays(): void {
		$record      = $this->catalog()->to_array();
		$recursive   = array();
		$recursive[] = &$recursive;
		$record['components'][0]['slots'] = $recursive;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-recursive arrays' );

		ComponentContractCatalog::from_array( $record );
	}

	/**
	 * @dataProvider invalid_json_provider
	 */
	public function test_accepted_json_fails_closed( string $json, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		AcceptedComponentContractCatalogProvider::from_json( $json );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function invalid_json_provider(): array {
		$canonical_json       = (string) json_encode( $this->catalog()->to_array(), JSON_THROW_ON_ERROR );
		$numeric_object_value = str_replace( '"default":"Hello"', '"default":{"0":"Hello"}', $canonical_json );
		$empty_object_value   = str_replace( '"default":"Hello"', '"default":{}', $canonical_json );

		return array(
			'empty' => array( '', 'valid JSON object' ),
			'broken' => array( '{', 'valid JSON object' ),
			'list root' => array( '[]', 'JSON object with a components field' ),
			'scalar root' => array( 'true', 'JSON object with a components field' ),
			'object components' => array( '{"components":{}}', 'components must be a JSON list' ),
			'object properties' => array(
				'{"components":[{"component_key":"Empty","properties":{},"slots":[],"class_property_paths":[],"status":"pending","recipe_ids":[]}]}',
				'properties must be a JSON list',
			),
			'object slots' => array(
				'{"components":[{"component_key":"Empty","properties":[],"slots":{},"class_property_paths":[],"status":"pending","recipe_ids":[]}]}',
				'slots must be a JSON list',
			),
			'object class paths' => array(
				'{"components":[{"component_key":"Empty","properties":[],"slots":[],"class_property_paths":{},"status":"pending","recipe_ids":[]}]}',
				'class_property_paths must be a JSON list',
			),
			'numeric object default' => array( $numeric_object_value, 'canonical model projection without object/list substitutions' ),
			'empty object default' => array( $empty_object_value, 'canonical model projection without object/list substitutions' ),
		);
	}

	private function catalog(): ComponentContractCatalog {
		$contract = ComponentContract::from_schema(
			'FeatureCard',
			array(
				array(
					'name'    => 'Title',
					'key'     => 'title',
					'type'    => array( 'primitive' => 'string' ),
					'default' => 'Hello',
				),
				array(
					'name'       => 'Styling',
					'key'        => 'styling',
					'type'       => array(
						'primitive'   => 'object',
						'specialized' => 'group',
					),
					'properties' => array(
						array(
							'name' => 'Root Class',
							'key'  => 'rootClass',
							'type' => array(
								'primitive'   => 'array',
								'specialized' => 'class',
							),
						),
					),
				),
			),
			array( 'default', 'actions' )
		);

		return ComponentContractCatalog::from_contracts( $contract );
	}
}
