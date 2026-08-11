<?php
/**
 * Ordered class-style value for one Etch component property.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Holds validated Class Style References with explicit order and empty semantics.
 */
final class ClassStyleSet {

	/**
	 * Ordered references.
	 *
	 * @var array<int, ClassStyleReference>
	 */
	private array $references;

	/**
	 * Constructor.
	 *
	 * @param array<int, ClassStyleReference> $references Ordered references.
	 * @throws InvalidArgumentException When an ID or selector appears more than once.
	 */
	private function __construct( array $references ) {
		$seen_ids       = array();
		$seen_selectors = array();

		foreach ( $references as $reference ) {
			$id       = $reference->id();
			$selector = $reference->selector();

			if ( isset( $seen_ids[ $id ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ClassStyleSet cannot contain duplicate Class Style ID "%s".', $id )
				);
			}

			if ( isset( $seen_selectors[ $selector ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ClassStyleSet cannot contain duplicate class selector "%s" under different IDs.', $selector )
				);
			}

			$seen_ids[ $id ]             = true;
			$seen_selectors[ $selector ] = true;
		}

		$this->references = array_values( $references );
	}

	/**
	 * Create a non-empty ordered set.
	 *
	 * @param ClassStyleReference    $first First required reference.
	 * @param ClassStyleReference ...$rest Remaining ordered references.
	 */
	public static function of( ClassStyleReference $first, ClassStyleReference ...$rest ): self {
		$references = array( $first );
		foreach ( $rest as $reference ) {
			$references[] = $reference;
		}

		return new self( $references );
	}

	/**
	 * Create an explicit empty class-property override.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Return ordered references as a defensive array value.
	 *
	 * @return array<int, ClassStyleReference>
	 */
	public function references(): array {
		return $this->references;
	}

	/**
	 * Return ordered opaque style IDs.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_map(
			static fn ( ClassStyleReference $reference ): string => $reference->id(),
			$this->references
		);
	}

	/**
	 * Whether this is the explicit empty override.
	 */
	public function is_empty(): bool {
		return array() === $this->references;
	}
}
