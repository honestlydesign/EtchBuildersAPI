<?php
/**
 * Result of executing one negative Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries the actual diagnostic, optional plan, and no-write proof.
 */
final class AuthoringNegativeRecipeResult {

	/**
	 * @param array{code: string, severity: string, message: string, identity: string|null}|null $actual_diagnostic
	 * @param array<int, string> $failures
	 */
	private function __construct(
		private readonly string $recipe_id,
		private readonly string $version,
		private readonly AuthoringNegativeRecipeExpectation $expected_outcome,
		private readonly ?array $actual_diagnostic,
		private readonly ?CompiledSitePlan $plan,
		private readonly bool $writes_detected,
		private readonly array $failures
	) {
	}

	public static function from_execution(
		string $recipe_id,
		string $version,
		AuthoringNegativeRecipeExpectation $expected_outcome,
		?array $actual_diagnostic,
		?CompiledSitePlan $plan,
		bool $writes_detected
	): self {
		/** @var array{code: string, severity: string, message: string, identity: string|null}|null $actual_diagnostic */
		return new self(
			$recipe_id,
			$version,
			$expected_outcome,
			$actual_diagnostic,
			$plan,
			$writes_detected,
			$expected_outcome->mismatches( $actual_diagnostic, $plan, $writes_detected )
		);
	}

	/**
	 * Re-evaluate the same captured execution against another expectation.
	 */
	public function recheck( AuthoringNegativeRecipeExpectation $expected_outcome ): self {
		return self::from_execution(
			$this->recipe_id,
			$this->version,
			$expected_outcome,
			$this->actual_diagnostic,
			$this->plan,
			$this->writes_detected
		);
	}

	public function assertions_passed(): bool {
		return array() === $this->failures;
	}

	public function failure_message(): string {
		return $this->assertions_passed()
			? ''
			: sprintf( 'Negative authoring recipe "%s" mismatches: %s', $this->recipe_id, implode( ', ', $this->failures ) );
	}

	public function expected_outcome(): AuthoringNegativeRecipeExpectation {
		return $this->expected_outcome;
	}

	/**
	 * @return array{code: string, severity: string, message: string, identity: string|null}|null
	 */
	public function actual_diagnostic(): ?array {
		return $this->actual_diagnostic;
	}

	public function plan(): ?CompiledSitePlan {
		return $this->plan;
	}

	public function writes_detected(): bool {
		return $this->writes_detected;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'recipe_id'         => $this->recipe_id,
			'version'           => $this->version,
			'passed'            => $this->assertions_passed(),
			'expected_outcome'  => $this->expected_outcome->to_array(),
			'actual_diagnostic' => $this->actual_diagnostic,
			'plan'              => null !== $this->plan ? $this->plan->to_array() : null,
			'writes_detected'   => $this->writes_detected,
			'failures'          => $this->failures,
		);
	}
}
