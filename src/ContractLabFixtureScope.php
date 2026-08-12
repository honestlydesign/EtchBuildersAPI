<?php
/**
 * Verified, locked scope for Contract Lab fixture mutation.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Binds fixture operations to the marker, doctor result, and lock that
 * authorized the current maintainer run.
 */
final class ContractLabFixtureScope {

	private function __construct(
		private readonly ContractLabBindingResolution $resolution,
		private readonly ContractLabDoctorResult $doctor,
		private readonly ContractLabLock $lock
	) {
	}

	public static function new(
		ContractLabBindingResolution $resolution,
		ContractLabDoctorResult $doctor,
		ContractLabLock $lock
	): self {
		if ( 'verified' !== $resolution->status() ) {
			throw new InvalidArgumentException( 'Contract Lab fixture scope requires a verified binding resolution.' );
		}
		if ( 'ready' !== $doctor->status() ) {
			throw new InvalidArgumentException( 'Contract Lab fixture scope requires a ready doctor result.' );
		}
		$has_fixture_target = false;
		foreach ( $resolution->mutable_targets() as $target ) {
			if ( ! is_array( $target ) || array( 'identity', 'kind' ) !== self::sorted_keys( $target ) || ! is_string( $target['kind'] ) || ! is_string( $target['identity'] ) ) {
				throw new InvalidArgumentException( 'Contract Lab fixture scope has an invalid mutable target.' );
			}
			if ( 'fixture-namespace' === $target['kind'] && ContractLabBinding::FIXTURE_NAMESPACE === $target['identity'] ) {
				$has_fixture_target = true;
				break;
			}
		}
		if ( ! $has_fixture_target ) {
			throw new InvalidArgumentException( 'Contract Lab fixture scope has no verified fixture namespace target.' );
		}
		if ( ! $lock->is_active() ) {
			throw new InvalidArgumentException( 'Contract Lab fixture scope requires an active exclusive lock.' );
		}

		return new self( $resolution, $doctor, $lock );
	}

	public function marker_id(): string {
		return $this->resolution->marker_id();
	}

	public function site_id(): string {
		return $this->resolution->site_id();
	}

	public function lock_path(): string {
		return $this->lock->path();
	}

	public function doctor_status(): string {
		return $this->doctor->status();
	}

	public function assert_active(): void {
		if ( ! $this->lock->is_active() ) {
			throw new ContractLabFixtureException( 'lock', 'Contract Lab fixture operation requires the active exclusive lock.' );
		}
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return array<int, string>
	 */
	private static function sorted_keys( array $record ): array {
		$keys = array_keys( $record );
		sort( $keys );

		return $keys;
	}
}
