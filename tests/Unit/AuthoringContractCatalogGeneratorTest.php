<?php
/**
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringCapability;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityReferenceIndex;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceDeclaration;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceSymbol;
use HonestlyDesign\EtchBuilders\AuthoringContractCatalog;
use HonestlyDesign\EtchBuilders\AuthoringContractCatalogGenerator;
use HonestlyDesign\EtchBuilders\SiteDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AuthoringContractCatalogGeneratorTest extends TestCase {

	public function test_generation_uses_only_declared_public_symbols_and_source_facts(): void {
		$catalog = AuthoringContractCatalogGenerator::generate(
			$this->capabilities(),
			$this->sources(),
			'1.1.8-dev'
		);

		$record       = $catalog->to_array();
		$capability   = $record['capabilities'][0];
		$interfaces   = $capability['interfaces'];
		$factory_fact = $interfaces[0];
		$legacy_fact  = $interfaces[1];

		self::assertSame( '1', $record['schema_version'] );
		self::assertSame( '1.0', $record['contract_version'] );
		self::assertSame( '1.1.8-dev', $record['package_version'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['source_digest'] );
		self::assertSame( ContractFixture::class, $factory_fact['class'] );
		self::assertSame( 'factory', $factory_fact['method'] );
		self::assertSame( 'public', $factory_fact['visibility'] );
		self::assertTrue( $factory_fact['static'] );
		self::assertFalse( $factory_fact['deprecated'] );
		self::assertSame( '1.0', $factory_fact['contract_version'] );
		self::assertSame( 'string', $factory_fact['parameters'][0]['type'] );
		self::assertSame( 'int', $factory_fact['parameters'][1]['type'] );
		self::assertTrue( $factory_fact['parameters'][1]['has_default'] );
		self::assertSame( 3, $factory_fact['parameters'][1]['default'] );
		self::assertSame( (string) ( new ReflectionMethod( ContractFixture::class, 'factory' ) )->getReturnType(), $factory_fact['return_type'] );

		self::assertSame( 'legacy', $legacy_fact['method'] );
		self::assertTrue( $legacy_fact['deprecated'] );
		self::assertSame( 'Use factory().', $legacy_fact['deprecation_reason'] );
		self::assertSame( 'string|false', $legacy_fact['return_type'] );
		self::assertTrue( $interfaces[2]['deprecated'] );
		self::assertNull( $interfaces[2]['deprecation_reason'] );
		self::assertArrayNotHasKey( 'unversioned', $this->interface_method_names( $interfaces ) );
		self::assertNotContains( 'public_only_by_enumeration', $this->interface_method_names( $interfaces ) );
	}

	public function test_generation_is_deterministic_and_canonical_projection_round_trips(): void {
		$first  = AuthoringContractCatalogGenerator::generate( $this->capabilities(), $this->sources(), '1.1.8-dev' );
		$second = AuthoringContractCatalogGenerator::generate( $this->capabilities(), $this->sources(), '1.1.8-dev' );

		self::assertSame( $first->to_array(), $second->to_array() );
		self::assertSame(
			$first->to_array(),
			AuthoringContractCatalog::from_array( $first->to_array() )->to_array()
		);
	}

	public function test_stale_or_hand_authored_projection_cannot_be_verified(): void {
		$catalog    = AuthoringContractCatalogGenerator::generate( $this->capabilities(), $this->sources(), '1.1.8-dev' );
		$projection = $catalog->to_array();
		$projection['capabilities'][0]['interfaces'][0]['parameters'][0]['type'] = 'invented\\Type';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not match current source' );
		AuthoringContractCatalogGenerator::verify( $projection, $this->capabilities(), $this->sources(), '1.1.8-dev' );
	}

	public function test_stale_digest_is_rejected_even_when_interface_facts_are_unchanged(): void {
		$catalog    = AuthoringContractCatalogGenerator::generate( $this->capabilities(), $this->sources(), '1.1.8-dev' );
		$projection = $catalog->to_array();
		$projection['source_digest'] = str_repeat( '0', 64 );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not match current source' );
		AuthoringContractCatalogGenerator::verify( $projection, $this->capabilities(), $this->sources(), '1.1.8-dev' );
	}

	public function test_source_symbol_rejects_hand_authored_signature_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exactly class and method' );

		AuthoringCapabilitySourceSymbol::from_array(
			array(
				'class'      => ContractFixture::class,
				'method'     => 'factory',
				'parameters' => array( 'string' ),
			)
		);
	}

	public function test_generator_rejects_unknown_non_public_and_unversioned_symbols(): void {
		$unknown_sources = AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.factory',
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'missing' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not exist' );
		AuthoringContractCatalogGenerator::generate( $this->capabilities(), $unknown_sources, '1.1.8-dev' );
	}

	public function test_generator_rejects_non_public_symbols_even_when_they_exist(): void {
		$sources = AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.factory',
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'hidden' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'is not public' );
		AuthoringContractCatalogGenerator::generate( $this->capabilities(), $sources, '1.1.8-dev' );
	}

	public function test_generator_rejects_source_symbols_without_a_contract_version_tag(): void {
		$sources = AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.factory',
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'unversioned' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing @authoring-contract-version' );
		AuthoringContractCatalogGenerator::generate( $this->capabilities(), $sources, '1.1.8-dev' );
	}

	public function test_admitted_capability_requires_a_source_declaration(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'requires source declarations' );
		AuthoringContractCatalogGenerator::generate(
			$this->capabilities(),
			AuthoringCapabilitySourceCatalog::empty(),
			'1.1.8-dev'
		);
	}

	public function test_real_builder_source_contract_tag_is_reflected_without_enumerating_the_public_api(): void {
		$sources = AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.factory',
				AuthoringCapabilitySourceSymbol::method( SiteDefinition::class, 'new' )
			)
		);

		$catalog = AuthoringContractCatalogGenerator::generate( $this->capabilities(), $sources, '1.1.8-dev' );
		$fact    = $catalog->interfaces_for( 'site.factory' )[0]->to_array();

		self::assertSame( SiteDefinition::class, $fact['class'] );
		self::assertSame( 'new', $fact['method'] );
		self::assertSame( '1.0', $fact['contract_version'] );
		self::assertCount( 0, $fact['parameters'] );
	}

	/**
	 * @return array<int, string>
	 */
	private function interface_method_names( array $interfaces ): array {
		return array_map( static fn ( array $interface ): string => $interface['method'], $interfaces );
	}

	private function capabilities(): AuthoringCapabilityCatalog {
		$references = AuthoringCapabilityReferenceIndex::new(
			array( 'recipe.factory' ),
			array( 'AUTHORING_FACTORY' ),
			array( 'evidence.factory' )
		);

		return AuthoringCapabilityCatalog::from_declarations(
			$references,
			AuthoringCapability::supported(
				'site.factory',
				array(),
				array( 'recipe.factory' ),
				array( 'AUTHORING_FACTORY' ),
				array( 'evidence.factory' )
			)
		);
	}

	private function sources(): AuthoringCapabilitySourceCatalog {
		return AuthoringCapabilitySourceCatalog::from_declarations(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.factory',
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'factory' ),
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'legacy' ),
				AuthoringCapabilitySourceSymbol::method( ContractFixture::class, 'legacy_without_reason' )
			)
		);
	}
}

/**
 * Source-owned fixture used to prove that facts come from reflection/docblocks.
 */
final class ContractFixture {

	/**
	 * @authoring-contract-version 1.0
	 */
	public static function factory( string $name, int $count = 3 ): ?string {
		return '' === $name ? null : str_repeat( $name, $count );
	}

	/**
	 * @authoring-contract-version 1.0
	 * @deprecated Use factory().
	 */
	public function legacy( array $items = array() ): string|false {
		return empty( $items ) ? false : (string) reset( $items );
	}

	/**
	 * Deliberately not contract-versioned.
	 */
	public function unversioned(): void {
	}

	/**
	 * @authoring-contract-version 1.0
	 * @deprecated
	 */
	public function legacy_without_reason(): void {
	}

	public function public_only_by_enumeration(): void {
	}

	protected function hidden(): void {
	}
}
