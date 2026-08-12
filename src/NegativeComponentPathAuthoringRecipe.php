<?php
/**
 * Negative recipe for a guessed component-property path.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;

/**
 * Feeds a malformed grouped component path through the compiler guard. The
 * attempted path remains metadata so a query can explain the correction.
 */
final class NegativeComponentPathAuthoringRecipe extends AbstractAuthoringNegativeRecipe {

	public function id(): string {
		return 'recipe.negative.component-path';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.component.instance' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.page.definition' );
	}

	public function inputs(): array {
		return array(
			'attempted_component_ref' => 77,
			'attempted_path'          => 'styling',
			'attempted_value'         => '{{broken}}',
			'remediation'             => 'Use ComponentBlock::for_key() and an exact schema-derived path with a typed value.',
		);
	}

	protected function build(): SiteDefinition {
		$component = ComponentBlock::new()
			->ref( 77 )
			->attribute( 'styling', '{{broken}}' );
		$page = Page::new()->slug( 'home' )->block(
			$component
		);

		return SiteDefinition::new()->page( $page );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return AuthoringNegativeRecipeExpectation::diagnostic(
			'ETCH_SITE_COMPONENT_CONTRACT_INVALID',
			CompiledSiteDiagnosticSeverity::ERROR,
			'Rule G: Component ref "77" does not resolve to an exact component key.',
			'page:slug:home',
			true,
			true
		);
	}
}
