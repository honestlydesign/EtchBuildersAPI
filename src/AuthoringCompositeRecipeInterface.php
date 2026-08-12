<?php
/**
 * Public contract for one composite reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Extends recipe metadata with optional product/runtime prerequisites. A
 * composite can therefore be discoverable without claiming that an optional
 * plugin or accepted external contract is installed in every host.
 */
interface AuthoringCompositeRecipeInterface {

	public function id(): string;

	public function version(): string;

	/**
	 * @return array<int, string>
	 */
	public function capability_ids(): array;

	/**
	 * @return array<int, string>
	 */
	public function prerequisite_ids(): array;

	/**
	 * @return array<int, string>
	 */
	public function optional_product_prerequisite_ids(): array;

	/**
	 * @return array<string, mixed>
	 */
	public function inputs(): array;

	public function expected_outcome(): AuthoringCompositeRecipeExpectation;

	public function execute(): AuthoringCompositeRecipeResult;

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array;
}
