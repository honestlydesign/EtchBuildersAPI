<?php
/**
 * Package-owned source contract for schema-backed component composition.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentExpression;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;

/**
 * Exports exact source facts without claiming runtime/OME admission. A host may
 * promote these Pending declarations only after validating its own contracts.
 */
final class CoreComponentCompositionAuthoringContract {

	private function __construct() {
	}

	/** Return the curated Pending declarations for the schema-backed lane. */
	public static function capabilities(): AuthoringCapabilityCatalog {
		$references = self::reference_index();
		$reason = 'Source facts are versioned; runtime admission remains owned by a host with accepted component contracts.';

		return AuthoringCapabilityCatalog::from_declarations(
			$references,
			AuthoringCapability::pending(
				'site.style.class-reference',
				$reason,
				array(),
				array( 'recipe.negative.class-style-id', 'recipe.reference.ome' ),
				array( 'ETCH_CLASS_UNKNOWN_ID' ),
				array( 'evidence.site.style.class.reference' )
			),
			AuthoringCapability::pending(
				'site.component.instance',
				$reason,
				array(),
				array( 'recipe.negative.component-path', 'recipe.reference.ome' ),
				array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				array( 'evidence.site.component.instance' )
			),
			AuthoringCapability::pending(
				'site.component.slot',
				$reason,
				array( 'site.component.instance' ),
				array( 'recipe.reference.ome' ),
				array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				array( 'evidence.site.component.slot' )
			),
			AuthoringCapability::pending(
				'site.ome.composition',
				$reason,
				array( 'site.component.instance', 'site.component.slot', 'site.style.class-reference' ),
				array( 'recipe.reference.ome' ),
				array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				array( 'evidence.site.ome.composition' )
			),
			AuthoringCapability::pending(
				'site.dynamic.loop',
				'Native loop declarations are source facts only; the host must verify the exact Etch runtime record before persistence.',
				array(),
				array( 'recipe.site.native-loop-dependency' ),
				array(),
				array()
			)
		);
	}

