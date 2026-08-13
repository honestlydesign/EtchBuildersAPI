<?php
/**
 * Ordered composite frontend fixture catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Keeps one small reusable fixture set explicit and deterministic.
 */
final class ContractLabFrontendFixtureCatalog {

	/**
	 * Return the marker-verified current-Etch fixture inventory.
	 *
	 * The path and marker values are intentionally kept here, beside the
	 * fixture value object, so a maintainer gate cannot silently drift from the
	 * Contract Lab site contract.
	 */
	public static function current(): self {
		return self::new(
			array(
				ContractLabFrontendFixture::new(
					'marketing-home',
					'/contract-fixtures/marketing-home/',
					array(
						'dom'        => 'marketing-home',
						'stylesheet' => '.marketing-card',
						'class'      => 'marketing-card',
						'slot'       => 'headline',
						'loop'        => 'item-1',
						'dynamic'    => 'title',
					)
				),
			)
		);
	}

	/**
	 * @param array<int, ContractLabFrontendFixture> $fixtures
	 */
	private function __construct( private readonly array $fixtures ) {
	}

	/**
	 * @param array<int, ContractLabFrontendFixture> $fixtures
	 */
	public static function new( array $fixtures ): self {
		if ( array() === $fixtures || ! array_is_list( $fixtures ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture catalog must be a non-empty ordered list.' );
		}
		$ids   = array();
		$paths = array();
		foreach ( $fixtures as $fixture ) {
			if ( ! $fixture instanceof ContractLabFrontendFixture ) {
				throw new InvalidArgumentException( 'Contract Lab frontend fixture catalog must contain fixture definitions.' );
			}
			if ( isset( $ids[ $fixture->logical_id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab frontend fixture catalog has duplicate logical identity "%s".', $fixture->logical_id() ) );
			}
			if ( isset( $paths[ $fixture->path() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab frontend fixture catalog has duplicate HTTP path "%s".', $fixture->path() ) );
			}
			$ids[ $fixture->logical_id() ] = true;
			$paths[ $fixture->path() ]     = true;
		}

		return new self( array_values( $fixtures ) );
	}

	/**
	 * @param array<string, mixed> $projection
	 */
	public static function from_array( array $projection ): self {
		AcyclicArrayGuard::assert_acyclic( $projection );
		if ( array( 'fixtures' ) !== array_keys( $projection ) || ! is_array( $projection['fixtures'] ) || ! array_is_list( $projection['fixtures'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture catalog projection must contain an ordered fixtures list.' );
		}
		$fixtures = array();
		foreach ( $projection['fixtures'] as $fixture ) {
			if ( ! is_array( $fixture ) ) {
				throw new InvalidArgumentException( 'Contract Lab frontend fixture catalog projection contains a malformed fixture.' );
			}
			$fixtures[] = ContractLabFrontendFixture::from_array( $fixture );
		}
		$catalog = self::new( $fixtures );
		if ( $catalog->to_array() !== ImmutableArray::copy( $projection, 'Contract Lab frontend fixture catalog projection must contain scalar values.' ) ) {
			throw new InvalidArgumentException( 'Contract Lab frontend fixture catalog projection must be canonical.' );
		}

		return $catalog;
	}

	/**
	 * @return array<int, ContractLabFrontendFixture>
	 */
	public function all(): array {
		return $this->fixtures;
	}

	public function fixture( string $logical_id ): ContractLabFrontendFixture {
		foreach ( $this->fixtures as $fixture ) {
			if ( $fixture->logical_id() === $logical_id ) {
				return $fixture;
			}
		}

		throw new InvalidArgumentException( sprintf( 'Contract Lab frontend fixture catalog has no fixture "%s".', $logical_id ) );
	}

	/**
	 * @return array{fixtures: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array( 'fixtures' => array_map( static fn ( ContractLabFrontendFixture $fixture ): array => $fixture->to_array(), $this->fixtures ) );
	}
}
