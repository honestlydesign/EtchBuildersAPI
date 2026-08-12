<?php
/**
 * Schema-backed component instance value tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies exact schema paths own component instance wire encoding.
 */
final class SchemaBackedComponentInstanceValueTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider catalog_provider
	 */
	public function test_scalar_and_wrapped_values_cross_the_same_catalog_port( string $provider_kind ): void {
		$this->configure( $this->provider( $provider_kind ) );

		$block = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'title', ComponentInstanceValue::string( 'Hello' ) )
			->prop_value( 'count', ComponentInstanceValue::numeric_string( '12.5' ) )
			->prop_value( 'enabled', ComponentInstanceValue::boolean( true ) )
			->prop_value( 'metadata', ComponentInstanceValue::object( array( 'tone' => 'warm' ) ) )
			->prop_value( 'tags', ComponentInstanceValue::array( array( 'one', 'two' ) ) )
			->prop_value( 'accent', ComponentInstanceValue::color( '#ff00aa' ) )
			->prop_value( 'loopSource', ComponentInstanceValue::loop_reference( 'featured-posts' ) )
			->prop_value( 'targetUrl', ComponentInstanceValue::url( '/about' ) )
			->prop_value( 'image', ComponentInstanceValue::image( 'https://example.test/image.jpg' ) )
			->prop_value( 'choice', ComponentInstanceValue::select_option( 'wide' ) )
			->prop_value( 'mediaId', ComponentInstanceValue::wordpress_media_id( '42' ) )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame( 'Hello', $attributes['title'] );
		self::assertSame( '12.5', $attributes['count'] );
		self::assertSame( '{true}', $attributes['enabled'] );
		self::assertSame( '{{"tone":"warm"}}', $attributes['metadata'] );
		self::assertSame( '{["one","two"]}', $attributes['tags'] );
		self::assertSame( '#ff00aa', $attributes['accent'] );
		self::assertSame( 'featured-posts', $attributes['loopSource'] );
		self::assertSame( '/about', $attributes['targetUrl'] );
		self::assertSame( 'https://example.test/image.jpg', $attributes['image'] );
		self::assertSame( 'wide', $attributes['choice'] );
		self::assertSame( '42', $attributes['mediaId'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function catalog_provider(): array {
		return array(
			'in-memory catalog' => array( 'memory' ),
			'accepted catalog'  => array( 'accepted' ),
		);
	}

	public function test_exact_group_and_transparent_child_paths_compile_without_manual_wire_groups(): void {
		$this->configure( $this->provider( 'memory' ) );

		$block = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'styling.advanced.note', ComponentInstanceValue::string( 'Nested' ) )
			->prop_value( 'conditionalLabel', ComponentInstanceValue::string( 'Visible' ) )
			->prop_value( 'styling.label', ComponentInstanceValue::string( 'Card' ) )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame( '{{"label":"Card","advanced":{"note":"Nested"}}}', $attributes['styling'] );
		self::assertSame( 'Visible', $attributes['conditionalLabel'] );
	}

	public function test_concrete_repeater_rows_compile_in_index_and_schema_order(): void {
		$this->configure( $this->provider( 'memory' ) );

		$block = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items[1].label', ComponentInstanceValue::string( 'Second' ) )
			->prop_value( 'items[0].children[0].label', ComponentInstanceValue::string( 'Child' ) )
			->prop_value( 'items[0].styling.note', ComponentInstanceValue::string( 'Nested' ) )
			->prop_value( 'items[0].label', ComponentInstanceValue::string( 'First' ) )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame(
			'{[{"label":"First","styling":"{{\\"note\\":\\"Nested\\"}}","children":"{[{\\"label\\":\\"Child\\"}]}"},{"label":"Second"}]}',
			$attributes['items']
		);
	}

	public function test_explicit_empty_repeater_clears_its_schema_default(): void {
		$this->configure( $this->provider( 'memory' ) );

		$block = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame( '{[]}', $attributes['items'] );
	}

	/**
	 * @dataProvider invalid_assignment
	 */
	public function test_invalid_or_mismatched_paths_fail_closed( string $path, ComponentInstanceValue $value, string $message ): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentBlock::for_key( 'FeatureCard' )->prop_value( $path, $value );
	}

	/**
	 * @return array<string, array{string, ComponentInstanceValue, string}>
	 */
	public function invalid_assignment(): array {
		return array(
			'unknown path' => array(
				'invented',
				ComponentInstanceValue::string( 'x' ),
				'no exact instance value path "invented"',
			),
			'transparent declaration path' => array(
				'visibility',
				ComponentInstanceValue::string( 'x' ),
				'declaration-only transparent',
			),
			'repeater without a row index' => array(
				'items.label',
				ComponentInstanceValue::string( 'x' ),
				'requires a concrete row index',
			),
			'group used as a repeater' => array(
				'styling[0].label',
				ComponentInstanceValue::string( 'x' ),
				'is not a repeater',
			),
			'path ends at a repeater row' => array(
				'items[0]',
				ComponentInstanceValue::string( 'x' ),
				'must end at a property, not a repeater row',
			),
			'row index outside integer range' => array(
				'items[999999999999999999999999].label',
				ComponentInstanceValue::string( 'x' ),
				'repeater row index must fit the current PHP integer range',
			),
			'structural kind mismatch' => array(
				'styling',
				ComponentInstanceValue::string( 'x' ),
				'expects instance value kind "group"; got "string"',
			),
			'specialized kind mismatch' => array(
				'targetUrl',
				ComponentInstanceValue::string( '/about' ),
				'expects instance value kind "url-string"; got "string"',
			),
			'class path excluded from generic lane' => array(
				'styling.rootClass',
				ComponentInstanceValue::array( array( 'opaque-id' ) ),
				'expects instance value kind "class-style-set"; got "array"',
			),
		);
	}

	public function test_schema_lane_requires_a_component_key_and_known_catalog_contract(): void {
		$this->configure( $this->provider( 'memory' ) );

		try {
			ComponentBlock::new()
				->ref( 77 )
				->prop_value( 'title', ComponentInstanceValue::string( 'x' ) );
			self::fail( 'A numeric ref alone must not enable schema-backed props.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'requires for_key() or ref_by_key()', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no component key "Missing"' );

		ComponentBlock::for_key( 'Missing' );
	}

	public function test_duplicate_conflicting_and_sparse_assignments_fail_closed(): void {
		$this->configure( $this->provider( 'memory' ) );

		$duplicate = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'title', ComponentInstanceValue::string( 'one' ) );

		try {
			$duplicate->prop_value( 'title', ComponentInstanceValue::string( 'two' ) );
			self::fail( 'Duplicate exact paths must not silently overwrite.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'already has an assignment', $exception->getMessage() );
		}

		$conflict = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() );

		try {
			$conflict->prop_value( 'items[0].label', ComponentInstanceValue::string( 'x' ) );
			self::fail( 'A structural value and child assignment must not coexist.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'conflicts with an existing assignment', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'contiguous rows starting at index 0' );

		ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items[1].label', ComponentInstanceValue::string( 'orphan' ) )
			->to_block();
	}

	public function test_schema_and_legacy_root_values_cannot_overwrite_each_other(): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conflicts with an existing raw or legacy component attribute' );

		ComponentBlock::for_key( 'FeatureCard' )
			->prop_string( 'styling', 'raw' )
			->prop_value( 'styling.label', ComponentInstanceValue::string( 'typed' ) )
			->to_block();
	}

	public function test_value_factories_validate_shape_and_snapshot_mutable_input(): void {
		$object = array( 'nested' => array( 'value' => 'before' ) );
		$value  = ComponentInstanceValue::object( $object );

		$object['nested']['value'] = 'after';

		self::assertSame( '{{"nested":{"value":"before"}}}', $value->encode() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'numeric-string value must be a valid finite numeric string' );

		ComponentInstanceValue::numeric_string( 'twelve' );
	}

	public function test_value_factories_reject_nested_arbitrary_objects_instead_of_coercing_them(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'only scalar, null, array, and stdClass values' );

		ComponentInstanceValue::array( array( new \DateTimeImmutable() ) );
	}

	public function test_unsupported_json_serializable_objects_are_rejected_before_hooks_execute(): void {
		$probe = new SchemaBackedJsonSerializableProbe();

		try {
			ComponentInstanceValue::array( array( $probe ) );
			self::fail( 'JsonSerializable is outside the typed persisted-data boundary.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'only scalar, null, array, and stdClass values', $exception->getMessage() );
		}

		self::assertSame( 0, $probe->calls );
	}

	public function test_recursive_array_and_stdclass_payloads_fail_closed_before_snapshotting(): void {
		$recursive_array         = array();
		$recursive_array['self'] =& $recursive_array;

		try {
			ComponentInstanceValue::object( $recursive_array );
			self::fail( 'Recursive arrays must not enter typed component values.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'finite, non-recursive JSON data', $exception->getMessage() );
		}

		$recursive_object       = new \stdClass();
		$recursive_object->self = $recursive_object;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'finite, non-recursive JSON data' );

		ComponentInstanceValue::object( $recursive_object );
	}

	public function test_object_snapshots_preserve_nested_empty_and_numeric_key_object_shapes(): void {
		$value = ComponentInstanceValue::object(
			array(
				'empty'   => new \stdClass(),
				'numeric' => (object) array( '0' => 'zero', '1' => 'one' ),
			)
		);
		$array = ComponentInstanceValue::array(
			array(
				new \stdClass(),
				(object) array( '0' => 'zero' ),
			)
		);

		self::assertSame( '{{"empty":{},"numeric":{"0":"zero","1":"one"}}}', $value->encode() );
		self::assertSame( '{[{},{"0":"zero"}]}', $array->encode() );
	}

	public function test_object_factory_preserves_numeric_key_root_object_shapes(): void {
		$sequential_object = ComponentInstanceValue::object(
			(object) array( '0' => 'zero', '1' => 'one' )
		);
		$sparse_object     = ComponentInstanceValue::object( array( 2 => 'two' ) );

		self::assertSame( '{{"0":"zero","1":"one"}}', $sequential_object->encode() );
		self::assertSame( '{{"2":"two"}}', $sparse_object->encode() );
	}

	public function test_primitive_object_and_array_values_preserve_literal_json_types(): void {
		$object = ComponentInstanceValue::object(
			array(
				'enabled' => false,
				'count'   => 2,
				'value'   => null,
				'nested'  => array( 'items' => array( 1, true, null ) ),
			)
		);
		$array  = ComponentInstanceValue::array(
			array(
				false,
				2,
				null,
				array( 'nested' => array( 1, true, null ) ),
			)
		);

		self::assertSame(
			'{{"enabled":false,"count":2,"value":null,"nested":{"items":[1,true,null]}}}',
			$object->encode()
		);
		self::assertSame( '{[false,2,null,{"nested":[1,true,null]}]}', $array->encode() );
	}

	/**
	 * @dataProvider parser_unsafe_trailing_backslash_value
	 */
	public function test_literal_json_rejects_strings_or_object_keys_ending_in_a_backslash( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot end in a backslash' );

		$factory();
	}

	/**
	 * @return array<string, array{callable(): ComponentInstanceValue}>
	 */
	public function parser_unsafe_trailing_backslash_value(): array {
		return array(
			'object value' => array(
				static fn (): ComponentInstanceValue => ComponentInstanceValue::object( array( 'value' => 'ends\\' ) ),
			),
			'array value' => array(
				static fn (): ComponentInstanceValue => ComponentInstanceValue::array( array( 'ends\\' ) ),
			),
			'object key' => array(
				static fn (): ComponentInstanceValue => ComponentInstanceValue::object( array( 'ends\\' => 'value' ) ),
			),
			'stdClass key' => array(
				static fn (): ComponentInstanceValue => ComponentInstanceValue::object( (object) array( 'ends\\' => 'value' ) ),
			),
		);
	}

	/**
	 * @dataProvider expression_shaped_literal
	 */
	public function test_expression_shaped_literals_are_blocked_until_the_checked_expression_lane_exists(
		callable $factory
	): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Expression-shaped strings are not allowed in schema-backed literal values' );

		$factory();
	}

	/**
	 * @return array<string, array{callable(): ComponentInstanceValue}>
	 */
	public function expression_shaped_literal(): array {
		return array(
			'plain string'       => array( static fn (): ComponentInstanceValue => ComponentInstanceValue::string( '{dangerous()}' ) ),
			'embedded template'  => array( static fn (): ComponentInstanceValue => ComponentInstanceValue::string( 'Hello {this.title}' ) ),
			'specialized string' => array( static fn (): ComponentInstanceValue => ComponentInstanceValue::url( ' {props.url} ' ) ),
			'nested object'      => array( static fn (): ComponentInstanceValue => ComponentInstanceValue::object( array( 'value' => 'Hi {this.title}' ) ) ),
			'nested array'       => array( static fn (): ComponentInstanceValue => ComponentInstanceValue::array( array( '/posts/{this.slug}' ) ) ),
		);
	}

	public function test_schema_backed_method_signature_is_typed_and_legacy_methods_remain_available(): void {
		$method     = new ReflectionMethod( ComponentBlock::class, 'prop_value' );
		$parameters = $method->getParameters();

		self::assertCount( 2, $parameters );
		self::assertSame( 'string', (string) $parameters[0]->getType() );
		self::assertSame( ComponentInstanceValue::class, (string) $parameters[1]->getType() );
		self::assertTrue( method_exists( ComponentBlock::class, 'prop_string' ) );
		self::assertTrue( method_exists( ComponentBlock::class, 'prop_group' ) );
		self::assertTrue( method_exists( ComponentBlock::class, 'prop_repeater' ) );
	}

	private function configure( ComponentContractCatalogProviderInterface $provider ): void {
		Environment::configure(
			new NullStorage(),
			new NullMode(),
			new NullAssetRegistry(),
			new SchemaBackedRefResolver(),
			$provider
		);
	}

	private function provider( string $kind ): ComponentContractCatalogProviderInterface {
		$catalog = $this->catalog();

		return 'accepted' === $kind
			? AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )
			: InMemoryComponentContractCatalogProvider::from_catalog( $catalog );
	}

	private function catalog(): ComponentContractCatalog {
		$contract = ComponentContract::from_schema(
			'FeatureCard',
			array(
				$this->property( 'Title', 'title', 'string' ),
				$this->property( 'Count', 'count', 'number' ),
				$this->property( 'Enabled', 'enabled', 'boolean' ),
				$this->property( 'Metadata', 'metadata', 'object' ),
				$this->property( 'Tags', 'tags', 'array' ),
				$this->property( 'Accent', 'accent', 'string', 'color' ),
				$this->property( 'Loop Source', 'loopSource', 'string', 'array' ),
				$this->property( 'Target URL', 'targetUrl', 'string', 'url' ),
				$this->property( 'Image', 'image', 'string', 'image' ),
				$this->property( 'Choice', 'choice', 'string', 'select' ),
				$this->property( 'Media ID', 'mediaId', 'string', 'wpMediaId' ),
				$this->property(
					'Visibility',
					'visibility',
					'string',
					'condition',
					array( $this->property( 'Conditional Label', 'conditionalLabel', 'string' ) )
				),
				$this->property(
					'Styling',
					'styling',
					'object',
					'group',
					array(
						$this->property( 'Label', 'label', 'string' ),
						$this->property( 'Root Class', 'rootClass', 'array', 'class' ),
						$this->property(
							'Advanced',
							'advanced',
							'object',
							'group',
							array( $this->property( 'Note', 'note', 'string' ) )
						),
					)
				),
				$this->property(
					'Items',
					'items',
					'array',
					'repeater',
					array(
						$this->property( 'Label', 'label', 'string' ),
						$this->property(
							'Styling',
							'styling',
							'object',
							'group',
							array( $this->property( 'Note', 'note', 'string' ) )
						),
						$this->property(
							'Children',
							'children',
							'array',
							'repeater',
							array( $this->property( 'Label', 'label', 'string' ) )
						),
					)
				),
			),
			array()
		);

		$missing = ComponentContract::from_schema( 'KnownButUnused', array(), array() );

		return ComponentContractCatalog::from_contracts( $contract, $missing );
	}

	/**
	 * @param array<int, array<string, mixed>>|null $children Child definitions.
	 * @return array<string, mixed>
	 */
	private function property(
		string $name,
		string $key,
		string $primitive,
		?string $specialized = null,
		?array $children = null
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		$property = array(
			'name' => $name,
			'key'  => $key,
			'type' => $type,
		);
		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}

	/**
	 * @return array<string, string>
	 */
	private function extract_attributes( string $markup ): array {
		preg_match( '/<!-- wp:etch\/component (\{.*?\}) -->/s', $markup, $matches );
		self::assertNotEmpty( $matches, 'Failed to find component block attrs in: ' . $markup );

		$block_attributes = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

		return $block_attributes['attributes'];
	}
}

/**
 * Deterministic component ref lookup for schema-backed block tests.
 */
final class SchemaBackedRefResolver implements ComponentRefResolverInterface {

	public function ref_by_key( string $component_key ): int {
		return array_search( $component_key, array( 77 => 'FeatureCard', 88 => 'Missing', 99 => 'KnownButUnused' ), true ) ?: 0;
	}

	public function key_by_ref( int $ref ): ?string {
		return array( 77 => 'FeatureCard', 88 => 'Missing', 99 => 'KnownButUnused' )[ $ref ] ?? null;
	}
}

/**
 * Proves validation never invokes unsupported serialization hooks.
 */
final class SchemaBackedJsonSerializableProbe implements \JsonSerializable {

	public int $calls = 0;

	public function jsonSerialize(): mixed {
		++$this->calls;

		return array( 'unexpected' => true );
	}
}
