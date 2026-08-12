<?php
/**
 * One typed result from a compiled Site persistence apply.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Immutable result for one plan entity or resource.
 */
final class SitePersistenceResult {

	private function __construct(
		private readonly string $identity,
		private readonly SitePersistenceOutcome $outcome,
		private readonly string $code,
		private readonly string $message
	) {
	}

	public static function new( string $identity, SitePersistenceOutcome $outcome, string $code, string $message ): self {
		$identity = trim( $identity );
		$code     = trim( $code );
		$message  = trim( $message );
		if ( '' === $identity || '' === $code || '' === $message ) {
			throw new InvalidArgumentException( 'Site persistence results require identity, code, and message.' );
		}

		return new self( $identity, $outcome, $code, $message );
	}

	public function identity(): string {
		return $this->identity;
	}

	public function outcome(): SitePersistenceOutcome {
		return $this->outcome;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function is_success(): bool {
		return $this->outcome->is_success();
	}

	/**
	 * @return array{identity: string, outcome: string, code: string, message: string}
	 */
	public function to_array(): array {
		return array(
			'identity' => $this->identity,
			'outcome'  => $this->outcome->value,
			'code'     => $this->code,
			'message'  => $this->message,
		);
	}
}
