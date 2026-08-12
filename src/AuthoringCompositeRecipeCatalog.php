<?php
/**
 * Ordered catalog of composite reference-site recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Validates and exposes the shared composite recipe surface.
 */
final class AuthoringCompositeRecipeCatalog {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';
	private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D';

	/**
	 * @var array<int, AuthoringCompositeRecipeInterface>
	 */
	private readonly array $recipes;

	/**
	 * @var array<string, AuthoringCompositeRecipeInterface>
	 */
	private readonly array $recipes_by_id;

	/**
	 * @param array<int, AuthoringCompositeRecipeInterface> $recipes
	 */
	private function __construct( array $recipes ) {
		$by_id = array();
		foreach ( $recipes as $recipe ) {
			$id = $recipe->id();
			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Composite recipe ID "%s" must be stable.', $id ) );
			}
			if ( isset( $by_id[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Composite recipe catalog has duplicate recipe ID "%s".', $id ) );
			}
			if ( 1 !== preg_match( self::VERSION_PATTERN, $recipe->version() ) ) {
				throw new InvalidArgumentException( sprintf( 'Composite recipe "%s" must use a stable major.minor version.', $id ) );
			}
			self::validate_ids( $recipe->capability_ids(), 'capability', $id, true );
			self::validate_ids( $recipe->prerequisite_ids(), 'prerequisite', $id, false );
			self::validate_ids( $recipe->optional_product_prerequisite_ids(), 'optional product prerequisite', $id, false );
			$inputs = $recipe->inputs();
			AcyclicArrayGuard::assert_acyclic( $inputs );
			ImmutableArray::copy( $inputs, sprintf( 'Composite recipe "%s" inputs must contain only scalar, null, or nested array values.', $id ) );
			$by_id[ $id ] = $recipe;
		}

		$this->recipes       = array_values( $recipes );
		$this->recipes_by_id = $by_id;
	}

	public static function from_recipes( AuthoringCompositeRecipeInterface ...$recipes ): self {
		return new self( array_values( $recipes ) );
	}

	/**
	 * Rehydrate a projection against the supplied executable implementations.
	 *
	 * @param array<string, mixed> $projection
	 * @param AuthoringCompositeRecipeInterface ...$recipes
	 */
	public static function from_array( array $projection, AuthoringCompositeRecipeInterface ...$recipes ): self {
		AcyclicArrayGuard::assert_acyclic( $projection );
		if ( array( 'recipes' ) !== array_keys( $projection ) || ! is_array( $projection['recipes'] ) || ! array_is_list( $projection['recipes'] ) ) {
			throw new InvalidArgumentException( 'Composite recipe catalog projection must contain an ordered recipes list.' );
		}
		$catalog = self::from_recipes( ...$recipes );
		if ( $catalog->to_array() !== ImmutableArray::copy( $projection, 'Composite recipe catalog projection must contain scalar values.' ) ) {
			throw new InvalidArgumentException( 'Composite recipe catalog projection does not match executable recipes.' );
		}

		return $catalog;
	}

	public function recipe( string $id ): AuthoringCompositeRecipeInterface {
		if ( ! isset( $this->recipes_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Composite recipe catalog has no recipe ID "%s".', $id ) );
		}

		return $this->recipes_by_id[ $id ];
	}

	/**
	 * @return array<int, AuthoringCompositeRecipeInterface>
	 */
	public function all(): array {
		return $this->recipes;
	}

	/**
	 * @return array<int, AuthoringCompositeRecipeResult>
	 */
	public function execute_all(): array {
		return array_map(
			static fn ( AuthoringCompositeRecipeInterface $recipe ): AuthoringCompositeRecipeResult => $recipe->execute(),
			$this->recipes
		);
	}

	/**
	 * @return array{recipes: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array( 'recipes' => array_map( static fn ( AuthoringCompositeRecipeInterface $recipe ): array => $recipe->to_array(), $this->recipes ) );
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	private static function validate_ids( array $ids, string $label, string $recipe_id, bool $required ): void {
		if ( $required && array() === $ids ) {
			throw new InvalidArgumentException( sprintf( 'Composite recipe "%s" must declare at least one %s ID.', $recipe_id, $label ) );
		}
		if ( ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( sprintf( 'Composite recipe "%s" %s IDs must be a list.', $recipe_id, $label ) );
		}
		$seen = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Composite recipe "%s" %s IDs must use stable IDs.', $recipe_id, $label ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Composite recipe "%s" has duplicate %s ID "%s".', $recipe_id, $label, $id ) );
			}
			$seen[ $id ] = true;
		}
	}
}
