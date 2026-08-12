<?php
/**
 * Negative recipe for passing a guessed class name instead of an opaque ID.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;

/**
 * Exercises the exact class-style diagnostic before compilation or writes.
 */
final class NegativeClassStyleIdAuthoringRecipe extends AbstractAuthoringNegativeRecipe {

	public function id(): string {
		return 'recipe.negative.class-style-id';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.style.class-reference' );
	}

	public function prerequisite_ids(): array {
		return array();
	}

	public function inputs(): array {
		return array(
			'attempted_value' => 'visual-class',
			'remediation'    => 'Pass the exact opaque ID from ClassStyleReference::registered() after registering a type=class style.',
		);
	}

	protected function build(): SiteDefinition {
		$block = ElementBlock::new()
			->tag( 'div' )
			->class_style( ClassStyleReference::registered( 'visual-class' ) );

		return SiteDefinition::new()->page( Page::new()->slug( 'home' )->block( $block ) );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return AuthoringNegativeRecipeExpectation::diagnostic(
			ClassStyleDiagnostic::UNKNOWN_ID,
			CompiledSiteDiagnosticSeverity::ERROR,
			'[ETCH_CLASS_UNKNOWN_ID] Class Style ID "visual-class" is not registered in etch_styles. Migration: Register an exact type=class style, then pass its opaque ID to ClassStyleReference::registered().'
		);
	}
}
