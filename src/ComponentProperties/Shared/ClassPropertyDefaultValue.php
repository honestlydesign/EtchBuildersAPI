<?php
/**
 * Validated class-property default value.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use InvalidArgumentException;

/**
 * Keeps the exact identity proofs behind one ordered class-property default.
 *
 * @internal
 */
final class ClassPropertyDefaultValue {

	/**
	 * @param array<int, ClassStyleReference> $references Exact ordered identity proofs.
	 */
	private function __construct( private array $references ) {
	}

	/**
	 * Validate a typed set or the legacy array representation.
	 *
	 * @param mixed $value ClassStyleSet or legacy style ID array.
	 * @throws InvalidArgumentException When the value or an identity is invalid.
	 */
	public static function from( mixed $value ): self {
		if ( $value instanceof ClassStyleSet ) {
			$references = $value->references();

			foreach ( $references as $reference ) {
				self::assert_reference_is_current( $reference );
			}

			return new self( $references );
		}

		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Class property default must be an array.' );
		}

		$references = array();
		foreach ( array_values( $value ) as $style_id ) {
			if ( ! is_string( $style_id ) ) {
				throw new InvalidArgumentException( 'Class property default must contain only strings.' );
			}

			$normalized_style_id = trim( $style_id );
			if ( '' === $normalized_style_id ) {
				throw new InvalidArgumentException( 'Class property default cannot contain empty style IDs.' );
			}

			$references[] = ClassStyleReference::registered( $normalized_style_id );
		}

		return new self( $references );
	}

	/**
	 * Return unchanged opaque IDs in author-provided order.
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
	 * Prove every remembered ID still denotes its original selector.
	 *
	 * @throws InvalidArgumentException When current registry identity differs.
	 */
	public function assert_current(): void {
		foreach ( $this->references as $reference ) {
			self::assert_reference_is_current( $reference );
		}
	}

	/**
	 * Prove one remembered ID still denotes the same exact selector.
	 *
	 * @throws InvalidArgumentException When current registry identity differs.
	 */
	private static function assert_reference_is_current( ClassStyleReference $reference ): void {
		$current = ClassStyleReference::registered( $reference->id() );

		if ( $current->selector() !== $reference->selector() ) {
			throw new InvalidArgumentException(
				sprintf(
					'Class Style ID "%s" changed selector identity from "%s" to "%s".',
					$reference->id(),
					$reference->selector(),
					$current->selector()
				)
			);
		}
	}
}
