<?php
/**
 * Stable class-style migration diagnostic tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleDiagnostic;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup;
use HonestlyDesign\EtchBuilders\Exceptions\ClassStyleDiagnosticException;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies machine-checkable, prescriptive class-style migration failures.
 */
final class ClassStyleDiagnosticTest extends TestCase {

	public function test_public_code_catalog_contains_the_six_stable_migration_diagnostics(): void {
		self::assertSame(
			array(
				ClassStyleDiagnostic::UNKNOWN_ID,
				ClassStyleDiagnostic::NON_CLASS_STYLE,
				ClassStyleDiagnostic::CLASS_NAME_INPUT,
				ClassStyleDiagnostic::RUNTIME_TOKEN,
				ClassStyleDiagnostic::COMPOUND_SELECTOR,
				ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL,
			),
			ClassStyleDiagnostic::codes()
		);
	}

	public function test_unknown_id_has_a_stable_prescriptive_diagnostic_without_writes(): void {
		$this->assert_reference_failure(
			'missing-style-id',
			array(),
			ClassStyleDiagnostic::UNKNOWN_ID,
			'ClassStyleReference::registered()',
			'missing-style-id'
		);
	}

	/**
	 * @dataProvider class_name_input_provider
	 */
	public function test_class_name_input_names_the_matching_opaque_id_without_writes( string $input ): void {
		$this->assert_reference_failure(
			$input,
			array(
				'opaque-style-id' => array(
					'selector' => '.visual-class',
					'type'     => 'class',
				),
			),
			ClassStyleDiagnostic::CLASS_NAME_INPUT,
			'opaque-style-id',
			'ClassStyleReference::registered()'
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function class_name_input_provider(): array {
		return array(
			'class token' => array( 'visual-class' ),
			'selector'    => array( '.visual-class' ),
		);
	}

	public function test_non_class_style_has_a_stable_prescriptive_diagnostic_without_writes(): void {
		$this->assert_reference_failure(
			'opaque-style-id',
			array(
				'opaque-style-id' => array(
					'selector' => '#target',
					'type'     => 'id',
				),
			),
			ClassStyleDiagnostic::NON_CLASS_STYLE,
			'type=class',
			'ClassStyleReference::registered()'
		);
	}

	public function test_runtime_token_has_a_stable_prescriptive_diagnostic_without_writes(): void {
		$this->assert_reference_failure(
			'rt-active',
			array(),
			ClassStyleDiagnostic::RUNTIME_TOKEN,
			'element HTML class',
			'ClassStyleReference::registered()'
		);
	}

	public function test_compound_selector_has_a_stable_prescriptive_diagnostic_without_writes(): void {
		$this->assert_reference_failure(
			'opaque-style-id',
			array(
				'opaque-style-id' => array(
					'selector' => '.card:hover',
					'type'     => 'class',
				),
			),
			ClassStyleDiagnostic::COMPOUND_SELECTOR,
			'exactly one simple class selector',
			'pseudo, descendant, state, and media rules'
		);
	}

	public function test_failures_remain_catchable_as_invalid_argument_exceptions(): void {
		try {
			ClassStyleReference::registered( 'missing-style-id' );
			self::fail( 'Unknown IDs must fail.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertInstanceOf( ClassStyleDiagnosticException::class, $exception );
			self::assertSame( ClassStyleDiagnostic::UNKNOWN_ID, $exception->diagnostic_code() );
		}
	}

	public function test_component_prop_class_emits_legacy_diagnostic_and_keeps_wire_format(): void {
		$style_state = Style::snapshot_state();
		$messages    = array();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();
			Style::new()
				->id( 'opaque-style-id' )
				->selector( '.visual-class' )
				->css( 'color:red' )
				->type( 'class' )
				->add();

			$this->capture_deprecations( $messages );
			$markup = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'opaque-style-id' ) )
				->to_block()
				->to_string();

			self::assertStringContainsString( '"classes":"opaque-style-id"', $markup );
			self::assertCount( 1, $messages );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL . ']', $messages[0] );
			self::assertStringContainsString( 'class_prop()', $messages[0] );
		} finally {
			restore_error_handler();
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_legacy_runtime_token_emits_both_migration_diagnostics_and_keeps_wire_format(): void {
		$messages = array();

		try {
			$this->capture_deprecations( $messages );
			$markup = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'rt-active' ) )
				->to_block()
				->to_string();

			self::assertStringContainsString( '"classes":"rt-active"', $markup );
			self::assertCount( 2, $messages );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL . ']', $messages[0] );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::RUNTIME_TOKEN . ']', $messages[1] );
			self::assertStringContainsString( 'element HTML class', $messages[1] );
		} finally {
			restore_error_handler();
		}
	}

	public function test_group_class_emits_legacy_diagnostic_and_keeps_wire_format(): void {
		$messages = array();

		try {
			$this->capture_deprecations( $messages );
			$encoded = ComponentPropGroup::new()
				->class( 'rootClass', array( 'raw-b', 'raw-a' ) )
				->encode();

			self::assertSame( '{{"rootClass":"raw-b raw-a"}}', $encoded );
			self::assertCount( 1, $messages );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL . ']', $messages[0] );
			self::assertStringContainsString( 'class_prop()', $messages[0] );
		} finally {
			restore_error_handler();
		}
	}

	public function test_group_runtime_token_emits_both_migration_diagnostics_and_keeps_wire_format(): void {
		$messages = array();

		try {
			$this->capture_deprecations( $messages );
			$encoded = ComponentPropGroup::new()
				->class( 'rootClass', array( 'rt-active' ) )
				->encode();

			self::assertSame( '{{"rootClass":"rt-active"}}', $encoded );
			self::assertCount( 2, $messages );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL . ']', $messages[0] );
			self::assertStringContainsString( '[' . ClassStyleDiagnostic::RUNTIME_TOKEN . ']', $messages[1] );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $styles Persisted Etch styles.
	 */
	private function assert_reference_failure(
		string $input,
		array $styles,
		string $expected_code,
		string $first_message_fragment,
		string $second_message_fragment
	): void {
		$style_state = Style::snapshot_state();
		$storage     = new ClassStyleDiagnosticStorage( array( 'etch_styles' => $styles ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			ClassStyleRegistry::reset_cache();
			$before = Style::snapshot_state();

			try {
				ClassStyleReference::registered( $input );
				self::fail( 'Invalid class-style input must fail.' );
			} catch ( ClassStyleDiagnosticException $exception ) {
				self::assertSame( $expected_code, $exception->diagnostic_code() );
				self::assertStringStartsWith( '[' . $expected_code . ']', $exception->getMessage() );
				self::assertStringContainsString( $first_message_fragment, $exception->getMessage() );
				self::assertStringContainsString( $second_message_fragment, $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $styles, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	/**
	 * @param array<int, string> $messages Captured deprecation messages.
	 */
	private function capture_deprecations( array &$messages ): void {
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$messages ): bool {
				if ( E_USER_DEPRECATED === $severity ) {
					$messages[] = $message;
					return true;
				}

				return false;
			}
		);
	}
}

/**
 * Storage spy proving diagnostic classification is observational.
 */
final class ClassStyleDiagnosticStorage implements StorageInterface {

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
