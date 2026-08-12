<?php
/**
 * Public contract for one executable Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Describes a versioned, positive authoring example executable by the same
 * no-write Golden Path used by projects.
 */
interface AuthoringCapabilityRecipeInterface {

	/**
	 * Return the stable recipe identifier.
	 */
	public function id(): string;

	/**
	 * Return the recipe contract version.
	 */
	public function version(): string;

	/**
	 * Return the intent capabilities demonstrated by this recipe.
	 *
	 * @return array<int, string>
	 */
	public function capability_ids(): array;

	/**
	 * Return capabilities that must already be available to use this recipe.
	 *
	 * @return array<int, string>
	 */
	public function prerequisite_ids(): array;

	/**
	 * Return the deterministic machine-readable recipe inputs.
	 *
	 * @return array<string, mixed>
	 */
	public function inputs(): array;

	/**
	 * Return exact expected semantic and wire outcomes.
	 */
	public function expected_outcomes(): AuthoringRecipeExpectation;

	/**
	 * Execute the recipe through the public Golden Path.
	 */
	public function execute(): AuthoringRecipeResult;

	/**
	 * Return the metadata projection consumed by docs, queries, and evaluation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array;
}
