<?php
/**
 * Composite HTTP fixture definition for frontend Contract Lab probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Maps one rendered fixture to several observable frontend capabilities.
 *
 * Marker values are intentionally small and semantic:
 * - dom: data-contract-fixture value
 * - stylesheet: exact normalized CSS selector
 * - class: exact class token
 * - slot, loop, dynamic: data-contract-{capability} value
 */
final class ContractLabFrontendFixture {

	public const FIXTURE_VERSION = '1';

	/** @var array<int, string> */
	public const CAPABILITIES = array( 'dom', 'stylesheet', 'class', 'slot', 'loop', 'dynamic' );

	/**
	 * @param array<string, string> $markers
	 */
	private function __construct(
		private readonly string $logical_id,
		private readonly string $path,
		private readonly array $markers
	) {
	}

	/**
	 * @param array<string, string> $markers
	 */
	public static function new( string $logical_id, string $path, array $markers ): self {
		ContractLabManifestSafety::assert_stable_id( $logical_id, 'Contract Lab frontend fixture logical identity' );
		self::assert_path( $path );
		if ( array() === $markers ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture markers must be a non-empty capability map.' );
		}
		AcyclicArrayGuard::assert_acyclic( $markers );
		$markers = ImmutableArray::copy( $markers, 'Contract Lab frontend fixture markers must contain scalar values.' );
		foreach ( $markers as $capability => $marker ) {
			if ( ! is_string( $capability ) || ! in_array( $capability, self::CAPABILITIES, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown frontend capability "%s".', (string) $capability ) );
			}
			if ( ! is_string( $marker ) || '' === $marker || trim( $marker ) !== $marker || preg_match( '/[[:cntrl:]]/', $marker ) ) {
				throw new InvalidArgumentException( sprintf( 'Frontend capability marker "%s" must be a safe non-empty string.', $capability ) );
			}
		}

		return new self( $logical_id, $path, $markers );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		$expected = array( 'capabilities', 'fixture_version', 'logical_id', 'markers', 'path' );
		sort( $expected );
		if ( $keys !== $expected || self::FIXTURE_VERSION !== ( $record['fixture_version'] ?? null ) || ! is_string( $record['logical_id'] ?? null ) || ! is_string( $record['path'] ?? null ) || ! is_array( $record['markers'] ?? null ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture has an unknown version or field set.' );
		}
		/** @var array<string, string> $markers */
		$markers = $record['markers'];
		$fixture = self::new( $record['logical_id'], $record['path'], $markers );
		if ( $fixture->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture must be canonical.' );
		}

		return $fixture;
	}

	public function logical_id(): string {
		return $this->logical_id;
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * @return array<int, string>
	 */
	public function capabilities(): array {
		return array_keys( $this->markers );
	}

	/**
	 * @return array<string, string>
	 */
	public function markers(): array {
		return $this->markers;
	}

	public function marker( string $capability ): string {
		if ( ! isset( $this->markers[ $capability ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Frontend fixture "%s" has no capability "%s".', $this->logical_id, $capability ) );
		}

		return $this->markers[ $capability ];
	}

	/**
	 * @return array{fixture_version: string, logical_id: string, path: string, capabilities: array<int, string>, markers: array<string, string>}
	 */
	public function to_array(): array {
		return array(
			'fixture_version' => self::FIXTURE_VERSION,
			'logical_id'      => $this->logical_id,
			'path'            => $this->path,
			'capabilities'    => $this->capabilities(),
			'markers'         => $this->markers,
		);
	}

	private static function assert_path( string $path ): void {
		if ( '' === $path || trim( $path ) !== $path || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '/[[:cntrl:]#]/', $path ) || str_contains( $path, chr( 92 ) ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture path must be a root-relative HTTP path.' );
		}
		$parsed = parse_url( $path );
		if ( ! is_array( $parsed ) || isset( $parsed['scheme'] ) || isset( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['fragment'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture path must not contain an origin, credentials, or fragment.' );
		}
	}
}
