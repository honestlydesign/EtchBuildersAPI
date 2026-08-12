<?php
/**
 * Generates and verifies the Authoring Contract Catalog from source.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\Json;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

/**
 * Source/reflection boundary for the versioned authoring contract.
 */
final class AuthoringContractCatalogGenerator {

	public const SCHEMA_VERSION = '1';

	private function __construct() {
	}

	/**
	 * Generate a catalog from curated capability declarations and source symbols.
	 */
	public static function generate(
		AuthoringCapabilityCatalog $capabilities,
		AuthoringCapabilitySourceCatalog $sources,
		string $package_version
	): AuthoringContractCatalog {
		$package_version = trim( $package_version );
		if ( '' === $package_version || 1 === preg_match( '/\s/', $package_version ) ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog package version must be a non-empty token.' );
		}

		foreach ( $sources->all() as $source ) {
			if ( ! $capabilities->has( $source->capability_id() ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Authoring source declaration references unknown capability "%s".', $source->capability_id() )
				);
			}
		}

		/** @var array<string, array<int, AuthoringInterfaceFact>> $facts_by_capability */
		$facts_by_capability = array();
		/** @var array<string, true> $contract_versions */
		$contract_versions = array();
		/** @var array<string, array<int, string>> $symbols_by_file */
		$symbols_by_file = array();

		foreach ( $capabilities->all() as $capability ) {
			if ( ! $sources->has( $capability->id() ) ) {
				if ( $capability->status()->is_admitted() ) {
					throw new InvalidArgumentException(
						sprintf( 'Admitted authoring capability "%s" requires source declarations.', $capability->id() )
					);
				}

				$facts_by_capability[ $capability->id() ] = array();
				continue;
			}

			$facts = array();
			foreach ( $sources->for_capability( $capability->id() )->symbols() as $symbol ) {
				$method = self::reflect_method( $symbol );
				$fact   = AuthoringInterfaceFact::from_reflection( $method, $symbol );
				$facts[] = $fact;
				$contract_versions[ $fact->contract_version() ] = true;

				$source_file = $fact->source_file();
				if ( null === $source_file ) {
					throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" has no source file.', $symbol->identity() ) );
				}

				$symbols_by_file[ $source_file ][] = $symbol->identity();
			}

			$facts_by_capability[ $capability->id() ] = $facts;
		}

		if ( array() === $contract_versions ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog cannot derive a contract version without source facts.' );
		}

		if ( count( $contract_versions ) > 1 ) {
			throw new InvalidArgumentException( 'Authoring source symbols must use one contract version per generated catalog.' );
		}

		$contract_version = (string) array_key_first( $contract_versions );
		$source_digest    = self::source_digest( $symbols_by_file );

		return AuthoringContractCatalog::from_generated(
			self::SCHEMA_VERSION,
			$contract_version,
			$package_version,
			$source_digest,
			$capabilities->all(),
			$facts_by_capability
		);
	}

	/**
	 * Verify a checked-in/generated projection against the current source boundary.
	 *
	 * @param array<string, mixed> $projection
	 */
	public static function verify(
		array $projection,
		AuthoringCapabilityCatalog $capabilities,
		AuthoringCapabilitySourceCatalog $sources,
		string $package_version
	): AuthoringContractCatalog {
		$generated = self::generate( $capabilities, $sources, $package_version );
		AuthoringContractCatalog::from_array( $projection );
		if ( $generated->to_array() !== $projection ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog projection does not match current source.' );
		}

		return $generated;
	}

	/**
	 * @return \ReflectionMethod
	 */
	private static function reflect_method( AuthoringCapabilitySourceSymbol $symbol ): \ReflectionMethod {
		try {
			/** @var class-string $class_name */
			$class_name = $symbol->class_name();
			$class      = new ReflectionClass( $class_name );
		} catch ( ReflectionException $exception ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source class "%s" does not exist.', $symbol->class_name() ), 0, $exception );
		}

		if ( ! $class->hasMethod( $symbol->method_name() ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" does not exist.', $symbol->identity() ) );
		}

		try {
			$method = $class->getMethod( $symbol->method_name() );
		} catch ( ReflectionException $exception ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" does not exist.', $symbol->identity() ), 0, $exception );
		}

		if ( $method->getName() !== $symbol->method_name() ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" has a non-canonical method name.', $symbol->identity() ) );
		}

		return $method;
	}

	/**
	 * @param array<string, array<int, string>> $symbols_by_file
	 */
	private static function source_digest( array $symbols_by_file ): string {
		$materials = array();
		foreach ( $symbols_by_file as $source_file => $symbols ) {
			$source = file_get_contents( $source_file );
			if ( false === $source ) {
				throw new InvalidArgumentException( sprintf( 'Authoring source file "%s" is not readable.', $source_file ) );
			}

			sort( $symbols );
			$materials[] = array(
				'symbols' => array_values( $symbols ),
				'source'  => $source,
			);
		}

		usort(
			$materials,
			static fn ( array $left, array $right ): int => strcmp( (string) $left['symbols'][0], (string) $right['symbols'][0] )
		);

		return hash( 'sha256', Json::encode( $materials ) );
	}
}
