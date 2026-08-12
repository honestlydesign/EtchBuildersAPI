<?php
/**
 * Deterministic WordPress settings required by the Contract Lab.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Keeps locale, timezone, permalink, theme, and cache assumptions explicit.
 */
final class ContractLabDeterministicSettings {

	private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z][A-Za-z0-9-]{1,7})?$/D';

	private const PERMALINK_PATTERN = '/^\/(?:[A-Za-z0-9._~%{}-]+\/)*[A-Za-z0-9._~%{}-]*$/D';

	private function __construct(
		private readonly string $locale,
		private readonly string $timezone,
		private readonly string $permalink_structure,
		private readonly string $theme,
		private readonly bool $cache_enabled
	) {
	}

	/**
	 * Create validated deterministic settings.
	 */
	public static function new(
		string $locale,
		string $timezone,
		string $permalink_structure,
		string $theme,
		bool $cache_enabled
	): self {
		if ( '' === $locale || trim( $locale ) !== $locale || 1 !== preg_match( self::LOCALE_PATTERN, $locale ) ) {
			throw new InvalidArgumentException( 'Contract Lab locale must be a machine-readable locale.' );
		}
		if ( '' === $timezone || trim( $timezone ) !== $timezone ) {
			throw new InvalidArgumentException( 'Contract Lab timezone must be a non-empty exact timezone.' );
		}
		try {
			new \DateTimeZone( $timezone );
		} catch ( \Throwable ) {
			throw new InvalidArgumentException( 'Contract Lab timezone must be a machine-checkable timezone.' );
		}
		if ( '' === $permalink_structure || trim( $permalink_structure ) !== $permalink_structure || 1 !== preg_match( self::PERMALINK_PATTERN, $permalink_structure ) || str_contains( $permalink_structure, '//' ) ) {
			throw new InvalidArgumentException( 'Contract Lab permalink structure must be a site-relative machine-readable path.' );
		}
		ContractLabManifestSafety::assert_stable_token( $theme, 'Contract Lab theme' );

		return new self( $locale, $timezone, $permalink_structure, $theme, $cache_enabled );
	}

	/**
	 * Rehydrate one canonical deterministic settings record.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys(
			$record,
			array( 'locale', 'timezone', 'permalink_structure', 'theme', 'cache_enabled' ),
			'Contract Lab deterministic settings'
		);
		if ( ! is_string( $record['locale'] ) || ! is_string( $record['timezone'] ) || ! is_string( $record['permalink_structure'] ) || ! is_string( $record['theme'] ) || ! is_bool( $record['cache_enabled'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab deterministic settings have invalid field shapes.' );
		}

		$settings = self::new(
			$record['locale'],
			$record['timezone'],
			$record['permalink_structure'],
			$record['theme'],
			$record['cache_enabled']
		);
		if ( $settings->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab deterministic settings must be canonical.' );
		}

		return $settings;
	}

	public function locale(): string {
		return $this->locale;
	}

	public function timezone(): string {
		return $this->timezone;
	}

	public function permalink_structure(): string {
		return $this->permalink_structure;
	}

	public function theme(): string {
		return $this->theme;
	}

	public function cache_enabled(): bool {
		return $this->cache_enabled;
	}

	/**
	 * @return array{locale: string, timezone: string, permalink_structure: string, theme: string, cache_enabled: bool}
	 */
	public function to_array(): array {
		return array(
			'locale'              => $this->locale,
			'timezone'            => $this->timezone,
			'permalink_structure' => $this->permalink_structure,
			'theme'               => $this->theme,
			'cache_enabled'       => $this->cache_enabled,
		);
	}
}
