<?php
/**
 * Expected result for one composite reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Represents either a complete plan assertion or an explicit unavailable
 * prerequisite skip.
 */
final class AuthoringCompositeRecipeExpectation {

	private function __construct(
		private readonly ?AuthoringRecipeExpectation $plan,
		private readonly ?string $skip_reason
	) {
	}

	public static function for_plan( AuthoringRecipeExpectation $plan ): self {
		return new self( $plan, null );
	}

	public static function skipped( string $reason ): self {
		$reason = trim( $reason );
		if ( '' === $reason ) {
			throw new InvalidArgumentException( 'Skipped composite recipes require an explicit reason.' );
		}

		return new self( null, $reason );
	}

	public function status(): string {
		return null !== $this->plan ? 'passed' : 'skipped';
	}

	public function plan(): ?AuthoringRecipeExpectation {
		return $this->plan;
	}

	public function skip_reason(): ?string {
		return $this->skip_reason;
	}

	/**
	 * @return array{status: string, plan: array<string, mixed>|null, skip_reason: string|null}
	 */
	public function to_array(): array {
		return array(
			'status'      => $this->status(),
			'plan'        => null !== $this->plan ? $this->plan->plan_projection() : null,
			'skip_reason' => $this->skip_reason,
		);
	}
}