	/** Return the exact public source symbols behind each declaration. */
	public static function sources(): AuthoringCapabilitySourceCatalog {
		$component_block_symbols = array(
			'for_key'         => AuthoringCapabilitySourceSymbol::method( ComponentBlock::class, 'for_key' ),
			'prop_value'      => AuthoringCapabilitySourceSymbol::method( ComponentBlock::class, 'prop_value' ),
			'expression_prop' => AuthoringCapabilitySourceSymbol::method( ComponentBlock::class, 'expression_prop' ),
			'class_prop'      => AuthoringCapabilitySourceSymbol::method( ComponentBlock::class, 'class_prop' ),
			'slot'            => AuthoringCapabilitySourceSymbol::method( ComponentBlock::class, 'slot' ),
		);

		return AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.style.class-reference',
				AuthoringCapabilitySourceSymbol::method( ClassStyleReference::class, 'registered' ),
				AuthoringCapabilitySourceSymbol::method( ClassStyleSet::class, 'of' ),
				AuthoringCapabilitySourceSymbol::method( ClassStyleSet::class, 'none' ),
				$component_block_symbols['class_prop']
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.component.instance',
				$component_block_symbols['for_key'],
				$component_block_symbols['prop_value'],
				$component_block_symbols['expression_prop'],
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'string' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'numeric_string' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'boolean' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'object' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'array' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'color' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'loop_reference' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'url' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'image' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'select_option' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'wordpress_media_id' ),
				AuthoringCapabilitySourceSymbol::method( ComponentInstanceValue::class, 'empty_repeater' ),
				AuthoringCapabilitySourceSymbol::method( ComponentExpression::class, 'source' )
			),
			AuthoringCapabilitySourceDeclaration::for_capability( 'site.component.slot', $component_block_symbols['slot'] ),
			AuthoringCapabilitySourceDeclaration::for_capability( 'site.ome.composition', ...array_values( $component_block_symbols ) ),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.dynamic.loop',
				AuthoringCapabilitySourceSymbol::method( LoopPreset::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( LoopPreset::class, 'id' ),
				AuthoringCapabilitySourceSymbol::method( LoopPreset::class, 'key' ),
				AuthoringCapabilitySourceSymbol::method( LoopPreset::class, 'native_dependency' ),
				AuthoringCapabilitySourceSymbol::method( LoopPreset::class, 'wp_query' )
			)
		);
	}

	/** Return partial package evidence without promoting host-owned admission. */
	public static function evidence(): AuthoringCapabilityEvidenceCatalog {
		$catalog    = AuthoringCapabilityEvidenceCatalog::from_declarations( ...self::evidence_declarations() );
		$recipe_ids = self::recipe_index()->recipe_ids();
		foreach ( $catalog->all() as $record ) {
			if (
				in_array( $record->kind(), array( AuthoringCapabilityEvidenceKind::NEGATIVE, AuthoringCapabilityEvidenceKind::RECIPE ), true )
				&& ! in_array( $record->executable_id(), $recipe_ids, true )
			) {
				throw new \InvalidArgumentException(
					sprintf( 'Authoring evidence "%s" must link to a real recipe ID; "%s" does not resolve in the shipped catalogs.', $record->id(), $record->executable_id() )
				);
			}
		}

		return $catalog;
	}

	/**
	 * @return array<int, AuthoringCapabilityEvidence>
	 */
	private static function evidence_declarations(): array {
		return array(
			AuthoringCapabilityEvidence::negative( 'evidence.site.style.class.reference', 'site.style.class-reference', 'recipe.negative.class-style-id' ),
			AuthoringCapabilityEvidence::negative( 'evidence.site.component.instance', 'site.component.instance', 'recipe.negative.component-path' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.component.slot', 'site.component.slot', 'recipe.reference.ome' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.ome.composition', 'site.ome.composition', 'recipe.reference.ome' )
		);
	}

	/**
	 * Closed reference index derived from the shipped recipe catalogs instead of
	 * a hand-maintained ID list, so renamed recipes break loudly at construction.
	 */
	private static function reference_index(): AuthoringCapabilityReferenceIndex {
		$recipes = self::recipe_index();

		return AuthoringCapabilityReferenceIndex::new(
			$recipes->recipe_ids(),
			$recipes->diagnostic_ids(),
			array_map( static fn ( AuthoringCapabilityEvidence $evidence ): string => $evidence->id(), self::evidence_declarations() )
		);
	}

	private static function recipe_index(): AuthoringCapabilityReferenceIndex {
		$recipe_ids     = array();
		$diagnostic_ids = array();
		foreach ( CoreAuthoringRecipeCatalog::new()->all() as $recipe ) {
			$recipe_ids[] = $recipe->id();
		}
		foreach ( CoreNegativeAuthoringRecipeCatalog::new()->all() as $recipe ) {
			$recipe_ids[]     = $recipe->id();
			$diagnostic_ids[] = $recipe->expected_outcome()->diagnostic_record()['code'];
		}
		foreach ( CoreCompositeAuthoringRecipeCatalog::new()->all() as $recipe ) {
			$recipe_ids[] = $recipe->id();
		}

		return AuthoringCapabilityReferenceIndex::new( $recipe_ids, $diagnostic_ids, array() );
	}

	/** Validate and expose the exact evidence still missing for host admission. */
	public static function evidence_map(): AuthoringCapabilityEvidenceMap {
		$requirements = array_map(
			static fn ( AuthoringCapability $capability ): AuthoringCapabilityEvidenceRequirement => AuthoringCapabilityEvidenceRequirement::for_capability( $capability->id() ),
			self::capabilities()->all()
		);

		return AuthoringCapabilityEvidenceMap::from_catalogs(
			self::capabilities(),
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( ...$requirements ),
			self::evidence()
		);
	}

	/** Generate exact versioned interface facts from the current package source. */
	public static function catalog( string $package_version ): AuthoringContractCatalog {
		self::evidence_map();

		return AuthoringContractCatalogGenerator::generate( self::capabilities(), self::sources(), $package_version );
	}
}
