<?php
/**
 * One typed dependency in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Immutable consumer-to-dependency edge.
 */
final class CompiledSiteDependency {

	private function __construct(
		private readonly string $consumer_identity,
		private readonly string $dependency_identity,
		private readonly string $kind
	) {
	}

	/**
	 * Create one dependency edge.
	 *
	 * @param string $consumer_identity Entity that consumes the dependency.
	 * @param string $dependency_identity Resolved dependency identity.
	 * @param string $kind Dependency kind, such as "pattern".
	 */
	public static function new( string $consumer_identity, string $dependency_identity, string $kind ): self {
		return new self(
			self::validate_identity( $consumer_identity, 'consumer' ),
			self::validate_identity( $dependency_identity, 'dependency' ),
			self::validate_kind( $kind )
		);
	}

	/**
	 * Create the dependency edge for a registered Pattern Use.
	 */
	public static function pattern( string $consumer_identity, PatternUse $pattern_use ): self {
		return self::new( $consumer_identity, 'pattern:' . $pattern_use->pattern_key(), 'pattern' );
	}

	public function consumer_identity(): string {
		return $this->consumer_identity;
	}

	public function dependency_identity(): string {
		return $this->dependency_identity;
	}

	public function kind(): string {
		return $this->kind;
	}

	/**
	 * @return array{consumer: string, dependency: string, kind: string}
	 */
	public function to_array(): array {
		return array(
			'consumer'   => $this->consumer_identity,
			'dependency' => $this->dependency_identity,
			'kind'       => $this->kind,
		);
	}

	private static function validate_identity( string $identity, string $label ): string {
		$identity = trim( $identity );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*(?::[A-Za-z0-9][A-Za-z0-9_.-]*)+$/D', $identity ) ) {
			throw new InvalidArgumentException( sprintf( 'Compiled Site dependency %s identity must be a stable type:key value.', $label ) );
		}

		return $identity;
	}

	private static function validate_kind( string $kind ): string {
		$kind = trim( $kind );
		if ( '' === $kind || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/D', $kind ) ) {
			throw new InvalidArgumentException( 'Compiled Site dependency kind must be a non-empty lowercase identifier.' );
		}

		return $kind;
	}
}
