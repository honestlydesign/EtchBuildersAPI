<?php
/**
 * Shared execution mechanics for positive Authoring Capability recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use Throwable;

/**
 * Keeps concrete recipes focused on public typed composition and expected
 * outcomes while centralizing no-write execution and projection shape.
 */
abstract class AbstractAuthoringCapabilityRecipe implements AuthoringCapabilityRecipeInterface {

	/**
	 * Build the site definition using only public typed builders.
	 */
	abstract protected function build(): SiteDefinition;

	public function execute(): AuthoringRecipeResult {
		try {
			$plan = $this->build()->compile();
		} catch ( Throwable $exception ) {
			return AuthoringRecipeResult::from_error( $this->id(), $this->version(), $exception::class . ': ' . $exception->getMessage() );
		}

		return AuthoringRecipeResult::from_plan( $this->id(), $this->version(), $plan, $this->expected_outcomes() );
	}

	/**
	 * @return array<string, mixed>
	 */
	final public function to_array(): array {
		return array(
			'id'                => $this->id(),
			'version'           => $this->version(),
			'capability_ids'    => $this->capability_ids(),
			'prerequisite_ids'  => $this->prerequisite_ids(),
			'inputs'            => $this->inputs(),
			'expected_outcomes' => $this->expected_outcomes()->to_array(),
		);
	}
}
