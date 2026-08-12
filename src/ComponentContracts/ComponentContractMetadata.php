<?php
/**
 * Curated metadata for one schema-derived component contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use InvalidArgumentException;

/**
 * Keeps support admission separate from merely observing a valid schema.
 */
final class ComponentContractMetadata {

	private const RECIPE_ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/';

	/**
	 * @param array<int, string> $recipe_ids Stable ordered recipe IDs.
	 */
	private function __construct(
		private readonly ComponentContractStatus $status,
		private readonly array $recipe_ids
	) {
	}

	/**
	 * Create metadata for an observed component that is not yet agent-supported.
	 */
	public static function pending( string ...$recipe_ids ): self {
		return new self( ComponentContractStatus::PENDING, self::validate_recipe_ids( array_values( $recipe_ids ) ) );
	}

	/**
	 * Admit one component only with at least one discoverable canonical recipe.
	 */
	public static function supported( string $first_recipe_id, string ...$additional_recipe_ids ): self {
		if ( '' === $first_recipe_id ) {
			throw new InvalidArgumentException( 'Supported component metadata requires at least one recipe ID.' );
		}

		return new self(
			ComponentContractStatus::SUPPORTED,
			self::validate_recipe_ids( array_values( array_merge( array( $first_recipe_id ), $additional_recipe_ids ) ) )
		);
	}

	/**
	 * Rehydrate accepted metadata fields.
	 *
	 * @param array<string, mixed> $record Metadata record.
	 */
	public static function from_array( array $record ): self {
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'recipe_ids', 'status' ) !== $keys ) {
			throw new InvalidArgumentException( 'Accepted component metadata must contain exactly status and recipe_ids.' );
		}

		$status = is_string( $record['status'] )
			? ComponentContractStatus::tryFrom( $record['status'] )
			: null;
		if ( null === $status ) {
			throw new InvalidArgumentException( 'Accepted component status must be supported or pending.' );
		}

		$recipe_ids = $record['recipe_ids'];
		if ( ! is_array( $recipe_ids ) || ! array_is_list( $recipe_ids ) ) {
			throw new InvalidArgumentException( 'Accepted component recipe_ids must be a list.' );
		}

		if ( ComponentContractStatus::SUPPORTED === $status && array() === $recipe_ids ) {
			throw new InvalidArgumentException( 'Supported component metadata requires at least one recipe ID.' );
		}

		/** @var array<int, mixed> $recipe_ids */
		foreach ( $recipe_ids as $recipe_id ) {
			if ( ! is_string( $recipe_id ) ) {
				throw new InvalidArgumentException( 'Component recipe IDs must be exact stable ID strings.' );
			}
		}

		/** @var array<int, string> $recipe_ids */
		return new self( $status, self::validate_recipe_ids( $recipe_ids ) );
	}

	public function status(): ComponentContractStatus {
		return $this->status;
	}

	/**
	 * @return array<int, string>
	 */
	public function recipe_ids(): array {
		return $this->recipe_ids;
	}

	/**
	 * @return array{status: string, recipe_ids: array<int, string>}
	 */
	public function to_array(): array {
		return array(
			'status'     => $this->status->value,
			'recipe_ids' => $this->recipe_ids,
		);
	}

	/**
	 * @param array<int, string> $recipe_ids Recipe IDs to validate.
	 * @return array<int, string>
	 */
	private static function validate_recipe_ids( array $recipe_ids ): array {
		$seen      = array();
		$validated = array();
		foreach ( $recipe_ids as $recipe_id ) {
			if ( 1 !== preg_match( self::RECIPE_ID_PATTERN, $recipe_id ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component recipe ID "%s" must be an exact stable ID.', $recipe_id )
				);
			}

			if ( isset( $seen[ $recipe_id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Component metadata has duplicate recipe ID "%s".', $recipe_id ) );
			}

			$seen[ $recipe_id ] = true;
			$validated[]        = $recipe_id;
		}

		return $validated;
	}
}
