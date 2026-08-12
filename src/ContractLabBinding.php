<?php
/**
 * Ignored maintainer-local Contract Lab binding material.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Records locators that must be re-resolved and cross-checked with a marker.
 *
 * None of these locators authorizes a mutation by itself.
 */
final class ContractLabBinding {

	public const BINDING_VERSION = '1';

	public const LAB_ID = 'etch-builders-contract-lab';

	public const MARKER_OPTION = 'etch_builders_contract_lab_marker';

	public const FIXTURE_NAMESPACE = 'etch-builders-contract-lab';

	private function __construct(
		private readonly string $site_id,
		private readonly string $site_name,
		private readonly string $site_url,
		private readonly string $web_root,
		private readonly string $marker_id
	) {
	}

	/**
	 * Create binding material without reading or mutating LocalWP.
	 */
	public static function new(
		string $site_id,
		string $site_name,
		string $site_url,
		string $web_root,
		string $marker_id
	): self {
		ContractLabManifestSafety::assert_local_identifier( $site_id, 'Contract Lab Local site ID' );
		ContractLabManifestSafety::assert_stable_id( $site_name, 'Contract Lab Local site name' );
		$site_url = ContractLabManifestSafety::normalize_origin( $site_url, 'Contract Lab site URL' );
		$web_root = ContractLabManifestSafety::normalize_absolute_path( $web_root, 'Contract Lab web root' );
		ContractLabManifestSafety::assert_stable_id( $marker_id, 'Contract Lab marker ID' );

		return new self( $site_id, $site_name, $site_url, $web_root, $marker_id );
	}

	/**
	 * Rehydrate binding material. The binding version is checked before any
	 * locator is interpreted.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys(
			$record,
			array( 'binding_version', 'lab_id', 'site_id', 'site_name', 'site_url', 'web_root', 'marker_id' ),
			'Contract Lab binding'
		);
		if ( ! is_string( $record['binding_version'] ) || self::BINDING_VERSION !== $record['binding_version'] ) {
			$version = is_string( $record['binding_version'] ) ? $record['binding_version'] : 'unknown';
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab binding version "%s".', $version ) );
		}
		if ( ! is_string( $record['lab_id'] ) || self::LAB_ID !== $record['lab_id'] || ! is_string( $record['site_id'] ) || ! is_string( $record['site_name'] ) || ! is_string( $record['site_url'] ) || ! is_string( $record['web_root'] ) || ! is_string( $record['marker_id'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab binding has invalid field shapes or lab identity.' );
		}

		$binding = self::new( $record['site_id'], $record['site_name'], $record['site_url'], $record['web_root'], $record['marker_id'] );
		if ( $binding->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab binding must be canonical.' );
		}

		return $binding;
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

	public function marker_id(): string {
		return $this->marker_id;
	}

	/**
	 * @return array{binding_version: string, lab_id: string, site_id: string, site_name: string, site_url: string, web_root: string, marker_id: string}
	 */
	public function to_array(): array {
		return array(
			'binding_version' => self::BINDING_VERSION,
			'lab_id'          => self::LAB_ID,
			'site_id'         => $this->site_id,
			'site_name'       => $this->site_name,
			'site_url'        => $this->site_url,
			'web_root'        => $this->web_root,
			'marker_id'       => $this->marker_id,
		);
	}
}
