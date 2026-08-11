<?php
/**
 * Property Contract Matrix source validation tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractStatus;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyInstanceValueKind;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\NumberProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\UrlProperty;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropGroup;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Proves matrix claims against the package's real typed source surface.
 */
final class PropertyContractMatrixSourceValidationTest extends TestCase {

	public function test_every_concrete_source_builder_is_present_exactly_once_in_the_matrix(): void {
		$source_builders = $this->discover_source_builders();
		$matrix_builders = array_map(
			static fn ( $contract ): string => $contract->definition_builder(),
			PropertyContractMatrix::all()
		);

		sort( $source_builders, SORT_STRING );
		sort( $matrix_builders, SORT_STRING );

		self::assertSame( $source_builders, $matrix_builders );
		self::assertCount( count( array_unique( $matrix_builders ) ), $matrix_builders );
	}

	public function test_every_matrix_builder_reflects_the_supported_constructor_and_exact_serialized_type(): void {
		foreach ( PropertyContractMatrix::all() as $contract ) {
			$reflection = new ReflectionClass( $contract->definition_builder() );

			self::assertTrue( $reflection->isFinal(), $reflection->getName() . ' must remain final.' );
			self::assertTrue(
				$reflection->implementsInterface( ComponentPropertyInterface::class ),
				$reflection->getName() . ' must implement ComponentPropertyInterface.'
			);

			self::assertTrue( $reflection->hasMethod( 'new' ), $reflection->getName() . ' must expose new(string).' );
			$new_method = $reflection->getMethod( 'new' );
			self::assertTrue( $new_method->isPublic() );
			self::assertTrue( $new_method->isStatic() );
			self::assertCount( 1, $new_method->getParameters() );
			self::assertSame( 1, $new_method->getNumberOfRequiredParameters() );

			$name_type = $new_method->getParameters()[0]->getType();
			self::assertInstanceOf( ReflectionNamedType::class, $name_type );
			self::assertSame( 'string', $name_type->getName() );
			self::assertFalse( $name_type->allowsNull() );

			$return_type = $new_method->getReturnType();
			self::assertInstanceOf( ReflectionNamedType::class, $return_type );
			self::assertContains(
				$return_type->getName(),
				array( 'self', 'static', $reflection->getName() ),
				$reflection->getName() . ' factory must return its own builder type.'
			);

			$definition = $new_method->invoke( null, 'Contract Probe' );
			self::assertInstanceOf( ComponentPropertyInterface::class, $definition );
			self::assertTrue( $reflection->isInstance( $definition ) );
			self::assertSame( $contract, PropertyContractMatrix::contract_for_definition( $definition ) );
			self::assertSame( $contract->to_array()['type'], $definition->to_array()['type'] );
			self::assertSame( PropertyContractStatus::SUPPORTED, $contract->status() );
		}
	}

	public function test_raw_url_and_number_instance_kinds_and_setters_do_not_exist(): void {
		$block_methods = $this->public_method_names( ComponentBlock::class );
		$group_methods = $this->public_method_names( ComponentPropGroup::class );

		self::assertNull( PropertyInstanceValueKind::tryFrom( 'url' ) );
		self::assertNull( PropertyInstanceValueKind::tryFrom( 'number' ) );
		self::assertNotContains( 'prop_url', $block_methods );
		self::assertNotContains( 'prop_number', $block_methods );
		self::assertNotContains( 'url', $group_methods );
		self::assertNotContains( 'number', $group_methods );
	}

	public function test_url_and_number_keep_their_real_definition_and_string_instance_contracts(): void {
		$url = PropertyContractMatrix::contract_for_type( 'string', 'url' );
		self::assertSame( UrlProperty::class, $url->definition_builder() );
		self::assertSame( PropertyInstanceValueKind::URL_STRING, $url->instance_value_kinds()[0] );
		self::assertSame(
			array( 'primitive' => 'string', 'specialized' => 'url' ),
			UrlProperty::new( 'URL' )->to_array()['type']
		);

		$number = PropertyContractMatrix::contract_for_type( 'number' );
		self::assertSame( NumberProperty::class, $number->definition_builder() );
		self::assertSame( PropertyInstanceValueKind::NUMERIC_STRING, $number->instance_value_kinds()[0] );
		self::assertSame(
			array( 'primitive' => 'number' ),
			NumberProperty::new( 'Number' )->to_array()['type']
		);
	}

	public function test_number_source_documentation_matches_the_audited_contract(): void {
		$documentation = ( new ReflectionClass( NumberProperty::class ) )->getDocComment();

		self::assertIsString( $documentation );
		self::assertStringNotContainsString( 'NOT SUPPORTED', $documentation );
		self::assertStringContainsString( 'numeric-string', $documentation );
	}

	/**
	 * Discover concrete source builders so adding a file requires a matrix decision.
	 *
	 * @return array<int, class-string<ComponentPropertyInterface>>
	 */
	private function discover_source_builders(): array {
		$types_root = dirname( __DIR__, 2 ) . '/src/ComponentProperties/Types';
		$builders   = array();
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $types_root, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path      = $file->getPathname();
			$relative  = substr( $path, strlen( $types_root ) + 1, -4 );
			$class     = 'HonestlyDesign\\EtchBuilders\\ComponentProperties\\Types\\'
				. str_replace( DIRECTORY_SEPARATOR, '\\', $relative );
			$reflection  = class_exists( $class ) ? new ReflectionClass( $class ) : null;
			$new_method  = null !== $reflection && $reflection->hasMethod( 'new' )
				? $reflection->getMethod( 'new' )
				: null;

			if (
				null === $reflection
				|| ! $reflection->implementsInterface( ComponentPropertyInterface::class )
				|| $reflection->isAbstract()
				|| null === $new_method
				|| ! $new_method->isPublic()
				|| ! $new_method->isStatic()
			) {
				continue;
			}

			/** @var class-string<ComponentPropertyInterface> $class */
			$builders[] = $class;
		}

		sort( $builders, SORT_STRING );

		return $builders;
	}

	/**
	 * @param class-string $class Class to inspect.
	 * @return array<int, string>
	 */
	private function public_method_names( string $class ): array {
		return array_map(
			static fn ( ReflectionMethod $method ): string => $method->getName(),
			( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);
	}
}
