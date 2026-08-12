<?php
/**
 * Declarative maintainer-only browser preservation sentinel.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Describes one destructive client save path without inventing Etch UI APIs.
 */
final class ContractLabBrowserSentinel {

	public const SENTINEL_VERSION = '1';

	/** @var array<int, string> */
	public const ENTITY_TYPES = array( 'document', 'component', 'pattern', 'global-asset' );

	private function __construct(
		private readonly string $logical_id,
		private readonly string $entity_type,
		private readonly string $fixture_id,
		private readonly string $editor_path,
		private readonly string $save_action_id
	) {
	}

	public static function new(
		string $logical_id,
		string $entity_type,
		string $fixture_id,
		string $editor_path,
		string $save_action_id
	): self {
		ContractLabManifestSafety::assert_stable_id( $logical_id, 'Contract Lab browser sentinel logical identity' );
		ContractLabManifestSafety::assert_stable_id( $fixture_id, 'Contract Lab browser sentinel fixture identity' );
		ContractLabManifestSafety::assert_stable_id( $save_action_id, 'Contract Lab browser sentinel save action identity' );
		if ( ! in_array( $entity_type, self::ENTITY_TYPES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab browser sentinel entity type "%s".', $entity_type ) );
		}
		self::assert_editor_path( $editor_path );

		return new self( $logical_id, $entity_type, $fixture_id, $editor_path, $save_action_id );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		$expected = array( 'editor_path', 'entity_type', 'fixture_id', 'logical_id', 'save_action_id', 'sentinel_version' );
		sort( $expected );
		if ( $keys !== $expected || self::SENTINEL_VERSION !== ( $record['sentinel_version'] ?? null ) || ! is_string( $record['logical_id'] ?? null ) || ! is_string( $record['entity_type'] ?? null ) || ! is_string( $record['fixture_id'] ?? null ) || ! is_string( $record['editor_path'] ?? null ) || ! is_string( $record['save_action_id'] ?? null ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel has an unknown version or field set.' );
		}
		$sentinel = self::new( $record['logical_id'], $record['entity_type'], $record['fixture_id'], $record['editor_path'], $record['save_action_id'] );
		if ( $sentinel->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel must be canonical.' );
		}

		return $sentinel;
	}

	public function logical_id(): string {
		return $this->logical_id;
	}

	public function entity_type(): string {
		return $this->entity_type;
	}

	public function fixture_id(): string {
		return $this->fixture_id;
	}

	public function editor_path(): string {
		return $this->editor_path;
	}

	public function save_action_id(): string {
		return $this->save_action_id;
	}

	/**
	 * @return array{sentinel_version: string, logical_id: string, entity_type: string, fixture_id: string, editor_path: string, save_action_id: string}
	 */
	public function to_array(): array {
		return array(
			'sentinel_version' => self::SENTINEL_VERSION,
			'logical_id'       => $this->logical_id,
			'entity_type'      => $this->entity_type,
			'fixture_id'       => $this->fixture_id,
			'editor_path'      => $this->editor_path,
			'save_action_id'   => $this->save_action_id,
		);
	}

	private static function assert_editor_path( string $path ): void {
		if ( '' === $path || trim( $path ) !== $path || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '/[[:cntrl:]#]/', $path ) || str_contains( $path, chr( 92 ) ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel editor path must be root-relative and credential-free.' );
		}
		$parsed = parse_url( $path );
		if ( ! is_array( $parsed ) || isset( $parsed['scheme'] ) || isset( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['fragment'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel editor path must not contain an origin, credentials, or fragment.' );
		}
	}
}
