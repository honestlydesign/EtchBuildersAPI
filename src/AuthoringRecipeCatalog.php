<?php
/**
 * Ordered catalog of executable Authoring Capability recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Provides one closed, deterministic recipe surface for documentation,
 * semantic queries, and maintainer-only agent evaluation.
 */
final class AuthoringRecipeCatalog {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';
	private const VERSION_PATTERN = '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D';

	/**
	 * @var array<int, AuthoringCapabilityRecipeInterface>
	 */
	private readonly array $recipes;

	/**
	 * @var array<string, AuthoringCapabilityRecipeInterface>
	 */
	private readonly array $recipes_by_id;

	/**
	 * @param array<int, AuthoringCapabilityRecipeInterface> $recipes
	 */
	private function __construct( array $recipes ) {
		$by_id = array();
		foreach ( $recipes as $recipe ) {
			$id = $recipe->id();
			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe ID "%s" must be stable.', $id ) );
			}
			if ( 1 !== preg_match( self::VERSION_PATTERN, $recipe->version() ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" version must be numeric semver.', $id ) );
			}
			if ( isset( $by_id[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe catalog has duplicate recipe ID "%s".', $id ) );
			}

			self::validate_ids( $recipe->capability_ids(), 'capability', $id, true );
			self::validate_ids( $recipe->prerequisite_ids(), 'prerequisite', $id, false );
			$inputs = $recipe->inputs();
			AcyclicArrayGuard::assert_acyclic( $inputs );
			ImmutableArray::copy( $inputs, sprintf( 'Authoring recipe "%s" inputs must contain only scalar, null, or nested array values.', $id ) );
			if ( ! $recipe->expected_outcomes() instanceof AuthoringRecipeExpectation ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" must declare typed expected outcomes.', $id ) );
			}

			$by_id[ $id ] = $recipe;
		}

		$this->recipes       = array_values( $recipes );
		$this->recipes_by_id = $by_id;
	}

	/**
	 * Build a catalog from ordered executable recipe implementations.
	 */
	public static function from_recipes( AuthoringCapabilityRecipeInterface ...$recipes ): self {
		return new self( array_values( $recipes ) );
	}

	/**
	 * Create an empty recipe catalog.
	 */
	public static function empty(): self {
		return new self( array() );
	}

	public function has( string $id ): bool {
		return isset( $this->recipes_by_id[ $id ] );
	}

	/**
	 * Return one exact recipe implementation.
	 */
	public function recipe( string $id ): AuthoringCapabilityRecipeInterface {
		if ( ! isset( $this->recipes_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring recipe catalog has no recipe ID "%s".', $id ) );
		}

		return $this->recipes_by_id[ $id ];
	}

	/**
	 * @return array<int, AuthoringCapabilityRecipeInterface>
	 */
	public function all(): array {
		return $this->recipes;
	}

	/**
	 * Execute one recipe.
	 */
	public function execute( string $id ): AuthoringRecipeResult {
		return $this->recipe( $id )->execute();
	}

	/**
	 * Execute every recipe in deterministic catalog order.
	 *
	 * @return array<int, AuthoringRecipeResult>
	 */
	public function execute_all(): array {
		return array_map(
			static fn ( AuthoringCapabilityRecipeInterface $recipe ): AuthoringRecipeResult => $recipe->execute(),
			$this->recipes
		);
	}

	/**
	 * Return the deterministic metadata projection.
	 *
	 * @return array{recipes: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'recipes' => array_map(
				static fn ( AuthoringCapabilityRecipeInterface $recipe ): array => $recipe->to_array(),
				$this->recipes
			),
		);
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	private static function validate_ids( array $ids, string $label, string $recipe_id, bool $required ): void {
		if ( $required && array() === $ids ) {
			throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" must declare at least one %s ID.', $recipe_id, $label ) );
		}
		if ( ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" %s IDs must be a list.', $recipe_id, $label ) );
		}

		$seen = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" %s IDs must use stable IDs.', $recipe_id, $label ) );
			}
			if ( isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring recipe "%s" has duplicate %s ID "%s".', $recipe_id, $label, $id ) );
			}
			$seen[ $id ] = true;
		}
	}
}
