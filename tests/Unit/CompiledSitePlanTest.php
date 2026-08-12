<?php
/**
 * Immutable Compiled Site Plan model tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\CompiledSiteDependency;
use HonestlyDesign\EtchBuilders\CompiledSiteDiagnostic;
use HonestlyDesign\EtchBuilders\CompiledSiteDiagnosticSeverity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\PatternUse;
use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies typed plan sections without WordPress or persistence.
 */
final class CompiledSitePlanTest extends TestCase {

	private function pattern_use(): PatternUse {
		$pattern = Pattern::new( 'Hero', 'Hero pattern' )
			->key( 'Hero' )
			->blocks( BlockSequence::new()->append( TextBlock::new()->content( 'Hero' ) ) );

		return PatternUse::registered( $pattern );
	}

	public function test_plan_contains_all_compiled_sections_and_deterministic_projection(): void {
		$component = CompiledSiteEntity::new(
			CompiledSiteEntityType::COMPONENT,
			'component:Hero',
			array( 'blocks' => '<!-- wp:etch/text /-->' )
		);
		$pattern = CompiledSiteEntity::new(
			CompiledSiteEntityType::PATTERN,
			'pattern:Hero',
			array( 'blocks' => '<!-- wp:etch/text /-->' )
		);
		$dependency = CompiledSiteDependency::pattern( 'page:home', $this->pattern_use() );
		$style = CompiledSiteResource::new(
			CompiledSiteResourceType::STYLE,
			'style:hero',
			array( 'css' => '.hero { color: red; }' )
		);
		$asset = CompiledSiteResource::new(
			CompiledSiteResourceType::ASSET,
			'asset:hero-script',
			array( 'kind' => 'javascript', 'path' => 'assets/hero.js' )
		);
		$ownership = CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'entity_style' );
		$diagnostic = CompiledSiteDiagnostic::new(
			'ETCH_PLAN_WARNING',
			CompiledSiteDiagnosticSeverity::WARNING,
			'Pattern asset is optional.',
			'pattern:Hero'
		);

		$plan = CompiledSitePlan::from_sections(
			array( $component, $pattern ),
			array( $dependency ),
			array( $style ),
			array( $asset ),
			array( $ownership ),
			array( $diagnostic )
		);

