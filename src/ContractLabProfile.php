<?php
/**
 * Explicit Contract Lab prerequisite profile.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Describes one ordered required or optional plugin profile.
 */
final class ContractLabProfile {

	/**
	 * @param array<int, string> $plugin_prerequisites
	 */
	private function __construct(
		private readonly string $id,
		private readonly bool $required,
		private readonly array $plugin_prerequisites
	) {
	}

	/**
	 * Create a profile with an explicit required/optional flag.
	 *
	 * @param array<int, string> $plugin_prerequisites
	 */
	public static function new( string $id, bool $required, array $plugin_prerequisites ): self {
		ContractLabManifestSafety::assert_stable_token( $id, 'Contract Lab profile ID' );
		if ( array() === $plugin_prerequisites || ! array_is_list( $plugin_prerequisites ) ) {
			throw new InvalidArgumentException( 'Contract Lab profile prerequisites must be a non-empty ordered list.' );
		}

		$seen = array();
		foreach ( $plugin_prerequisites as $plugin_prerequisite ) {
			if ( ! is_string( $plugin_prerequisite ) ) {
				throw new InvalidArgumentException( 'Contract Lab profile prerequisites must contain stable plugin tokens.' );
			}
			ContractLabManifestSafety::assert_stable_token( $plugin_prerequisite, 'Contract Lab plugin prerequisite' );
			if ( isset( $seen[ $plugin_prerequisite ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab profile "%s" has duplicate plugin prerequisite "%s".', $id, $plugin_prerequisite ) );
			}
			$seen[ $plugin_prerequisite ] = true;
		}

		return new self( $id, $required, array_values( $plugin_prerequisites ) );
	}

	/**
	 * Create a required profile.
	 *
	 * @param array<int, string> $plugin_prerequisites
	 */
	public static function required( string $id, array $plugin_prerequisites ): self {
		return self::new( $id, true, $plugin_prerequisites );
	}

	/**
	 * Create an optional profile.
	 *
	 * @param array<int, string> $plugin_prerequisites
	 */
	public static function optional( string $id, array $plugin_prerequisites ): self {
		return self::new( $id, false, $plugin_prerequisites );
	}

	/**
	 * Rehydrate one canonical profile record.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys( $record, array( 'id', 'required', 'plugin_prerequisites' ), 'Contract Lab profile' );
		if ( ! is_string( $record['id'] ) || ! is_bool( $record['required'] ) || ! is_array( $record['plugin_prerequisites'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab profile has invalid field shapes.' );
		}

		/** @var array<int, string> $plugin_prerequisites */
		$plugin_prerequisites = $record['plugin_prerequisites'];
		$profile              = self::new( $record['id'], $record['required'], $plugin_prerequisites );
		if ( $profile->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab profile must be canonical.' );
		}

		return $profile;
	}

	public function id(): string {
		return $this->id;
	}

	public function is_required(): bool {
		return $this->required;
	}

	/**
	 * @return array<int, string>
	 */
	public function plugin_prerequisites(): array {
		return $this->plugin_prerequisites;
	}

	/**
	 * @return array{id: string, required: bool, plugin_prerequisites: array<int, string>}
	 */
	public function to_array(): array {
		return array(
			'id'                   => $this->id,
			'required'             => $this->required,
			'plugin_prerequisites' => $this->plugin_prerequisites,
		);
	}
}
