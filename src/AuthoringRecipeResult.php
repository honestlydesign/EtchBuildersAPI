<?php
/**
 * Result of executing one Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries the compiled plan and deterministic assertion result for a recipe.
 */
final class AuthoringRecipeResult {

	/**
	 * @param array<int, array{path: string, expected: mixed, actual: mixed}> $failures
	 */
	private function __construct(
		private readonly string $recipe_id,
		private readonly string $version,
		private readonly ?CompiledSitePlan $plan,
		private readonly array $failures,
		private readonly ?string $error
	) {
	}

	/**
	 * Create a result from a successfully compiled plan.
	 */
	public static function from_plan(
		string $recipe_id,
		string $version,
		CompiledSitePlan $plan,
		AuthoringRecipeExpectation $expectation
	): self {
		return new self( $recipe_id, $version, $plan, $expectation->mismatches( $plan ), null );
	}

	/**
	 * Create a failed result when the recipe cannot reach compilation.
	 */
	public static function from_error( string $recipe_id, string $version, string $error ): self {
		return new self( $recipe_id, $version, null, array(), $error );
	}

	public function recipe_id(): string {
		return $this->recipe_id;
	}

	public function version(): string {
		return $this->version;
	}

	public function plan(): ?CompiledSitePlan {
		return $this->plan;
	}

	public function assertions_passed(): bool {
		return null !== $this->plan && null === $this->error && array() === $this->failures;
	}

	/**
	 * @return array<int, array{path: string, expected: mixed, actual: mixed}>
	 */
	public function failures(): array {
		return $this->failures;
	}

	public function error(): ?string {
		return $this->error;
	}

	public function failure_message(): string {
		if ( null !== $this->error ) {
			return sprintf( 'Authoring recipe "%s" failed before assertion: %s', $this->recipe_id, $this->error );
		}

		if ( $this->assertions_passed() ) {
			return '';
		}

		return sprintf( 'Authoring recipe "%s" has projection mismatches: %s', $this->recipe_id, var_export( $this->failures, true ) );
	}

	/**
	 * Return the machine-readable execution projection.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'recipe_id' => $this->recipe_id,
			'version'   => $this->version,
			'passed'    => $this->assertions_passed(),
			'failures'  => $this->failures,
			'plan'      => null !== $this->plan ? $this->plan->to_array() : null,
			'error'     => $this->error,
		);
	}
}
