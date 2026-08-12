<?php
/**
 * Contract Lab runtime-shape and block-wire probe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ContractLabBlockRoundTripProbe;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabRuntimeShapeObservation;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Proves that runtime shape and wire probes remain normalized and fail closed.
 */
final class ContractLabRuntimeShapeTest extends TestCase {

	public function test_runtime_shape_projects_only_required_public_facts(): void {
		$component = ComponentContract::from_schema(
			'FeatureCard',
			array(
				array(
					'name'    => 'Title',
					'key'     => 'title',
					'type'    => array( 'primitive' => 'string' ),
					'default' => 'Hello',
				),
				array(
					'name'       => 'Class',
					'key'        => 'className',
					'type'       => array( 'primitive' => 'array', 'specialized' => 'class' ),
				),
			),
			array( 'default' )
		);

		$shape = ContractLabRuntimeShapeObservation::from_public_surfaces(
			array( 'etch/text', 'etch/element' ),
			array(
				array(
					'name'       => 'core/paragraph',
					'attributes' => array( 'content' => array( 'type' => 'string' ) ),
				),
				array(
					'name'       => 'etch/text',
					'attributes' => array( 'content' => array( 'type' => 'string', 'default' => '' ) ),
				),
				array(
					'name'       => 'etch/element',
					'attributes' => array( 'tag' => array( 'type' => 'string', 'default' => 'div' ) ),
				),
			),
			ComponentContractCatalog::from_contracts( $component ),
			array( 'FeatureCard' )
		);

		self::assertSame(
			array(
				'observation_version' => '1',
				'required_blocks'     => array( 'etch/text', 'etch/element' ),
				'blocks'              => array(
					array(
						'name'       => 'etch/text',
						'attributes' => array(
							array( 'name' => 'content', 'types' => array( 'string' ), 'has_default' => true, 'default' => '' ),
						),
					),
					array(
						'name'       => 'etch/element',
						'attributes' => array(
							array( 'name' => 'tag', 'types' => array( 'string' ), 'has_default' => true, 'default' => 'div' ),
						),
					),
				),
				'components' => array(
					array(
						'component_key'        => 'FeatureCard',
						'properties'           => array(
							array(
								'declaration_path' => 'title',
								'value_path'       => 'title',
								'type'              => array( 'primitive' => 'string' ),
								'has_default'       => true,
								'default'           => 'Hello',
							),
							array(
								'declaration_path' => 'className',
								'value_path'       => 'className',
								'type'              => array( 'primitive' => 'array', 'specialized' => 'class' ),
								'has_default'       => false,
							),
						),
						'slots'                => array( 'default' ),
						'class_property_paths' => array( 'className' ),
					),
				),
			),
			$shape->to_array()
		);

		self::assertStringNotContainsString( 'definition_builder', json_encode( $shape->to_array(), JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'recipe_ids', json_encode( $shape->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_runtime_shape_rejects_missing_or_malformed_required_public_facts(): void {
		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'Required block "etch/text" is not registered' );

		ContractLabRuntimeShapeObservation::from_public_surfaces(
			array( 'etch/text' ),
			array(
				array( 'name' => 'etch/element', 'attributes' => array() ),
			),
			ComponentContractCatalog::from_contracts(),
			array()
		);
	}

	public function test_round_trip_uses_adapter_and_preserves_nested_order(): void {
		$adapter = new class implements ContractLabBlockWireAdapterInterface {
			public function parse( string $markup ): array {
				return 'serialized' === $markup
					? array(
						array(
							'blockName'   => 'etch/element',
							'attrs'       => array( 'tag' => 'section' ),
							'innerBlocks' => array(
								array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'First' ), 'innerBlocks' => array() ),
								array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'Second' ), 'innerBlocks' => array() ),
							),
						)
					)
					: array(
						array(
							'blockName'   => 'etch/element',
							'attrs'       => array( 'tag' => 'section' ),
							'innerBlocks' => array(
								array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'First' ), 'innerBlocks' => array() ),
								array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'Second' ), 'innerBlocks' => array() ),
							),
						)
					);
			}

			public function serialize( array $blocks ): string {
				return 'serialized';
			}
		};

		$result = ContractLabBlockRoundTripProbe::run( 'fixture-markup', $adapter );

		self::assertSame( 'matched', $result->status() );
		self::assertSame( $result->before(), $result->after() );
		self::assertSame( 'etch/text', $result->before()[0]['inner_blocks'][0]['block_name'] );
		self::assertSame( 'Second', $result->before()[0]['inner_blocks'][1]['attributes']['content'] );
	}

	public function test_round_trip_reports_semantic_drift_and_rejects_malformed_shapes(): void {
		$drift_adapter = new class implements ContractLabBlockWireAdapterInterface {
			public function parse( string $markup ): array {
				return 'serialized' === $markup
					? array( array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'Second' ), 'innerBlocks' => array() ) )
					: array( array( 'blockName' => 'etch/text', 'attrs' => array( 'content' => 'First' ), 'innerBlocks' => array() ) );
			}

			public function serialize( array $blocks ): string {
				return 'serialized';
			}
		};

		$drift = ContractLabBlockRoundTripProbe::run( 'fixture-markup', $drift_adapter );
		self::assertSame( 'drift', $drift->status() );
		self::assertNotSame( $drift->before(), $drift->after() );

		$malformed_adapter = new class implements ContractLabBlockWireAdapterInterface {
			public function parse( string $markup ): array {
				return array( array( 'blockName' => 'etch/text', 'attrs' => 'not-an-object', 'innerBlocks' => array() ) );
			}

			public function serialize( array $blocks ): string {
				return 'serialized';
			}
		};

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'attrs must be an object' );
		ContractLabBlockRoundTripProbe::run( 'fixture-markup', $malformed_adapter );
	}

	public function test_wordpress_adapter_delegates_to_real_public_parser_functions(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/WordPressBlockWireAdapter.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'parse_blocks', $source );
		self::assertStringContainsString( 'serialize_blocks', $source );
		self::assertStringNotContainsString( 'preg_replace', $source );
		self::assertStringNotContainsString( 'str_replace', $source );
	}
}
