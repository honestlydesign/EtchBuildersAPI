<?php
/**
 * One diagnostic carried by a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Immutable diagnostic with stable code, severity, and optional identity.
 */
final class CompiledSiteDiagnostic {

	private function __construct(
		private readonly string $code,
		private readonly CompiledSiteDiagnosticSeverity $severity,
		private readonly string $message,
		private readonly ?string $identity
	) {
	}

	/**
	 * Create one diagnostic.
	 */
	public static function new(
		string $code,
		CompiledSiteDiagnosticSeverity $severity,
		string $message,
		?string $identity = null
	): self {
		$code    = trim( $code );
		$message = trim( $message );
		if ( '' === $code || 1 !== preg_match( '/^[A-Z][A-Z0-9_-]*$/D', $code ) ) {
			throw new InvalidArgumentException( 'Compiled Site diagnostic code must be a stable uppercase identifier.' );
		}
		if ( '' === $message ) {
			throw new InvalidArgumentException( 'Compiled Site diagnostic message must be non-empty.' );
		}
		if ( null !== $identity ) {
			$identity = trim( $identity );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+$/D', $identity ) ) {
				throw new InvalidArgumentException( 'Compiled Site diagnostic identity must be a stable type:key value.' );
			}
		}

		return new self( $code, $severity, $message, $identity );
	}

	public function code(): string {
		return $this->code;
	}

	public function severity(): CompiledSiteDiagnosticSeverity {
		return $this->severity;
	}

	public function message(): string {
		return $this->message;
	}

	public function identity(): ?string {
		return $this->identity;
	}

	/**
	 * @return array{code: string, severity: string, message: string, identity: string|null}
	 */
	public function to_array(): array {
		return array(
			'code'     => $this->code,
			'severity' => $this->severity->value,
			'message'  => $this->message,
			'identity' => $this->identity,
		);
	}
}
