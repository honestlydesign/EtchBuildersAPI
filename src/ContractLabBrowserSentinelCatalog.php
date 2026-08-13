<?php
/**
 * Ordered browser preservation sentinel catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Ensures each sentinel covers one distinct destructive client save action.
 */
final class ContractLabBrowserSentinelCatalog {

	/**
	 * Return the four current-Etch editor save sentinels.
	 */
	public static function current(): self {
		return self::new(
			array(
				ContractLabBrowserSentinel::new( 'document-preservation', 'document', 'marketing-home', '/editor/documents', 'save-document' ),
				ContractLabBrowserSentinel::new( 'component-preservation', 'component', 'marketing-home', '/editor/components', 'save-component' ),
				ContractLabBrowserSentinel::new( 'pattern-preservation', 'pattern', 'marketing-home', '/editor/patterns', 'save-pattern' ),
				ContractLabBrowserSentinel::new( 'global-asset-preservation', 'global-asset', 'marketing-home', '/editor/assets', 'save-global-asset' ),
			)
		);
	}

	/**
	 * @param array<int, ContractLabBrowserSentinel> $sentinels
	 */
	private function __construct( private readonly array $sentinels ) {
	}

	/**
	 * @param array<int, ContractLabBrowserSentinel> $sentinels
	 */
	public static function new( array $sentinels ): self {
		if ( array() === $sentinels || ! array_is_list( $sentinels ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel catalog must be a non-empty ordered list.' );
		}
		$ids    = array();
		$actions = array();
		$entities = array();
		foreach ( $sentinels as $sentinel ) {
			if ( ! $sentinel instanceof ContractLabBrowserSentinel ) {
				throw new InvalidArgumentException( 'Contract Lab browser sentinel catalog must contain sentinel definitions.' );
			}
			if ( isset( $ids[ $sentinel->logical_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab browser sentinel catalog has duplicate logical identity "%s".', $sentinel->logical_id() ) );
			}
			if ( isset( $actions[ $sentinel->save_action_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab browser sentinel catalog has duplicate save action "%s".', $sentinel->save_action_id() ) );
			}
			$ids[ $sentinel->logical_id() ]          = true;
			$actions[ $sentinel->save_action_id() ] = true;
			$entities[ $sentinel->entity_type() ]   = true;
		}
		$missing_entities = array_values( array_diff( ContractLabBrowserSentinel::ENTITY_TYPES, array_keys( $entities ) ) );
		if ( array() !== $missing_entities ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab browser sentinel catalog is missing entity type(s): %s.', implode( ', ', $missing_entities ) ) );
		}

		return new self( array_values( $sentinels ) );
	}

	/**
	 * @param array<string, mixed> $projection
	 */
	public static function from_array( array $projection ): self {
		AcyclicArrayGuard::assert_acyclic( $projection );
		if ( array( 'sentinels' ) !== array_keys( $projection ) || ! is_array( $projection['sentinels'] ) || ! array_is_list( $projection['sentinels'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel catalog projection must contain an ordered sentinels list.' );
		}
		$sentinels = array();
		foreach ( $projection['sentinels'] as $sentinel ) {
			if ( ! is_array( $sentinel ) ) {
				throw new InvalidArgumentException( 'Contract Lab browser sentinel catalog projection contains a malformed sentinel.' );
			}
			$sentinels[] = ContractLabBrowserSentinel::from_array( $sentinel );
		}
		$catalog = self::new( $sentinels );
		if ( $catalog->to_array() !== ImmutableArray::copy( $projection, 'Contract Lab browser sentinel catalog projection must contain scalar values.' ) ) {
			throw new InvalidArgumentException( 'Contract Lab browser sentinel catalog projection must be canonical.' );
		}

		return $catalog;
	}

	/**
	 * @return array<int, ContractLabBrowserSentinel>
	 */
	public function all(): array {
		return $this->sentinels;
	}

	public function sentinel( string $logical_id ): ContractLabBrowserSentinel {
		foreach ( $this->sentinels as $sentinel ) {
			if ( $sentinel->logical_id() === $logical_id ) {
				return $sentinel;
			}
		}

		throw new InvalidArgumentException( sprintf( 'Contract Lab browser sentinel catalog has no sentinel "%s".', $logical_id ) );
	}

	/**
	 * @return array{sentinels: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array( 'sentinels' => array_map( static fn ( ContractLabBrowserSentinel $sentinel ): array => $sentinel->to_array(), $this->sentinels ) );
	}
}
