<?php
/**
 * Result of one composite reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries passed, skipped, or failed status with an optional complete plan.
 */
final class AuthoringCompositeRecipeResult {

	/**
	 * @param array<int, string> $failures
	 */
	private function __construct(
		private readonly string $recipe_id,
		private readonly string $version,
		private readonly AuthoringCompositeRecipeExpectation $expected_outcome,
		private readonly string $status,
		private readonly ?CompiledSitePlan $plan,
		private readonly ?string $reason,
		private readonly bool $writes_detected,
		private readonly array $failures
	) {
	}

	public static function from_plan(
		string $recipe_id,
		string $version,
		AuthoringCompositeRecipeExpectation $expected_outcome,
		CompiledSitePlan $plan,
		bool $writes_detected
	): self {
		$failures = array();
		if ( 'passed' !== $expected_outcome->status() ) {
			$failures[] = 'unexpected execution';
		} elseif ( null !== $expected_outcome->plan() ) {
			$failures = array_map(
				static fn ( array $mismatch ): string => (string) $mismatch['path'],
				$expected_outcome->plan()->mismatches( $plan )
			);
		}
		if ( $writes_detected ) {
			$failures[] = 'writes detected';
		}

		$status = array() === $failures ? 'passed' : 'failed';

		return new self( $recipe_id, $version, $expected_outcome, $status, $plan, null, $writes_detected, $failures );
	}

	public static function from_skipped(
		string $recipe_id,
		string $version,
		AuthoringCompositeRecipeExpectation $expected_outcome,
		string $reason
	): self {
		$failures = 'skipped' === $expected_outcome->status() && $expected_outcome->skip_reason() === $reason
			? array()
			: array( 'skip status or reason' );

		return new self( $recipe_id, $version, $expected_outcome, 'skipped', null, $reason, false, $failures );
	}

	public static function from_failure(
		string $recipe_id,
		string $version,
		AuthoringCompositeRecipeExpectation $expected_outcome,
		string $reason,
		bool $writes_detected = false
	): self {
		$failures = array( 'unexpected failure' );
		if ( $writes_detected ) {
			$failures[] = 'writes detected';
		}

		return new self( $recipe_id, $version, $expected_outcome, 'failed', null, $reason, $writes_detected, $failures );
	}

	public function assertions_passed(): bool {
		return array() === $this->failures;
	}

	public function recipe_id(): string {
		return $this->recipe_id;
	}

	public function version(): string {
		return $this->version;
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		return $this->expected_outcome;
	}

	public function failure_message(): string {
		return $this->assertions_passed()
			? ''
			: sprintf( 'Composite recipe "%s" mismatches: %s', $this->recipe_id, implode( ', ', $this->failures ) );
	}

	public function status(): string {
		return $this->status;
	}

	public function plan(): ?CompiledSitePlan {
		return $this->plan;
	}

	public function reason(): ?string {
		return $this->reason;
	}

	public function writes_detected(): bool {
		return $this->writes_detected;
	}

	/**
	 * @return array<int, string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'recipe_id'       => $this->recipe_id,
			'version'         => $this->version,
			'status'          => $this->status,
			'plan'            => null !== $this->plan ? $this->plan->to_array() : null,
			'reason'          => $this->reason,
			'writes_detected' => $this->writes_detected,
			'failures'        => $this->failures,
		);
	}
}
