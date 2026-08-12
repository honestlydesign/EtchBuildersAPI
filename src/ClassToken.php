<?php
/**
 * Explicitly provenanced HTML class token.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Keeps one class token, its owner kind, and any exact origin/style identity together.
 */
final class ClassToken {

	private function __construct(
		private readonly string $token,
		private readonly ClassProvenance $provenance,
		private readonly ?string $origin,
		private readonly ?ClassStyleReference $style_reference
	) {
	}

	/**
	 * Create a site-owned presentation class from its exact current style identity.
	 */
	public static function site_presentation( ClassStyleReference $reference ): self {
		$reference->assert_current();
		$token = substr( $reference->selector(), 1 );
		ClassNamingPolicy::assert_site_presentation( $token );

		return new self(
			$token,
			ClassProvenance::SITE_PRESENTATION,
			null,
			$reference
		);
	}

	/**
	 * Create an explicitly registered project utility class token.
	 */
	public static function project_utility( string $token ): self {
		return new self(
			self::validate_token( $token ),
			ClassProvenance::PROJECT_UTILITY,
			null,
			null
		);
	}

	/**
	 * Create a class token owned by a named external framework.
	 */
	public static function external_framework( string $token, string $origin ): self {
		return new self(
			self::validate_token( $token ),
			ClassProvenance::EXTERNAL_FRAMEWORK,
			self::validate_origin( $origin ),
			null
		);
	}

	/**
	 * Create a state class emitted and owned by a named runtime.
	 */
	public static function runtime_state( string $token, string $origin ): self {
		return new self(
			self::validate_token( $token ),
			ClassProvenance::RUNTIME_STATE,
			self::validate_origin( $origin ),
			null
		);
	}

	/**
	 * Return the unchanged HTML class token.
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Return the declared owner kind.
	 */
	public function provenance(): ClassProvenance {
		return $this->provenance;
	}

	/**
	 * Return the explicit framework/runtime origin, when applicable.
	 */
	public function origin(): ?string {
		return $this->origin;
	}

	/**
	 * Return the exact site-owned style identity, when applicable.
	 */
	public function style_reference(): ?ClassStyleReference {
		return $this->style_reference;
	}

	/**
	 * Revalidate mutable external style state before a block can mutate.
	 */
	public function assert_current(): void {
		if ( null !== $this->style_reference ) {
			$this->style_reference->assert_current();
		}
	}

	/**
	 * Whether two declarations identify the same token owner and origin.
	 */
	public function has_same_identity( self $other ): bool {
		$style_id       = null !== $this->style_reference ? $this->style_reference->id() : null;
		$other_style_id = null !== $other->style_reference ? $other->style_reference->id() : null;

		return $this->token === $other->token
			&& $this->provenance === $other->provenance
			&& $this->origin === $other->origin
			&& $style_id === $other_style_id;
	}

	/**
	 * Require one non-empty HTML class token without normalization.
	 */
	private static function validate_token( string $token ): string {
		if ( '' === $token || 1 === preg_match( '/[\x09\x0A\x0C\x0D\x20]/', $token ) ) {
			throw new InvalidArgumentException( 'A provenanced class requires one non-empty HTML class token without whitespace.' );
		}

		return $token;
	}

	/**
	 * Require one non-empty exact owner label without hidden normalization.
	 */
	private static function validate_origin( string $origin ): string {
		if ( '' === $origin || trim( $origin ) !== $origin ) {
			throw new InvalidArgumentException( 'External framework and runtime classes require a non-empty exact origin.' );
		}

		return $origin;
	}
}
