<?php
/**
 * Shared execution mechanics for composite reference-site recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;
use Throwable;

/**
 * Compiles a composite through SiteDefinition and preserves explicit skips for
 * missing optional product/runtime prerequisites.
 */
abstract class AbstractAuthoringCompositeRecipe implements AuthoringCompositeRecipeInterface {

	abstract protected function build(): SiteDefinition;

	protected function runtime(): ?SiteRuntimeCapabilitiesInterface {
		return null;
	}

	protected function is_available(): bool {
		return array() === $this->optional_product_prerequisite_ids();
	}

	public function execute(): AuthoringCompositeRecipeResult {
		$expected_outcome = $this->expected_outcome();
		if ( ! $this->is_available() ) {
			return AuthoringCompositeRecipeResult::from_skipped(
				$this->id(),
				$this->version(),
				$expected_outcome,
				sprintf(
					'Optional product prerequisites are unavailable: %s.',
					implode( ', ', $this->optional_product_prerequisite_ids() )
				)
			);
		}

		$before        = $this->storage_snapshot();
		$style_state   = Style::snapshot_state();
		$script_state  = Javascript::snapshot();
		$loop_registry = LoopPreset::snapshot();
		$plan          = null;
		$error         = null;
		try {
			$plan = $this->build()->compile( $this->runtime() );
		} catch ( Throwable $throwable ) {
			$error = $throwable->getMessage();
		} finally {
			Style::restore_state( $style_state );
			Javascript::restore( $script_state );
			LoopPreset::restore( $loop_registry );
		}

		if ( null !== $error ) {
			return AuthoringCompositeRecipeResult::from_failure(
				$this->id(),
				$this->version(),
				$expected_outcome,
				$error,
				$before !== $this->storage_snapshot()
			);
		}

		return AuthoringCompositeRecipeResult::from_plan(
			$this->id(),
			$this->version(),
			$expected_outcome,
			$plan,
			$before !== $this->storage_snapshot()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	final public function to_array(): array {
		return array(
			'id'                               => $this->id(),
			'version'                          => $this->version(),
			'capability_ids'                   => $this->capability_ids(),
			'prerequisite_ids'                 => $this->prerequisite_ids(),
			'optional_product_prerequisite_ids' => $this->optional_product_prerequisite_ids(),
			'inputs'                           => $this->inputs(),
			'expected_outcome'                 => $this->expected_outcome()->to_array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storage_snapshot(): array {
		$keys = array( 'etch_styles', 'etch_global_stylesheets', 'etch_loops', 'etch_components', 'etch_patterns', 'etch_pages', 'etch_posts', 'etch_templates' );
		$snapshot = array();
		foreach ( $keys as $key ) {
			$snapshot[ $key ] = Environment::storage()->get( $key, null );
		}

		return $snapshot;
	}
}
