<?php
/**
 * Read-only semantic diff for Contract Lab candidate observations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Compares normalized semantic payloads without accepting or persisting a snapshot.
 */
final class ContractLabSemanticDiff {

	/**
	 * @param array<int, array<string, mixed>> $changes
	 */
	private function __construct(
		private readonly string $status,
		private readonly array $changes
	) {
	}

	public static function compare( ContractLabCandidateObservation $before, ContractLabCandidateObservation $after ): self {
		$changes = array();
		self::compare_values( $before->semantic_projection(), $after->semantic_projection(), '', $changes );

		return new self( array() === $changes ? 'unchanged' : 'changed', $changes );
	}

	public function status(): string {
		return $this->status;
	}

	public function is_unchanged(): bool {
		return 'unchanged' === $this->status;
	}

	public function is_changed(): bool {
		return 'changed' === $this->status;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function changes(): array {
		return $this->changes;
	}

	/**
	 * @return array{status: string, changes: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'status'  => $this->status,
			'changes' => $this->changes,
		);
	}

	/**
	 * @param mixed                              $before
	 * @param mixed                              $after
	 * @param array<int, array<string, mixed>>   $changes
	 */
	private static function compare_values( mixed $before, mixed $after, string $path, array &$changes ): void {
		if ( is_array( $before ) && is_array( $after ) ) {
			$before_is_list = array_is_list( $before );
			$after_is_list  = array_is_list( $after );
			if ( $before_is_list !== $after_is_list ) {
				$changes[] = self::change( $path, 'changed', $before, $after );
				return;
			}

			if ( $before_is_list ) {
				$length = max( count( $before ), count( $after ) );
				for ( $index = 0; $index < $length; $index++ ) {
					$child_path = self::index_path( $path, $index );
					if ( ! array_key_exists( $index, $before ) ) {
						$changes[] = self::change( $child_path, 'added', null, $after[ $index ] );
						continue;
					}
					if ( ! array_key_exists( $index, $after ) ) {
						$changes[] = self::change( $child_path, 'removed', $before[ $index ], null );
						continue;
					}
					self::compare_values( $before[ $index ], $after[ $index ], $child_path, $changes );
				}
				return;
			}

			$keys = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
			sort( $keys );
			foreach ( $keys as $key ) {
				$child_path = self::key_path( $path, $key );
				if ( ! array_key_exists( $key, $before ) ) {
					$changes[] = self::change( $child_path, 'added', null, $after[ $key ] );
					continue;
				}
				if ( ! array_key_exists( $key, $after ) ) {
					$changes[] = self::change( $child_path, 'removed', $before[ $key ], null );
					continue;
				}
				self::compare_values( $before[ $key ], $after[ $key ], $child_path, $changes );
			}
			return;
		}

		if ( $before !== $after ) {
			$changes[] = self::change( $path, 'changed', $before, $after );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function change( string $path, string $kind, mixed $before, mixed $after ): array {
		return array(
			'path'   => $path,
			'kind'   => $kind,
			'before' => $before,
			'after'  => $after,
		);
	}

	private static function key_path( string $path, int|string $key ): string {
		$key = (string) $key;

		return '' === $path ? $key : $path . '.' . $key;
	}

	private static function index_path( string $path, int $index ): string {
		return $path . '[' . $index . ']';
	}
}