		self::assertSame( array( 'component:Hero', 'pattern:Hero' ), $plan->resolved_identities() );
		self::assertSame( array( $dependency ), $plan->dependencies() );
		self::assertSame( array( $style ), $plan->styles() );
		self::assertSame( array( $asset ), $plan->assets() );
		self::assertSame( array( $ownership ), $plan->ownership() );
		self::assertSame( array( $diagnostic ), $plan->diagnostics() );
		self::assertFalse( $plan->has_errors() );
		self::assertSame(
			array(
				'entities'    => array(
					array( 'type' => 'component', 'identity' => 'component:Hero', 'payload' => array( 'blocks' => '<!-- wp:etch/text /-->' ) ),
					array( 'type' => 'pattern', 'identity' => 'pattern:Hero', 'payload' => array( 'blocks' => '<!-- wp:etch/text /-->' ) ),
				),
				'identities'   => array( 'component:Hero', 'pattern:Hero' ),
				'dependencies' => array( array( 'consumer' => 'page:home', 'dependency' => 'pattern:Hero', 'kind' => 'pattern' ) ),
				'styles'       => array( array( 'type' => 'style', 'identity' => 'style:hero', 'payload' => array( 'css' => '.hero { color: red; }' ) ) ),
				'assets'       => array( array( 'type' => 'asset', 'identity' => 'asset:hero-script', 'payload' => array( 'kind' => 'javascript', 'path' => 'assets/hero.js' ) ) ),
				'ownership'    => array( array( 'owner' => 'component:Hero', 'resource' => 'style:hero', 'role' => 'entity_style' ) ),
				'diagnostics'  => array( array( 'code' => 'ETCH_PLAN_WARNING', 'severity' => 'warning', 'message' => 'Pattern asset is optional.', 'identity' => 'pattern:Hero' ) ),
			),
			$plan->to_array()
		);
	}

	public function test_error_diagnostic_marks_plan_as_not_applicable(): void {
		$diagnostic = CompiledSiteDiagnostic::new( 'ETCH_PLAN_ERROR', CompiledSiteDiagnosticSeverity::ERROR, 'Cannot resolve page.', 'page:home' );
		$plan       = CompiledSitePlan::from_sections( diagnostics: array( $diagnostic ) );

		self::assertTrue( $plan->has_errors() );
	}

	public function test_empty_plan_is_a_valid_no_write_result(): void {
		$plan = CompiledSitePlan::empty();

		self::assertSame( array(), $plan->to_array()['entities'] );
		self::assertFalse( $plan->has_errors() );
	}

	public function test_sections_are_defensive_against_input_and_accessor_array_mutation(): void {
		$entity  = CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:home', array() );
		$entities = array( $entity );
		$plan     = CompiledSitePlan::from_sections( $entities );
		$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:about', array() );

		$from_plan   = $plan->entities();
		$from_plan[] = $entity;

		self::assertCount( 1, $plan->entities() );
		self::assertSame( array( 'page:home' ), $plan->resolved_identities() );
	}

	public function test_plan_rejects_invalid_section_types(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'styles must contain STYLE' );
		CompiledSitePlan::from_sections(
			styles: array( CompiledSiteResource::new( CompiledSiteResourceType::ASSET, 'asset:wrong', array() ) )
		);

	}

	public function test_plan_rejects_duplicate_resource_identities(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate style identity' );
		CompiledSitePlan::from_sections(
			styles: array(
				CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:hero', array() ),
				CompiledSiteResource::new( CompiledSiteResourceType::STYLE, 'style:hero', array() ),
			)
		);
	}

	public function test_plan_rejects_duplicate_dependency_and_ownership_edges(): void {
		$dependency = CompiledSiteDependency::new( 'page:home', 'pattern:Hero', 'pattern' );
		$ownership  = CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'entity_style' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate dependency edge identity' );
		CompiledSitePlan::from_sections(
			dependencies: array( $dependency, $dependency ),
			ownership: array( $ownership )
		);
	}

	public function test_value_objects_reject_malformed_identities_roles_codes_and_recursive_payloads(): void {
		$this->expectException( InvalidArgumentException::class );
		CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'home', array() );
	}

	public function test_dependency_ownership_and_diagnostic_values_reject_invalid_tokens(): void {
		$this->expectException( InvalidArgumentException::class );
		CompiledSiteDependency::new( 'page:home', 'pattern:Hero', '' );
	}

	public function test_ownership_rejects_an_empty_role(): void {
		$this->expectException( InvalidArgumentException::class );
		CompiledSiteOwnership::new( 'page:home', 'style:hero', '' );
	}

	public function test_diagnostic_rejects_an_unstable_code(): void {
		$this->expectException( InvalidArgumentException::class );
		CompiledSiteDiagnostic::new( 'bad code', CompiledSiteDiagnosticSeverity::ERROR, 'Failure.' );
	}

	public function test_recursive_entity_payload_is_rejected(): void {
		$payload = array();
		$payload['self'] =& $payload;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'finite, non-recursive' );
		CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:home', $payload );
	}

	public function test_entity_and_resource_namespaces_must_match_their_types(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'page:' );
		CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'component:Hero', array() );
	}

	public function test_resource_namespace_must_match_its_type(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'asset:' );
		CompiledSiteResource::new( CompiledSiteResourceType::ASSET, 'style:hero', array() );
	}

	public function test_entity_and_resource_payloads_reject_mutable_objects(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'scalar' );
		CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, 'page:home', array( 'object' => new \stdClass() ) );
	}
}
