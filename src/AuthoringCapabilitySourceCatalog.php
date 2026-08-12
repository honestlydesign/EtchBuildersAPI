<?php
/**
 * Ordered catalog of hand-curated source boundaries.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * The only hand-authored input accepted by the interface-fact generator.
 */
final class AuthoringCapabilitySourceCatalog {

	/**
	 * @var array<int, AuthoringCapabilitySourceDeclaration>
	 */
	private readonly array $declarations;

	/**
	 * @var array<string, AuthoringCapabilitySourceDeclaration>
	 */
	private readonly array $declarations_by_id;

	/**
	 * @param array<int, AuthoringCapabilitySourceDeclaration> $declarations
	 */
	private function __construct( array $declarations ) {
		$by_id = array();
		foreach ( $declarations as $declaration ) {
			if ( isset( $by_id[ $declaration->capability_id() ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Authoring source catalog has duplicate capability ID "%s".', $declaration->capability_id() )
				);
			}

			$by_id[ $declaration->capability_id() ] = $declaration;
		}

		$this->declarations       = array_values( $declarations );
		$this->declarations_by_id = $by_id;
	}

	public static function from_declarations( AuthoringCapabilitySourceDeclaration ...$declarations ): self {
		return new self( array_values( $declarations ) );
	}

	public static function empty(): self {
		return new self( array() );
	}

	/**
	 * Rehydrate only source selections; generated facts are not accepted here.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'sources' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring source catalog must contain exactly sources.' );
		}

		$source_records = $record['sources'];
		if ( ! is_array( $source_records ) || ! array_is_list( $source_records ) ) {
			throw new InvalidArgumentException( 'Authoring source catalog sources must be a list.' );
		}

		$declarations = array();
		foreach ( $source_records as $source_record ) {
			if ( ! is_array( $source_record ) ) {
				throw new InvalidArgumentException( 'Authoring source catalog entries must be object records.' );
			}

			$declarations[] = AuthoringCapabilitySourceDeclaration::from_array( $source_record );
		}

		$catalog = self::from_declarations( ...$declarations );
		if ( $catalog->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring source catalog must be a canonical model projection.' );
		}

		return $catalog;
	}

	public function has( string $capability_id ): bool {
		return isset( $this->declarations_by_id[ $capability_id ] );
	}

	public function for_capability( string $capability_id ): AuthoringCapabilitySourceDeclaration {
		if ( ! isset( $this->declarations_by_id[ $capability_id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source catalog has no capability ID "%s".', $capability_id ) );
		}

		return $this->declarations_by_id[ $capability_id ];
	}

	/**
	 * @return array<int, AuthoringCapabilitySourceDeclaration>
	 */
	public function all(): array {
		return $this->declarations;
	}

	/**
	 * @return array{sources: array<int, array{id: string, symbols: array<int, array{class: string, method: string}>}>}
	 */
	public function to_array(): array {
		return array(
			'sources' => array_map(
				static fn ( AuthoringCapabilitySourceDeclaration $declaration ): array => $declaration->to_array(),
				$this->declarations
			),
		);
	}
}
