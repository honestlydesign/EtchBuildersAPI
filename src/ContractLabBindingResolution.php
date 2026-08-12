<?php
/**
 * Read-only result of a verified Contract Lab binding preflight.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Reports the exact logical mutation scopes available after verification.
 */
final class ContractLabBindingResolution {

	/**
	 * @param array<int, array{kind: string, identity: string}> $mutable_targets
	 */
	private function __construct(
		private readonly string $site_id,
		private readonly string $site_url,
		private readonly string $web_root,
		private readonly string $wordpress_root,
		private readonly string $marker_id,
		private readonly array $mutable_targets
	) {
	}

	/**
	 * @param array<int, array{kind: string, identity: string}> $mutable_targets
	 */
	public static function verified(
		string $site_id,
		string $site_url,
		string $web_root,
		string $wordpress_root,
		string $marker_id,
		array $mutable_targets
	): self {
		return new self( $site_id, $site_url, $web_root, $wordpress_root, $marker_id, $mutable_targets );
	}

	public function status(): string {
		return 'verified';
	}

	public function site_id(): string {
		return $this->site_id;
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

	public function marker_id(): string {
		return $this->marker_id;
	}

	/**
	 * @return array<int, array{kind: string, identity: string}>
	 */
	public function mutable_targets(): array {
		return $this->mutable_targets;
	}

	/**
	 * @return array{status: string, site_id: string, site_url: string, web_root: string, wordpress_root: string, marker_id: string, mutable_targets: array<int, array{kind: string, identity: string}>}
	 */
	public function to_array(): array {
		return array(
			'status'          => $this->status(),
			'site_id'         => $this->site_id,
			'site_url'        => $this->site_url,
			'web_root'        => $this->web_root,
			'wordpress_root'  => $this->wordpress_root,
			'marker_id'       => $this->marker_id,
			'mutable_targets' => $this->mutable_targets,
		);
	}

	/**
	 * Alias for callers that need an explicit preflight report.
	 *
	 * @return array{status: string, site_id: string, site_url: string, web_root: string, wordpress_root: string, marker_id: string, mutable_targets: array<int, array{kind: string, identity: string}>}
	 */
	public function report(): array {
		return $this->to_array();
	}
}
