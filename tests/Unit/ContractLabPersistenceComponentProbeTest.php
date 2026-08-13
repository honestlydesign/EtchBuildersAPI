<?php
/**
 * Contract Lab persistence and component-property probe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ContractLabComponentPropertyProbe;
use HonestlyDesign\EtchBuilders\ContractLabEtchRuntimeResolutionObservation;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\ContractLabPersistenceHandoffObservation;
use PHPUnit\Framework\TestCase;

/**
 * Proves that Contract Lab persistence and component probes keep their two
 * observation sources separate and fail closed at the Builder-to-Etch seam.
 */
final class ContractLabPersistenceComponentProbeTest extends TestCase {

	public function test_persistence_handoff_keeps_opaque_style_identity_separate_from_selector(): void {
		$style = CompiledSiteResource::new(
			CompiledSiteResourceType::STYLE,
			'style:style-opaque-id',
			array(
				'type'     => 'class',
				'selector' => '.visual-card',
				'css'      => '.visual-card { color: red; }',
			)
		);
		$component = CompiledSiteEntity::new(
			CompiledSiteEntityType::COMPONENT,
			'component:FeatureCard',
			array(
				'name'        => 'Feature Card',
				'description' => 'Contract fixture',
				'blocks'      => '<!-- wp:etch/element /-->',
				'properties'  => $this->component_schema(),
			)
		);

		$handoff = ContractLabPersistenceHandoffObservation::from_persistence_records(
			array(
				\HonestlyDesign\EtchBuilders\SitePersistenceRecord::from_resource( $style ),
				\HonestlyDesign\EtchBuilders\SitePersistenceRecord::from_entity( $component ),
			),
			array( 'FeatureCard' => array( 'default', 'actions' ) ),
			array(
				'FeatureCard' => array(
					array(
						'attributes' => array(
							'styling' => '{{"className":"style-opaque-id"}}',
						),
						'slots' => array(
							array( 'name' => 'default', 'blocks' => array( 'etch/text' ) ),
						),
					),
				),
			)
		);

		self::assertSame( '1', $handoff->to_array()['observation_version'] );
		self::assertSame( 'builder_handoff', $handoff->to_array()['source'] );
		self::assertSame( 'style-opaque-id', $handoff->styles()[0]['opaque_id'] );
		self::assertSame( '.visual-card', $handoff->styles()[0]['selector'] );
		self::assertNotSame( $handoff->styles()[0]['opaque_id'], $handoff->styles()[0]['selector'] );
		self::assertSame( '{{"className":"style-opaque-id"}}', $handoff->components()[0]['instances'][0]['attributes']['styling'] );
		self::assertArrayNotHasKey( 'post_id', $handoff->to_array() );
		self::assertArrayNotHasKey( 'url', $handoff->to_array() );
	}

	public function test_runtime_resolution_rehydrates_the_raw_public_probe_envelope(): void {
		$raw = array(
			'observation_version' => '1',
			'source'             => 'etch_runtime_resolution',
			'status'             => 'observed',
			'styles'             => array( array( 'opaque_id' => 'style-opaque-id', 'selector' => '.visual-card' ) ),
			'components'         => array(),
		);

		$observation = ContractLabEtchRuntimeResolutionObservation::from_array( $raw );

		self::assertSame( 'resolved', $observation->to_array()['styles'][0]['status'] );
		self::assertSame( $raw['styles'], array_map( static function ( array $style ): array {
			unset( $style['status'] );

			return $style;
		}, $observation->to_array()['styles'] ) );
	}

	public function test_component_probe_observes_nested_values_exact_slots_and_runtime_resolution_separately(): void {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema( 'FeatureCard', $this->component_schema(), array( 'default', 'actions' ) )
		);
		$handoff = ContractLabPersistenceHandoffObservation::from_public_surfaces(
			array(
				array( 'opaque_id' => 'style-opaque-id', 'type' => 'class', 'selector' => '.visual-card' ),
			),
			array(
				array(
					'component_key' => 'FeatureCard',
					'properties'    => $this->component_schema(),
					'slots'         => array( 'default', 'actions' ),
					'instances'     => array(
						array(
							'attributes' => array(
								'title'   => 'Hello',
								'styling' => '{{"className":"style-opaque-id"}}',
								'rows'    => '{[{"label":"One","nested":"{{\\"className\\":\\"style-opaque-id\\"}}"}]}',
							),
							'slots' => array(
								array( 'name' => 'default', 'blocks' => array( 'etch/text' ) ),
								array( 'name' => 'actions', 'blocks' => array( 'etch/text', 'etch/text' ) ),
							),
						),
					),
				),
			)
		);
		$runtime = ContractLabEtchRuntimeResolutionObservation::observed(
			array(
				array( 'opaque_id' => 'style-opaque-id', 'selector' => '.visual-card' ),
			),
			array(
				array(
					'component_key'   => 'FeatureCard',
					'property_paths' => array( 'title', 'styling.className', 'rows[0].label', 'rows[0].nested.className' ),
					'slots'          => array( 'default', 'actions' ),
				),
			)
		);

