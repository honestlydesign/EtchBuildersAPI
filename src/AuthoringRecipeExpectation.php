<?php
/**
 * Exact expected outcome for one executable Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Compares a recipe's complete Compiled Site Plan projection, including the
 * serialized wire payloads, without reducing the assertion to a substring.
 */
final class AuthoringRecipeExpectation {

	/**
	 * @var array<string, mixed>
	 */
	private readonly array $plan_projection;

	/**
	 * @param array<string, mixed> $plan_projection
	 */
	private function __construct( array $plan_projection ) {
		$this->plan_projection = $plan_projection;
	}

	/**
	 * Create an expectation from a canonical Compiled Site Plan projection.
	 *
	 * The projection is intentionally authored alongside executable recipe code;
	 * it is not a second snippet or a runtime parser.
	 *
	 * @param array<string, mixed> $plan_projection
	 */
	public static function for_plan( array $plan_projection ): self {
		AcyclicArrayGuard::assert_acyclic( $plan_projection );
		$plan_projection = ImmutableArray::copy(
			$plan_projection,
			'Authoring recipe expectations must contain only scalar, null, or nested array values.'
		);

		$keys = array_keys( $plan_projection );
		sort( $keys );
		$expected_keys = array( 'assets', 'dependencies', 'diagnostics', 'entities', 'home_page', 'identities', 'ownership', 'styles' );
		if ( $expected_keys !== $keys ) {
			throw new InvalidArgumentException( 'Authoring recipe expectations must contain the complete Compiled Site Plan projection.' );
		}

		return new self( $plan_projection );
	}

	/**
	 * Return the complete expected plan projection.
	 *
	 * @return array<string, mixed>
	 */
	public function plan_projection(): array {
		return $this->plan_projection;
	}

	/**
	 * Return the machine-readable expected outcome projection.
	 *
	 * @return array{plan: array<string, mixed>}
	 */
	public function to_array(): array {
		return array( 'plan' => $this->plan_projection );
	}

	/**
	 * Return every exact projection mismatch, if any.
	 *
	 * @return array<int, array{path: string, expected: mixed, actual: mixed}>
	 */
	public function mismatches( CompiledSitePlan $plan ): array {
		$failures = array();
		self::compare( $this->plan_projection, $plan->to_array(), '$', $failures );

		return $failures;
	}

	/**
	 * @param mixed                                                                    $expected
	 * @param mixed                                                                    $actual
	 * @param array<int, array{path: string, expected: mixed, actual: mixed}> &$errors
	 */
	private static function compare( mixed $expected, mixed $actual, string $path, array &$errors ): void {
		if ( is_array( $expected ) ) {
			if ( ! is_array( $actual ) ) {
				$errors[] = array( 'path' => $path, 'expected' => $expected, 'actual' => $actual );
				return;
			}

			$expected_keys = array_keys( $expected );
			$actual_keys   = array_keys( $actual );
			if ( $expected_keys !== $actual_keys ) {
				$errors[] = array( 'path' => $path . '.keys', 'expected' => $expected_keys, 'actual' => $actual_keys );
			}

			foreach ( $expected as $key => $expected_value ) {
				if ( ! array_key_exists( $key, $actual ) ) {
					continue;
				}

				self::compare( $expected_value, $actual[ $key ], $path . '[' . var_export( $key, true ) . ']', $errors );
			}

			return;
		}

		if ( $expected !== $actual ) {
			$errors[] = array( 'path' => $path, 'expected' => $expected, 'actual' => $actual );
		}
	}
}
