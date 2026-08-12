<?php
/**
 * Ordered catalog of executable negative Authoring Capability recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Keeps corrective diagnostics closed and deterministic for queries and
 * evaluation instead of accepting arbitrary failed examples.
 */
final class AuthoringNegativeRecipeCatalog {

	private const ID_PATTERN      = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';
	private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D';

	/**
	 * @var array<int, AuthoringNegativeRecipeInterface>
	 */
	private readonly array $recipes;

	/**
	 * @var array<string, AuthoringNegativeRecipeInterface>
	 */
	private readonly array $recipes_by_id;

	/**
	 * @param array<int, AuthoringNegativeRecipeInterface> $recipes
	 */
	private function __construct( array $recipes ) {
		$by_id = array();
		foreach ( $recipes as $recipe ) {
			$id = $recipe->id();
			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Negative authoring recipe ID "%s" must be stable.', $id ) );
			}
			if ( 1 !== preg_match( self::VERSION_PATTERN, $recipe->version() ) ) {
				throw new InvalidArgumentException( sprintf( 'Negative authoring recipe "%s" version must be numeric semver.', $id ) );
			}
			if ( isset( $by_id[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Negative authoring recipe catalog has duplicate recipe ID "%s".', $id ) );
			}
			self::validate_ids( $recipe->capability_ids(), 'capability', $id, true );
			self::validate_ids( $recipe->prerequisite_ids(), 'prerequisite', $id, false );
			$inputs = $recipe->inputs();
			AcyclicArrayGuard::assert_acyclic( $inputs );
			ImmutableArray::copy( $inputs, sprintf( 'Negative authoring recipe "%s" inputs must contain only scalar, null, or nested array values.', $id ) );
			$by_id[ $id ] = $recipe;
		}

		$this->recipes       = array_values( $recipes );
		$this->recipes_by_id = $by_id;
	}

	public static function from_recipes( AuthoringNegativeRecipeInterface ...$recipes ): self {
		return new self( array_values( $recipes ) );
	}

	public static function empty(): self {
		return new self( array() );
	}

	public function recipe( string $id ): AuthoringNegativeRecipeInterface {
		if ( ! isset( $this->recipes_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Negative authoring recipe catalog has no recipe ID "%s".', $id ) );
		}

		return $this->recipes_by_id[ $id ];
	}

	/**
	 * @return array<int, AuthoringNegativeRecipeInterface>
	 */
	public function all(): array {
		return $this->recipes;
	}

	public function execute( string $id ): AuthoringNegativeRecipeResult {
		return $this->recipe( $id )->execute();
	}

	/**
	 * @return array<int, AuthoringNegativeRecipeResult>
	 */
	public function execute_all(): array {
		return array_map(
			static fn ( AuthoringNegativeRecipeInterface $recipe ): AuthoringNegativeRecipeResult => $recipe->execute(),
			$this->recipes
		);
	}

	/**
	 * @return array{recipes: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'recipes' => array_map(
				static fn ( AuthoringNegativeRecipeInterface $recipe ): array => $recipe->to_array(),
				$this->recipes
			),
		);
	}

	/**
	 * @param array<int, mixed> $ids
		*/
	private static function validate_ids( array $ids, string $label, string $recipe_id, bool $required ): void {
		if ( $required && array() === $ids ) {
			throw new InvalidArgumentException( sprintf( 'Negative authoring recipe "%s" must declare at least one %s ID.', $recipe_id, $label ) );
		}
		if ( ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( sprintf( 'Negative authoring recipe "%s" %s IDs must be a list.', $recipe_id, $label ) );
		}

		$seen = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Negative authoring recipe "%s" %s IDs must use stable IDs.', $recipe_id, $label ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Negative authoring recipe "%s" has duplicate %s ID "%s".', $recipe_id, $label, $id ) );
			}
			$seen[ $id ] = true;
		}
	}
}