		$probe = ContractLabComponentPropertyProbe::observe( $handoff, $catalog, $runtime );
		$record = $probe->to_array();

		self::assertSame( 'builder_handoff', $record['builder_handoff']['source'] );
		self::assertSame( 'etch_runtime_resolution', $record['etch_runtime_resolution']['source'] );
		self::assertSame( 'style-opaque-id', $record['styles'][0]['opaque_id'] );
		self::assertSame( '.visual-card', $record['styles'][0]['selector'] );
		self::assertSame(
			array( 'default', 'actions' ),
			$record['components'][0]['instances'][0]['slots']
		);
		self::assertSame(
			array( 'styling.className', 'rows[0].nested.className' ),
			$record['components'][0]['instances'][0]['class_property_paths']
		);
		self::assertStringContainsString( 'style-opaque-id', json_encode( $record['components'][0]['instances'][0]['values'], JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'Etch\\', json_encode( $record, JSON_THROW_ON_ERROR ) );
	}

	public function test_component_probe_rejects_selector_or_class_name_instead_of_opaque_style_id(): void {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema( 'FeatureCard', $this->component_schema(), array( 'default' ) )
		);
		$handoff = ContractLabPersistenceHandoffObservation::from_public_surfaces(
			array(
				array( 'opaque_id' => 'style-opaque-id', 'type' => 'class', 'selector' => '.visual-card' ),
			),
			array(
				array(
					'component_key' => 'FeatureCard',
					'properties'    => $this->component_schema(),
					'slots'         => array( 'default' ),
					'instances'     => array(
						array(
							'attributes' => array( 'styling' => '{{"className":"visual-card"}}' ),
							'slots'      => array( array( 'name' => 'default', 'blocks' => array() ) ),
						),
					),
				),
			)
		);
		$runtime = ContractLabEtchRuntimeResolutionObservation::observed(
			array( array( 'opaque_id' => 'style-opaque-id', 'selector' => '.visual-card' ) ),
			array( array( 'component_key' => 'FeatureCard', 'property_paths' => array( 'styling.className' ), 'slots' => array( 'default' ) ) )
		);

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'opaque style ID' );
		ContractLabComponentPropertyProbe::observe( $handoff, $catalog, $runtime );
	}

	public function test_component_probe_rejects_unknown_exact_slot(): void {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema( 'FeatureCard', $this->component_schema(), array( 'default' ) )
		);
		$handoff = ContractLabPersistenceHandoffObservation::from_public_surfaces(
			array(),
			array(
				array(
					'component_key' => 'FeatureCard',
					'properties'    => $this->component_schema(),
					'slots'         => array( 'default' ),
					'instances'     => array(
						array(
							'attributes' => array(),
							'slots'      => array( array( 'name' => 'invented', 'blocks' => array() ) ),
						),
					),
				),
			)
		);
		$runtime = ContractLabEtchRuntimeResolutionObservation::observed( array(), array( array( 'component_key' => 'FeatureCard', 'property_paths' => array(), 'slots' => array( 'invented' ) ) ) );

		$this->expectException( ContractLabObservationException::class );
		$this->expectExceptionMessage( 'exact slot' );
		ContractLabComponentPropertyProbe::observe( $handoff, $catalog, $runtime );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function component_schema(): array {
		return array(
			array(
				'key'        => 'title',
				'name'       => 'Title',
				'type'       => array( 'primitive' => 'string' ),
				'default'    => 'Card',
			),
			array(
				'key'        => 'styling',
				'name'       => 'Styling',
				'type'       => array( 'primitive' => 'object', 'specialized' => 'group' ),
				'properties' => array(
					array(
						'key'  => 'className',
						'name' => 'Class',
						'type' => array( 'primitive' => 'array', 'specialized' => 'class' ),
					),
				),
			),
			array(
				'key'        => 'rows',
				'name'       => 'Rows',
				'type'       => array( 'primitive' => 'array', 'specialized' => 'repeater' ),
				'properties' => array(
					array(
						'key'  => 'label',
						'name' => 'Label',
						'type' => array( 'primitive' => 'string' ),
					),
					array(
						'key'        => 'nested',
						'name'       => 'Nested',
						'type'       => array( 'primitive' => 'object', 'specialized' => 'group' ),
						'properties' => array(
							array(
								'key'  => 'className',
								'name' => 'Class',
								'type' => array( 'primitive' => 'array', 'specialized' => 'class' ),
							),
						),
					),
				),
			),
		);
	}
}
