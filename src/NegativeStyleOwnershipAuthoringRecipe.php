<?php
/**
 * Negative recipe for a stale style ownership reference.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Builds an entity with a style declaration whose registry owner disappears
 * before the no-write compiler runs.
 */
final class NegativeStyleOwnershipAuthoringRecipe extends AbstractAuthoringNegativeRecipe {

	public function id(): string {
		return 'recipe.negative.style-ownership';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.style.ownership' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.component.definition' );
	}

	public function inputs(): array {
		return array(
			'style_id'    => 'missing-owner-style',
			'remediation' => 'Keep the exact request-local style registry and entity ownership record aligned before compilation.',
		);
	}

	protected function build(): SiteDefinition {
		$component = Component::new( 'Hero', 'Hero component' )
			->key( 'Hero' )
			->blocks( \HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock::new()->content( 'Hero' ) );
		$component->add_style(
			Style::new()
				->id( 'missing-owner-style' )
				->selector( '.hero' )
				->owner_local_css( 'color: red;' )
		);
		Style::reset();

		return SiteDefinition::new()->component( $component );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return AuthoringNegativeRecipeExpectation::diagnostic(
			'ETCH_SITE_STYLE_INVALID',
			CompiledSiteDiagnosticSeverity::ERROR,
			'Referenced style ID "missing-owner-style" is not present in the request-local style registry.',
			null,
			true,
			true
		);
	}
}
