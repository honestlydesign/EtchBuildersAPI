<?php
/**
 * Known cross-references for curated Authoring Capabilities.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Provides the closed ID sets against which capability references are checked.
 *
 * Recipe and evidence authorities are introduced by later Map E tickets; this
 * index keeps declarations fail-closed until those authorities are available.
 */
final class AuthoringCapabilityReferenceIndex {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	private const DIAGNOSTIC_PATTERN = '/^[A-Z][A-Z0-9_-]*$/D';

	/**
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	private function __construct(
		private readonly array $recipe_ids,
		private readonly array $diagnostic_ids,
		private readonly array $evidence_ids
	) {
	}

	/**
	 * Create an explicit closed reference index.
	 *
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function new(
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array()
	): self {
		return new self(
			self::validate_ids( $recipe_ids, 'recipe', self::ID_PATTERN ),
			self::validate_ids( $diagnostic_ids, 'diagnostic', self::DIAGNOSTIC_PATTERN ),
			self::validate_ids( $evidence_ids, 'evidence', self::ID_PATTERN )
		);
	}

	/**
	 * Create an index with no known external references.
	 */
	public static function empty(): self {
		return new self( array(), array(), array() );
	}

	public function has_recipe( string $id ): bool {
		return in_array( $id, $this->recipe_ids, true );
	}

	public function has_diagnostic( string $id ): bool {
		return in_array( $id, $this->diagnostic_ids, true );
	}

	public function has_evidence( string $id ): bool {
		return in_array( $id, $this->evidence_ids, true );
	}

	/**
	 * @return array<int, string>
	 */
	public function recipe_ids(): array {
		return $this->recipe_ids;
	}

	/**
	 * @return array<int, string>
	 */
	public function diagnostic_ids(): array {
		return $this->diagnostic_ids;
	}

	/**
	 * @return array<int, string>
	 */
	public function evidence_ids(): array {
		return $this->evidence_ids;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return array<int, string>
	 */
	private static function validate_ids( array $ids, string $label, string $pattern ): array {
		if ( ! array_is_list( $ids ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring capability %s IDs must be a list.', $label ) );
		}

		$seen      = array();
		$validated = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( $pattern, $id ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring capability %s IDs must use stable IDs.', $label ) );
			}

			if ( isset( $seen[ $id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring capability has duplicate %s ID "%s".', $label, $id ) );
			}

			$seen[ $id ] = true;
			$validated[] = $id;
		}

		return $validated;
	}
}
