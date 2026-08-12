<?php
/**
 * Exact expected corrective diagnostic for one negative recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Keeps diagnostic code, severity, message/remediation, and plan behavior
 * executable rather than accepting any non-empty error.
 */
final class AuthoringNegativeRecipeExpectation {

	/**
	 * @param array{code: string, severity: string, message: string, identity: string|null} $diagnostic
	 */
	private function __construct(
		private readonly array $diagnostic,
		private readonly bool $plan_must_have_errors,
		private readonly bool $plan_required
	) {
	}

	public static function diagnostic(
		string $code,
		CompiledSiteDiagnosticSeverity $severity,
		string $message,
		?string $identity = null,
		bool $plan_must_have_errors = true,
		bool $plan_required = false
	): self {
		$diagnostic = array(
			'code'     => trim( $code ),
			'severity' => $severity->value,
			'message'  => trim( $message ),
			'identity' => $identity,
		);
		AcyclicArrayGuard::assert_acyclic( $diagnostic );
		$diagnostic = ImmutableArray::copy( $diagnostic, 'Negative recipe diagnostics must contain scalar values.' );
		/** @var array{code: string, severity: string, message: string, identity: string|null} $diagnostic */
		if ( 1 !== preg_match( '/^[A-Z][A-Z0-9_-]*$/D', $diagnostic['code'] ) || '' === $diagnostic['message'] ) {
			throw new InvalidArgumentException( 'Negative recipe diagnostics require a stable code and non-empty message.' );
		}
		if ( null !== $identity && '' === trim( $identity ) ) {
			throw new InvalidArgumentException( 'Negative recipe diagnostic identity must be null or non-empty.' );
		}

		return new self( $diagnostic, $plan_must_have_errors, $plan_required );
	}

	/**
	 * Return a copy with one changed message for mutation-detection tests.
	 */
	public function with_message( string $message ): self {
		return self::diagnostic(
			$this->diagnostic['code'],
			CompiledSiteDiagnosticSeverity::from( $this->diagnostic['severity'] ),
			$message,
			$this->diagnostic['identity'],
			$this->plan_must_have_errors,
			$this->plan_required
		);
	}

	/**
	 * @return array{code: string, severity: string, message: string, identity: string|null}
	 */
	public function diagnostic_record(): array {
		return $this->diagnostic;
	}

	public function plan_must_have_errors(): bool {
		return $this->plan_must_have_errors;
	}

	public function plan_required(): bool {
		return $this->plan_required;
	}

	/**
	 * @return array{diagnostic: array{code: string, severity: string, message: string, identity: string|null}, plan_must_have_errors: bool}
	 */
	public function to_array(): array {
		return array(
			'diagnostic'          => $this->diagnostic,
			'plan_must_have_errors' => $this->plan_must_have_errors,
			'plan_required'       => $this->plan_required,
		);
	}

	/**
	 * @param array{code: string, severity: string, message: string, identity: string|null}|null $actual
	 * @return array<int, string>
	 */
	public function mismatches( ?array $actual, ?CompiledSitePlan $plan, bool $writes_detected ): array {
		$failures = array();
		if ( $actual !== $this->diagnostic ) {
			$failures[] = 'diagnostic';
		}

		if ( null !== $plan ) {
			$diagnostics = array_map(
				static fn ( CompiledSiteDiagnostic $diagnostic ): array => $diagnostic->to_array(),
				$plan->diagnostics()
			);
			if ( 1 !== count( $diagnostics ) || $diagnostics[0] !== $this->diagnostic ) {
				$failures[] = 'plan diagnostics';
			}
			if ( $this->plan_must_have_errors && ! $plan->has_errors() ) {
				$failures[] = 'plan error state';
			}
		} elseif ( $this->plan_required ) {
			$failures[] = 'missing error plan';
		}

		if ( $writes_detected ) {
			$failures[] = 'writes detected';
		}

		return $failures;
	}
}
