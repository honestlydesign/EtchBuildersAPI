<?php
/**
 * Versioned in-WordPress Contract Lab marker.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * The second half of binding authority, written and read in the target site.
 */
final class ContractLabMarker {

	public const MARKER_VERSION = '1';

	private function __construct(
		private readonly string $marker_id,
		private readonly string $site_id,
		private readonly string $wordpress_root
	) {
	}

	/**
	 * Create the marker value expected from the target WordPress installation.
	 */
	public static function new( string $marker_id, string $site_id, string $wordpress_root ): self {
		ContractLabManifestSafety::assert_stable_id( $marker_id, 'Contract Lab marker ID' );
		ContractLabManifestSafety::assert_local_identifier( $site_id, 'Contract Lab marker Local site ID' );
		ContractLabManifestSafety::assert_stable_token( $wordpress_root, 'Contract Lab marker WordPress root' );

		return new self( $marker_id, $site_id, $wordpress_root );
	}

	/**
	 * Rehydrate the marker before any identity fields are interpreted.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys( $record, array( 'marker_version', 'lab_id', 'marker_id', 'site_id', 'wordpress_root' ), 'Contract Lab marker' );
		if ( ! is_string( $record['marker_version'] ) || self::MARKER_VERSION !== $record['marker_version'] ) {
			$version = is_string( $record['marker_version'] ) ? $record['marker_version'] : 'unknown';
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab marker version "%s".', $version ) );
		}
		if ( ! is_string( $record['lab_id'] ) || ContractLabBinding::LAB_ID !== $record['lab_id'] || ! is_string( $record['marker_id'] ) || ! is_string( $record['site_id'] ) || ! is_string( $record['wordpress_root'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab marker has invalid field shapes or lab identity.' );
		}

		$marker = self::new( $record['marker_id'], $record['site_id'], $record['wordpress_root'] );
		if ( $marker->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab marker must be canonical.' );
		}

		return $marker;
	}

	public function marker_id(): string {
		return $this->marker_id;
	}

	public function site_id(): string {
		return $this->site_id;
	}

	public function wordpress_root(): string {
		return $this->wordpress_root;
	}

	/**
	 * @return array{marker_version: string, lab_id: string, marker_id: string, site_id: string, wordpress_root: string}
	 */
	public function to_array(): array {
		return array(
			'marker_version' => self::MARKER_VERSION,
			'lab_id'         => ContractLabBinding::LAB_ID,
			'marker_id'      => $this->marker_id,
			'site_id'        => $this->site_id,
			'wordpress_root' => $this->wordpress_root,
		);
	}
}
