<?php
/**
 * Machine-checkable runtime constraints for the Contract Lab.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Describes the environment a maintainer-only Contract Lab may target.
 */
final class ContractLabEnvironmentConstraints {

	/**
	 * @param string $wordpress_constraint Composer-like WordPress version constraint.
	 * @param string $php_constraint       Composer-like PHP version constraint.
	 * @param string $localwp_constraint   LocalWP version constraint.
	 */
	private function __construct(
		private readonly string $wordpress_constraint,
		private readonly string $php_constraint,
		private readonly string $localwp_constraint,
		private readonly bool $requires_local_environment,
		private readonly bool $single_site,
		private readonly string $expected_wordpress_root
	) {
	}

	/**
	 * Create validated environment constraints.
	 */
	public static function new(
		string $wordpress_constraint,
		string $php_constraint,
		string $localwp_constraint,
		bool $requires_local_environment = true,
		bool $single_site = true,
		string $expected_wordpress_root = 'wp'
	): self {
		if ( ! $requires_local_environment || ! $single_site ) {
			throw new InvalidArgumentException( 'Contract Lab environment must require LocalWP and a single site.' );
		}
		ContractLabManifestSafety::assert_version_constraint( $wordpress_constraint, 'WordPress version constraint' );
		ContractLabManifestSafety::assert_version_constraint( $php_constraint, 'PHP version constraint' );
		ContractLabManifestSafety::assert_version_constraint( $localwp_constraint, 'LocalWP version constraint' );
		ContractLabManifestSafety::assert_stable_token( $expected_wordpress_root, 'WordPress root' );

		return new self(
			$wordpress_constraint,
			$php_constraint,
			$localwp_constraint,
			$requires_local_environment,
			$single_site,
			$expected_wordpress_root
		);
	}

	/**
	 * Rehydrate one canonical environment constraint record.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys(
			$record,
			array(
				'wordpress_constraint',
				'php_constraint',
				'localwp_constraint',
				'requires_local_environment',
				'single_site',
				'expected_wordpress_root',
			),
			'Contract Lab environment constraints'
		);

		if ( ! is_string( $record['wordpress_constraint'] ) || ! is_string( $record['php_constraint'] ) || ! is_string( $record['localwp_constraint'] ) || ! is_bool( $record['requires_local_environment'] ) || ! is_bool( $record['single_site'] ) || ! is_string( $record['expected_wordpress_root'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab environment constraints have invalid field shapes.' );
		}

		$constraints = self::new(
			$record['wordpress_constraint'],
			$record['php_constraint'],
			$record['localwp_constraint'],
			$record['requires_local_environment'],
			$record['single_site'],
			$record['expected_wordpress_root']
		);
		if ( $constraints->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab environment constraints must be canonical.' );
		}

		return $constraints;
	}

	public function wordpress_constraint(): string {
		return $this->wordpress_constraint;
	}

	public function php_constraint(): string {
		return $this->php_constraint;
	}

	public function localwp_constraint(): string {
		return $this->localwp_constraint;
	}

	public function requires_local_environment(): bool {
		return $this->requires_local_environment;
	}

	public function single_site(): bool {
		return $this->single_site;
	}

	public function expected_wordpress_root(): string {
		return $this->expected_wordpress_root;
	}

	/**
	 * @return array{wordpress_constraint: string, php_constraint: string, localwp_constraint: string, requires_local_environment: bool, single_site: bool, expected_wordpress_root: string}
	 */
	public function to_array(): array {
		return array(
			'wordpress_constraint'      => $this->wordpress_constraint,
			'php_constraint'            => $this->php_constraint,
			'localwp_constraint'        => $this->localwp_constraint,
			'requires_local_environment' => $this->requires_local_environment,
			'single_site'               => $this->single_site,
			'expected_wordpress_root'   => $this->expected_wordpress_root,
		);
	}
}
