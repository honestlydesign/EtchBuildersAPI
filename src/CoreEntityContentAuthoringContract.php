<?php
/**
 * Package-owned source contract for entity content authoring lanes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Content\AbstractContentBuilder;

/**
 * Exports exact source facts without claiming runtime admission. A host may
 * promote these Pending declarations only after validating its own contracts.
 */
final class CoreEntityContentAuthoringContract {

	private function __construct() {
	}

	/** Return the curated Pending declarations for the entity content lanes. */
	public static function capabilities(): AuthoringCapabilityCatalog {
		$references = self::reference_index();
		$reason = 'Source facts are versioned; runtime admission remains owned by a host with accepted entity contracts.';

		return AuthoringCapabilityCatalog::from_declarations(
			$references,
			AuthoringCapability::pending(
				'site.entity.definition',
				$reason,
				array(),
				array( 'recipe.reference.marketing', 'recipe.reference.cms-blog' ),
				array(),
				array( 'evidence.site.entity.definition' )
			),
			AuthoringCapability::pending(
				'site.pattern.definition',
				$reason,
				array(),
				array( 'recipe.reference.marketing', 'recipe.reference.cms-blog' ),
				array(),
				array( 'evidence.site.pattern.definition' )
			),
			AuthoringCapability::pending(
				'site.page.definition',
				$reason,
				array(),
				array( 'recipe.site.page', 'recipe.reference.marketing' ),
				array(),
				array( 'evidence.site.page.definition' )
			),
			AuthoringCapability::pending(
				'site.post.definition',
				$reason,
				array(),
				array( 'recipe.reference.cms-blog' ),
				array( 'ETCH_SITE_LOOP_INVALID' ),
				array( 'evidence.site.post.definition' )
			),
			AuthoringCapability::pending(
				'site.template.definition',
				$reason,
				array(),
				array( 'recipe.reference.marketing', 'recipe.reference.cms-blog' ),
				array(),
				array( 'evidence.site.template.definition' )
			),
			AuthoringCapability::pending(
				'site.style.ownership',
				$reason,
				array(),
				array( 'recipe.reference.marketing', 'recipe.negative.style-ownership' ),
				array( 'ETCH_SITE_STYLE_INVALID' ),
				array( 'evidence.site.style.ownership' )
			)
		);
	}

	/** Return the exact public source symbols behind each declaration. */
	public static function sources(): AuthoringCapabilitySourceCatalog {
		$content_symbols = array(
			'title'           => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'title' ),
			'status'          => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'status' ),
			'block'           => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'block' ),
			'blocks_sequence' => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'blocks_sequence' ),
			'pattern_use'     => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'pattern_use' ),
			'stylesheet'      => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'stylesheet' ),
			'add_style'       => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'add_style' ),
			'overwrite'       => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'overwrite' ),
			'dev_only'        => AuthoringCapabilitySourceSymbol::method( AbstractContentBuilder::class, 'dev_only' ),
		);

		return AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.entity.definition',
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'component' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'pattern' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'page' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'post' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'template' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'supporting' ),
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'global_asset' )
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.pattern.definition',
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'key' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'category' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'blocks' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'pattern_use' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'stylesheet' ),
				AuthoringCapabilitySourceSymbol::method( Pattern::class, 'add_style' )
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.page.definition',
				AuthoringCapabilitySourceSymbol::method( Page::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( Page::class, 'slug' ),
				AuthoringCapabilitySourceSymbol::method( Page::class, 'id' ),
				$content_symbols['title'],
				$content_symbols['status'],
				$content_symbols['block'],
				$content_symbols['blocks_sequence'],
				$content_symbols['pattern_use'],
				$content_symbols['stylesheet'],
				$content_symbols['add_style'],
				$content_symbols['overwrite'],
				$content_symbols['dev_only']
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.post.definition',
				AuthoringCapabilitySourceSymbol::method( Post::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( Post::class, 'post_type' ),
				AuthoringCapabilitySourceSymbol::method( Post::class, 'slug' ),
				AuthoringCapabilitySourceSymbol::method( Post::class, 'id' ),
				$content_symbols['title'],
				$content_symbols['status'],
				$content_symbols['block'],
				$content_symbols['blocks_sequence'],
				$content_symbols['pattern_use'],
				$content_symbols['stylesheet'],
				$content_symbols['add_style'],
				$content_symbols['overwrite'],
				$content_symbols['dev_only']
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.template.definition',
				AuthoringCapabilitySourceSymbol::method( Template::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( Template::class, 'slug' ),
				$content_symbols['title'],
				$content_symbols['status'],
				$content_symbols['block'],
				$content_symbols['blocks_sequence'],
				$content_symbols['pattern_use'],
				$content_symbols['stylesheet'],
				$content_symbols['add_style'],
				$content_symbols['overwrite'],
				$content_symbols['dev_only']
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.style.ownership',
				AuthoringCapabilitySourceSymbol::method( EntityStyleSet::class, 'from_file' ),
				AuthoringCapabilitySourceSymbol::method( EntityStyleSet::class, 'class_reference' ),
				AuthoringCapabilitySourceSymbol::method( Stylesheet::class, 'new' ),
				AuthoringCapabilitySourceSymbol::method( Stylesheet::class, 'css_file' ),
				AuthoringCapabilitySourceSymbol::method( Stylesheet::class, 'register_references' )
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
			AuthoringCapabilityEvidence::recipe( 'evidence.site.entity.definition', 'site.entity.definition', 'recipe.reference.marketing' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.pattern.definition', 'site.pattern.definition', 'recipe.reference.marketing' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.page.definition', 'site.page.definition', 'recipe.site.page' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.post.definition', 'site.post.definition', 'recipe.reference.cms-blog' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.site.template.definition', 'site.template.definition', 'recipe.reference.cms-blog' ),
			AuthoringCapabilityEvidence::negative( 'evidence.site.style.ownership', 'site.style.ownership', 'recipe.negative.style-ownership' )
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
