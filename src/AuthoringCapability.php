<?php
/**
 * One curated intent-level Authoring Capability declaration.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Describes an agent task without enumerating arbitrary public PHP methods.
 */
final class AuthoringCapability {

	private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D';

	/**
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	private function __construct(
		private readonly string $id,
		private readonly AuthoringCapabilityStatus $status,
		private readonly array $prerequisite_ids,
		private readonly array $recipe_ids,
		private readonly array $diagnostic_ids,
		private readonly array $evidence_ids,
		private readonly string $status_reason
	) {
	}

	/**
	 * Create one capability declaration.
	 *
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function new(
		string $id,
		AuthoringCapabilityStatus $status,
		array $prerequisite_ids = array(),
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array(),
		string $status_reason = ''
	): self {
		$id            = trim( $id );
		$status_reason = trim( $status_reason );
		if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring capability ID "%s" must be stable.', $id ) );
		}

		if ( ! $status->is_supported() && '' === $status_reason ) {
			throw new InvalidArgumentException( sprintf( 'Authoring capability "%s" requires a status reason.', $id ) );
		}

		if ( $status->is_admitted() && ( array() === $recipe_ids || array() === $diagnostic_ids || array() === $evidence_ids ) ) {
			throw new InvalidArgumentException( 'Supported authoring capability requires at least one recipe, diagnostic, and evidence ID.' );
		}

		return new self(
			$id,
			$status,
			self::validate_ids( $prerequisite_ids, 'prerequisite', self::ID_PATTERN ),
			self::validate_ids( $recipe_ids, 'recipe', self::ID_PATTERN ),
			self::validate_ids( $diagnostic_ids, 'diagnostic', '/^[A-Z][A-Z0-9_-]*$/D' ),
			self::validate_ids( $evidence_ids, 'evidence', self::ID_PATTERN ),
			$status_reason
		);
	}

	/**
	 * Declare an admitted Supported capability.
	 *
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function supported(
		string $id,
		array $prerequisite_ids = array(),
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array()
	): self {
		return self::new( $id, AuthoringCapabilityStatus::SUPPORTED, $prerequisite_ids, $recipe_ids, $diagnostic_ids, $evidence_ids );
	}

	/**
	 * Declare a capability admitted only through an explicit checked escape.
	 *
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function checked_escape(
		string $id,
		string $status_reason,
		array $prerequisite_ids = array(),
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array()
	): self {
		return self::new( $id, AuthoringCapabilityStatus::CHECKED_ESCAPE, $prerequisite_ids, $recipe_ids, $diagnostic_ids, $evidence_ids, $status_reason );
	}

	/**
	 * Declare a required but not yet admitted Pending capability.
	 *
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function pending(
		string $id,
		string $status_reason,
		array $prerequisite_ids = array(),
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array()
	): self {
		return self::new( $id, AuthoringCapabilityStatus::PENDING, $prerequisite_ids, $recipe_ids, $diagnostic_ids, $evidence_ids, $status_reason );
	}

	/**
	 * Declare an explicitly unavailable Unsupported capability.
	 *
	 * @param array<int, string> $prerequisite_ids
	 * @param array<int, string> $recipe_ids
	 * @param array<int, string> $diagnostic_ids
	 * @param array<int, string> $evidence_ids
	 */
	public static function unsupported(
		string $id,
		string $status_reason,
		array $prerequisite_ids = array(),
		array $recipe_ids = array(),
		array $diagnostic_ids = array(),
		array $evidence_ids = array()
	): self {
		return self::new( $id, AuthoringCapabilityStatus::UNSUPPORTED, $prerequisite_ids, $recipe_ids, $diagnostic_ids, $evidence_ids, $status_reason );
	}

	/**
	 * Rehydrate one canonical declaration record.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'diagnostic_ids', 'evidence_ids', 'id', 'prerequisite_ids', 'recipe_ids', 'status', 'status_reason' ) !== $keys ) {
			throw new InvalidArgumentException( 'Accepted authoring capability must contain exactly ID, status, references, and status_reason.' );
		}

		$id            = $record['id'];
		$status_value  = $record['status'];
		$prerequisites = $record['prerequisite_ids'];
		$recipes       = $record['recipe_ids'];
		$diagnostics   = $record['diagnostic_ids'];
		$evidence      = $record['evidence_ids'];
		$reason        = $record['status_reason'];
		$status        = is_string( $status_value ) ? AuthoringCapabilityStatus::tryFrom( $status_value ) : null;
		if ( ! is_string( $id ) || null === $status || ! is_array( $prerequisites ) || ! is_array( $recipes ) || ! is_array( $diagnostics ) || ! is_array( $evidence ) || ! is_string( $reason ) ) {
			throw new InvalidArgumentException( 'Accepted authoring capability fields have invalid shapes.' );
		}

		/** @var array<int, string> $prerequisites */
		/** @var array<int, string> $recipes */
		/** @var array<int, string> $diagnostics */
		/** @var array<int, string> $evidence */
		return self::new( $id, $status, $prerequisites, $recipes, $diagnostics, $evidence, $reason );
	}

	public function id(): string {
		return $this->id;
	}

	public function status(): AuthoringCapabilityStatus {
		return $this->status;
	}

	/**
	 * @return array<int, string>
	 */
	public function prerequisite_ids(): array {
		return $this->prerequisite_ids;
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
	 * Alias emphasizing that the links are required evidence for admission.
	 *
	 * @return array<int, string>
	 */
	public function required_evidence_ids(): array {
		return $this->evidence_ids;
	}

	public function status_reason(): string {
		return $this->status_reason;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'status'           => $this->status->value,
			'prerequisite_ids' => $this->prerequisite_ids,
			'recipe_ids'       => $this->recipe_ids,
			'diagnostic_ids'   => $this->diagnostic_ids,
			'evidence_ids'     => $this->evidence_ids,
			'status_reason'    => $this->status_reason,
		);
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
