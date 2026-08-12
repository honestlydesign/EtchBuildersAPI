<?php
/**
 * Negative recipe for an untyped serialized markup fallback.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Keeps the legacy route visible as a warning so it cannot be mistaken for a
 * fully checked typed recipe.
 */
final class NegativeRawFallbackAuthoringRecipe extends AbstractAuthoringNegativeRecipe {

	public function id(): string {
		return 'recipe.negative.raw-fallback';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.checked-raw-fragment' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.page.definition' );
	}

	public function inputs(): array {
		return array(
			'route'       => 'Page::blocks_markup()',
			'remediation' => 'Compose a BlockSequence from typed builders or use a narrow RawFragment with a reason.',
		);
	}

	protected function build(): SiteDefinition {
		$page = Page::new()
			->slug( 'home' )
			->blocks_markup( '<!-- wp:etch/text {"content":"legacy"} /-->' );

		return SiteDefinition::new()->page( $page );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return AuthoringNegativeRecipeExpectation::diagnostic(
			'ETCH_SITE_ESCAPE_REVIEW',
			CompiledSiteDiagnosticSeverity::WARNING,
			'Entity uses serialized block markup instead of a typed BlockSequence; compiler checks are limited to the opaque wire payload.',
			'page:slug:home',
			false,
			true
		);
	}
}
