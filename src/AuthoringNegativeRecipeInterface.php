<?php
/**
 * Public contract for one executable negative Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Describes one intentionally invalid authoring route and its corrective
 * diagnostic. The fixture itself remains code, while its metadata is reusable
 * by documentation, queries, and agent evaluation.
 */
interface AuthoringNegativeRecipeInterface {

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
	 * @return array<string, mixed>
	 */
	public function inputs(): array;

	public function expected_outcome(): AuthoringNegativeRecipeExpectation;

	public function execute(): AuthoringNegativeRecipeResult;

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array;
}
