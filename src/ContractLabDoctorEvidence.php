<?php
/**
 * Read-only runtime evidence consumed by the Contract Lab doctor.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries environment versions and public contract compatibility facts.
 */
final class ContractLabDoctorEvidence {

	private function __construct(
		private readonly string $wordpress_version,
		private readonly string $php_version,
		private readonly string $localwp_version,
		private readonly string $etch_version,
		private readonly string $etch_fingerprint,
		private readonly string $probe_schema_version,
		private readonly string $observation_schema_version
	) {
	}

	/**
	 * Build validated evidence. Unknown probe/observation versions remain
	 * representable so the doctor can classify them as contract drift.
	 */
	public static function new(
		string $wordpress_version,
		string $php_version,
		string $localwp_version,
		string $etch_version,
		string $etch_fingerprint,
		string $probe_schema_version,
		string $observation_schema_version
	): self {
		ContractLabVersionConstraint::assert_version( $wordpress_version, 'WordPress version' );
		ContractLabVersionConstraint::assert_version( $php_version, 'PHP version' );
		ContractLabVersionConstraint::assert_version( $localwp_version, 'LocalWP version' );
		ContractLabVersionConstraint::assert_version( $etch_version, 'Etch version' );
		ContractLabManifestSafety::assert_digest( $etch_fingerprint, 'Etch fingerprint' );
		if ( '' === $probe_schema_version || trim( $probe_schema_version ) !== $probe_schema_version || '' === $observation_schema_version || trim( $observation_schema_version ) !== $observation_schema_version ) {
			throw new \InvalidArgumentException( 'Contract Lab probe and observation schema versions must be non-empty exact strings.' );
		}

		return new self( $wordpress_version, $php_version, $localwp_version, $etch_version, $etch_fingerprint, $probe_schema_version, $observation_schema_version );
	}

	public function wordpress_version(): string {
		return $this->wordpress_version;
	}

	public function php_version(): string {
		return $this->php_version;
	}

	public function localwp_version(): string {
		return $this->localwp_version;
	}

	public function etch_version(): string {
		return $this->etch_version;
	}

	public function etch_fingerprint(): string {
		return $this->etch_fingerprint;
	}

	public function probe_schema_version(): string {
		return $this->probe_schema_version;
	}

	public function observation_schema_version(): string {
		return $this->observation_schema_version;
	}
}
