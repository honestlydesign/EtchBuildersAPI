<?php
/**
 * Independently re-resolved LocalWP and WordPress site state.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Carries the live facts checked against ignored binding material.
 */
final class ContractLabSiteState {

	/**
	 * @param array<int, string> $active_prerequisites
	 */
	private function __construct(
		private readonly string $site_id,
		private readonly string $site_name,
		private readonly string $site_url,
		private readonly string $web_root,
		private readonly string $wordpress_root,
		private readonly string $environment_type,
		private readonly bool $single_site,
		private readonly array $active_prerequisites,
		private readonly ?ContractLabMarker $marker
	) {
	}

	/**
	 * Build live state from separate LocalWP and WordPress observations.
	 *
	 * A missing marker is represented explicitly so the verifier can fail
	 * closed with a useful diagnostic instead of treating a locator as proof.
	 *
	 * @param array<int, string> $active_prerequisites
	 */
	public static function new(
		string $site_id,
		string $site_name,
		string $site_url,
		string $web_root,
		string $wordpress_root,
		string $environment_type,
		bool $single_site,
		array $active_prerequisites,
		?ContractLabMarker $marker
	): self {
		ContractLabManifestSafety::assert_local_identifier( $site_id, 'Resolved Local site ID' );
		ContractLabManifestSafety::assert_stable_id( $site_name, 'Resolved Local site name' );
		$site_url = ContractLabManifestSafety::normalize_origin( $site_url, 'Resolved Contract Lab site URL' );
		$web_root = ContractLabManifestSafety::normalize_absolute_path( $web_root, 'Resolved Contract Lab web root' );
		ContractLabManifestSafety::assert_stable_token( $wordpress_root, 'Resolved WordPress root' );
		if ( ! in_array( $environment_type, array( 'local', 'development' ), true ) ) {
			throw new InvalidArgumentException( 'Resolved WordPress environment must be local or development.' );
		}
		if ( ! array_is_list( $active_prerequisites ) ) {
			throw new InvalidArgumentException( 'Resolved active prerequisites must be an ordered list.' );
		}

		$seen = array();
		foreach ( $active_prerequisites as $prerequisite ) {
			if ( ! is_string( $prerequisite ) ) {
				throw new InvalidArgumentException( 'Resolved active prerequisites must contain stable tokens.' );
			}
			ContractLabManifestSafety::assert_stable_token( $prerequisite, 'Resolved active prerequisite' );
			if ( isset( $seen[ $prerequisite ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Resolved active prerequisites contain duplicate token "%s".', $prerequisite ) );
			}
			$seen[ $prerequisite ] = true;
		}

		return new self( $site_id, $site_name, $site_url, $web_root, $wordpress_root, $environment_type, $single_site, array_values( $active_prerequisites ), $marker );
	}

	public function site_id(): string {
		return $this->site_id;
	}

	public function site_name(): string {
		return $this->site_name;
	}

	public function site_url(): string {
		return $this->site_url;
	}

	public function web_root(): string {
		return $this->web_root;
	}

	public function wordpress_root(): string {
		return $this->wordpress_root;
	}

	public function environment_type(): string {
		return $this->environment_type;
	}

	public function is_single_site(): bool {
		return $this->single_site;
	}

	/**
	 * @return array<int, string>
	 */
	public function active_prerequisites(): array {
		return $this->active_prerequisites;
	}

	public function marker(): ?ContractLabMarker {
		return $this->marker;
	}
}
