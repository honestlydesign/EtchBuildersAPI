<?php
/**
 * Negative recipe for an unsupported/unregistered loop expression.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Uses the compiler's stable loop diagnostic rather than allowing an unknown
 * loop target to pass as a guessed runtime expression.
 */
final class NegativeLoopExpressionAuthoringRecipe extends AbstractAuthoringNegativeRecipe {

	public function id(): string {
		return 'recipe.negative.loop-expression';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.dynamic.loop' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.page.definition' );
	}

	public function inputs(): array {
		return array(
			'loop_id'    => 'missing-loop',
			'target'     => 'items',
			'remediation' => 'Declare the matching LoopPreset in SiteDefinition::supporting() before referencing its key.',
		);
	}

	protected function build(): SiteDefinition {
		$page = Page::new()->slug( 'home' )->block(
			Block::new( 'loop', array( 'target' => 'items', 'loopId' => 'missing-loop' ) )
		);

		return SiteDefinition::new()->page( $page );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return AuthoringNegativeRecipeExpectation::diagnostic(
			'ETCH_SITE_LOOP_INVALID',
			CompiledSiteDiagnosticSeverity::ERROR,
			'Loop reference "missing-loop" is not registered in the Site Definition.',
			'page:slug:home',
			true,
			true
		);
	}
}
