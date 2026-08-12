<?php
/**
 * Recursive component prop value encoder tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropArray;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropRepeater;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

/**
 * Verifies ClassStyleSet remains typed until recursive wire encoding.
 */
final class ComponentPropValueEncoderTest extends TestCase {

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
 *     retained_persisted_identities: array<array-key, array{selector: string, type: string, collection: string}>
	 * }
	 */
	private array $original_style_state;

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
 *     retained_persisted_identities: array<array-key, array{selector: string, type: string, collection: string}>
	 * }
	 */
	private array $clean_style_state;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $persisted;

	private RecursiveClassValueStorage $storage;

	private ClassStyleSet $classes;

	protected function setUp(): void {
		parent::setUp();

		$this->original_style_state = Style::snapshot_state();
		$this->persisted            = array(
			'first-opaque-id'  => array(
				'selector' => '.first-visual-class',
				'type'     => 'class',
			),
			'second-opaque-id' => array(
				'selector' => '.second-visual-class',
				'type'     => 'class',
			),
		);
		$this->storage              = new RecursiveClassValueStorage( array( 'etch_styles' => $this->persisted ) );

		Style::reset();
		Environment::configure( $this->storage, new NullMode(), new NullAssetRegistry() );
		ClassStyleRegistry::reset_cache();

		$first                   = ClassStyleReference::registered( 'first-opaque-id' );
		$second                  = ClassStyleReference::registered( 'second-opaque-id' );
		$this->classes           = ClassStyleSet::of( $second, $first );
		$this->clean_style_state = Style::snapshot_state();
	}

	protected function tearDown(): void {
		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::restore_state( $this->original_style_state );

		parent::tearDown();
	}

	public function test_group_class_prop_preserves_the_typed_set_until_encode(): void {
		$group = ComponentPropGroup::new()
			->class_prop( 'rootClass', $this->classes );

		self::assertSame( $this->classes, $group->to_array()['rootClass'] );
		self::assertSame(
			'{{"rootClass":"second-opaque-id first-opaque-id"}}',
			$group->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	public function test_nested_group_encodes_the_typed_set_recursively(): void {
		$group = ComponentPropGroup::new()
			->group(
				'styling',
				ComponentPropGroup::new()->class_prop( 'rootClass', $this->classes )
			);

		self::assertSame(
			'{{"styling":{"rootClass":"second-opaque-id first-opaque-id"}}}',
			$group->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	public function test_repeater_row_encodes_a_direct_typed_class_value(): void {
		$row = ComponentPropGroup::new()
			->string( 'title', 'Row' )
			->class_prop( 'itemClass', $this->classes );
		$repeater = ComponentPropRepeater::new()->item( $row );

		self::assertSame(
			'{[{"title":"Row","itemClass":"second-opaque-id first-opaque-id"}]}',
			$repeater->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	public function test_nested_group_inside_repeater_keeps_the_existing_group_string_wrapper(): void {
		$row = ComponentPropGroup::new()
			->group(
				'styling',
				ComponentPropGroup::new()->class_prop( 'rootClass', $this->classes )
			);
		$repeater = ComponentPropRepeater::new()->item( $row );

		self::assertSame(
			'{[{"styling":"{{\\"rootClass\\":\\"second-opaque-id first-opaque-id\\"}}"}]}',
			$repeater->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	/**
	 * @dataProvider object_payload_provider
	 */
	public function test_object_payloads_encode_the_typed_set_recursively( array|stdClass $payload ): void {
		if ( is_array( $payload ) ) {
			$payload['rootClass'] = $this->classes;
		} else {
			$payload->rootClass = $this->classes;
		}

		$group = ComponentPropGroup::new()->value( 'settings', $payload );

		self::assertSame(
			'{{"settings":{"label":"Card","rootClass":"second-opaque-id first-opaque-id"}}}',
			$group->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	/**
	 * @dataProvider object_payload_provider
	 */
	public function test_wrapped_object_method_preserves_its_existing_group_string_wire( array|stdClass $payload ): void {
		if ( is_array( $payload ) ) {
			$payload['rootClass'] = $this->classes;
		} else {
			$payload->rootClass = $this->classes;
		}

		$group = ComponentPropGroup::new()->object( 'settings', $payload );

		self::assertSame(
			'{{"settings":"{{\\"label\\":\\"Card\\",\\"rootClass\\":\\"second-opaque-id first-opaque-id\\"}}"}}',
			$group->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	/**
	 * @return array<string, array{array<string, string>|stdClass}>
	 */
	public function object_payload_provider(): array {
		return array(
			'associative array' => array( array( 'label' => 'Card' ) ),
			'stdClass'          => array( (object) array( 'label' => 'Card' ) ),
		);
	}

	public function test_component_prop_array_group_object_encodes_the_typed_set_recursively(): void {
		$array = ComponentPropArray::new()
			->object(
				ComponentPropGroup::new()->class_prop( 'rootClass', $this->classes )
			);

		self::assertSame(
			'{[{"rootClass":"second-opaque-id first-opaque-id"}]}',
			$array->encode()
		);
		$this->assert_encoding_is_read_only();
	}

	public function test_none_encodes_as_an_explicit_empty_value_at_recursive_locations(): void {
		$none     = ClassStyleSet::none();
		$group    = ComponentPropGroup::new()->class_prop( 'rootClass', $none );
		$repeater = ComponentPropRepeater::new()->item(
			ComponentPropGroup::new()->class_prop( 'itemClass', $none )
		);

		self::assertSame( $none, $group->to_array()['rootClass'] );
		self::assertSame( '{{"rootClass":""}}', $group->encode() );
		self::assertSame( '{[{"itemClass":""}]}', $repeater->encode() );
		$this->assert_encoding_is_read_only();
	}

	public function test_raw_group_class_remains_functional_and_deprecated(): void {
		$group  = ComponentPropGroup::new()->class( 'rootClass', array( 'raw-b', 'raw-a', 'raw-b' ) );
		$method = new ReflectionMethod( ComponentPropGroup::class, 'class' );

		self::assertSame( 'raw-b raw-a raw-b', $group->to_array()['rootClass'] );
		self::assertSame( '{{"rootClass":"raw-b raw-a raw-b"}}', $group->encode() );
		self::assertStringContainsString( '@deprecated', (string) $method->getDocComment() );
	}

	public function test_unsupported_arbitrary_objects_still_fail_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Component group payload values must be' );

		ComponentPropGroup::new()
			->value( 'rootClass', new \DateTimeImmutable() )
			->encode();
	}

	private function assert_encoding_is_read_only(): void {
		self::assertSame( $this->clean_style_state, Style::snapshot_state() );
		self::assertSame( $this->persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}
}

/**
 * Storage spy proving recursive encoding is read-only.
 */
final class RecursiveClassValueStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @param array<string, mixed> $values Initial values.
	 */
	public function __construct( private array $values = array() ) {
	}

	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function set( string $key, mixed $value ): bool {
		++$this->set_calls;
		$this->values[ $key ] = $value;

		return true;
	}

	public function delete( string $key ): bool {
		++$this->delete_calls;
		unset( $this->values[ $key ] );

		return true;
	}
}
