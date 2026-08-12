<?php
/**
 * Schema-backed component expression tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentExpression;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyInstanceValueKind;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that checked expressions are constrained by exact Component Contracts.
 */
final class SchemaBackedComponentExpressionTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider catalog_provider
	 */
	public function test_checked_expressions_compile_exact_paths_through_both_catalog_providers( string $provider_kind ): void {
		$this->configure( $this->provider( $provider_kind ) );

		$attributes = $this->extract_attributes(
			ComponentBlock::for_key( 'FeatureCard' )
				->expression_prop( 'title', ComponentExpression::source( 'this.title', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'conditionalLabel', ComponentExpression::source( 'archive.title', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'styling.label', ComponentExpression::source( 'user.display_name', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'styling.advanced.note', ComponentExpression::source( 'options.brand_name', PropertyInstanceValueKind::STRING ) )
				->to_block()
				->to_string()
		);

		self::assertSame( '{this.title}', $attributes['title'] );
		self::assertSame( '{archive.title}', $attributes['conditionalLabel'] );
		self::assertSame(
			'{{"label":"{user.display_name}","advanced":{"note":"{options.brand_name}"}}}',
			$attributes['styling']
		);
		self::assertArrayNotHasKey( 'visibility', $attributes );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function catalog_provider(): array {
		return array(
			'in-memory catalog' => array( 'memory' ),
			'accepted catalog'  => array( 'accepted' ),
		);
	}

	public function test_repeater_and_nested_repeater_expressions_compile_in_schema_order(): void {
		$this->configure( $this->provider( 'memory' ) );

		$attributes = $this->extract_attributes(
			ComponentBlock::for_key( 'FeatureCard' )
				->expression_prop( 'items[1].label', ComponentExpression::source( 'this.items.1.title', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'items[0].children[0].label', ComponentExpression::source( 'this.items.0.child', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'items[0].styling.note', ComponentExpression::source( 'this.items.0.note', PropertyInstanceValueKind::STRING ) )
				->expression_prop( 'items[0].label', ComponentExpression::source( 'this.items.0.title', PropertyInstanceValueKind::STRING ) )
				->to_block()
				->to_string()
		);

		self::assertSame(
			'{[{"label":"{this.items.0.title}","styling":"{{\\"note\\":\\"{this.items.0.note}\\"}}","children":"{[{\\"label\\":\\"{this.items.0.child}\\"}]}"},{"label":"{this.items.1.title}"}]}',
			$attributes['items']
		);
	}

	/**
	 * @dataProvider structural_expression_target
	 */
	public function test_whole_structural_and_specialized_targets_require_their_exact_declared_kind(
		string $path,
		PropertyInstanceValueKind $kind
	): void {
		$this->configure( $this->provider( 'memory' ) );

		$attributes = $this->extract_attributes(
			ComponentBlock::for_key( 'FeatureCard' )
				->expression_prop( $path, ComponentExpression::source( 'this.value', $kind ) )
				->to_block()
				->to_string()
		);

		self::assertSame( '{this.value}', $attributes[ $path ] );
	}

	/**
	 * @return array<string, array{string, PropertyInstanceValueKind}>
	 */
	public function structural_expression_target(): array {
		return array(
			'primitive object' => array( 'metadata', PropertyInstanceValueKind::OBJECT ),
			'primitive array'  => array( 'tags', PropertyInstanceValueKind::ARRAY ),
			'group'            => array( 'styling', PropertyInstanceValueKind::GROUP ),
			'repeater'         => array( 'items', PropertyInstanceValueKind::REPEATER ),
			'class style IDs'  => array( 'rootClass', PropertyInstanceValueKind::CLASS_STYLE_SET ),
		);
	}

	public function test_root_source_reference_is_valid_without_claiming_runtime_availability(): void {
		$this->configure( $this->provider( 'memory' ) );

		$expression = ComponentExpression::source( 'customSource', PropertyInstanceValueKind::STRING );
		$attributes = $this->extract_attributes(
			ComponentBlock::for_key( 'FeatureCard' )
				->expression_prop( 'title', $expression )
				->to_block()
				->to_string()
		);

		self::assertSame( 'customSource', $expression->source_path() );
		self::assertSame( PropertyInstanceValueKind::STRING, $expression->expected_kind() );
		self::assertSame( '{customSource}', $attributes['title'] );
	}

	/**
	 * @dataProvider unsafe_source_expression
	 */
	public function test_checked_source_expression_rejects_unsafe_or_ambiguous_syntax( string $source_path ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'simple dot-separated source path' );

		ComponentExpression::source( $source_path, PropertyInstanceValueKind::STRING );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function unsafe_source_expression(): array {
		return array(
			'empty'               => array( '' ),
			'leading whitespace'  => array( ' this.title' ),
			'trailing whitespace' => array( 'this.title ' ),
			'pre-wrapped'         => array( '{this.title}' ),
			'embedded template'   => array( 'Hello {this.title}' ),
			'bracket access'      => array( 'this.items[0].title' ),
			'dashed segment'      => array( 'options.brand-name' ),
			'function call'       => array( 'this.title.toUpperCase()' ),
			'modifier'            => array( 'this.title.applyData()' ),
			'operator'            => array( 'this.title + other.title' ),
			'quoted literal'      => array( '"literal"' ),
			'numeric literal'     => array( '42' ),
			'boolean literal'     => array( 'true' ),
			'false literal'       => array( 'false' ),
			'null literal'        => array( 'null' ),
			'browser infinity'    => array( 'Infinity' ),
			'json literal'        => array( '{"title":"x"}' ),
			'quoted segment'      => array( "this.'title'" ),
			'escape'              => array( 'this\\title' ),
			'runtime token'       => array( 'rt-active' ),
			'empty segment'       => array( 'this..title' ),
			'leading dot'         => array( '.this.title' ),
			'trailing dot'        => array( 'this.title.' ),
		);
	}

	public function test_transparent_result_kind_is_not_a_persistable_expression_value(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'transparent-children' );

		ComponentExpression::source( 'this.value', PropertyInstanceValueKind::TRANSPARENT_CHILDREN );
	}

	/**
	 * @dataProvider invalid_expression_assignment
	 */
	public function test_unknown_inexact_nonconcrete_and_mismatched_targets_fail_closed(
		string $path,
		PropertyInstanceValueKind $kind,
		string $message
	): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentBlock::for_key( 'FeatureCard' )
			->expression_prop( $path, ComponentExpression::source( 'this.value', $kind ) );
	}

	/**
	 * @return array<string, array{string, PropertyInstanceValueKind, string}>
	 */
	public function invalid_expression_assignment(): array {
		return array(
			'unknown path' => array( 'invented', PropertyInstanceValueKind::STRING, 'no exact instance value path "invented"' ),
			'wrong case' => array( 'Title', PropertyInstanceValueKind::STRING, 'no exact instance value path "Title"' ),
			'transparent declaration' => array( 'visibility', PropertyInstanceValueKind::STRING, 'declaration-only transparent' ),
			'repeater without row' => array( 'items.label', PropertyInstanceValueKind::STRING, 'requires a concrete row index' ),
			'path ends at row' => array( 'items[0]', PropertyInstanceValueKind::STRING, 'must end at a property' ),
			'wrong scalar kind' => array( 'title', PropertyInstanceValueKind::URL_STRING, 'expects expression result kind "string"; got "url-string"' ),
			'wrong structural kind' => array( 'styling', PropertyInstanceValueKind::OBJECT, 'expects expression result kind "group"; got "object"' ),
			'wrong class kind' => array( 'rootClass', PropertyInstanceValueKind::ARRAY, 'expects expression result kind "class-style-set"; got "array"' ),
		);
	}

	public function test_expression_assignments_conflict_with_literals_and_each_other_in_both_directions(): void {
		$this->configure( $this->provider( 'memory' ) );

		$duplicate = ComponentBlock::for_key( 'FeatureCard' )
			->expression_prop( 'title', ComponentExpression::source( 'this.first', PropertyInstanceValueKind::STRING ) );

		try {
			$duplicate->expression_prop( 'title', ComponentExpression::source( 'this.second', PropertyInstanceValueKind::STRING ) );
			self::fail( 'Duplicate expression paths must not overwrite.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'already has an assignment', $exception->getMessage() );
		}

		$literal_first = ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() );

		try {
			$literal_first->expression_prop( 'items[0].label', ComponentExpression::source( 'this.title', PropertyInstanceValueKind::STRING ) );
			self::fail( 'A literal structural value must conflict with an expression child.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'conflicts with an existing assignment', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conflicts with existing child assignments' );

		ComponentBlock::for_key( 'FeatureCard' )
			->expression_prop( 'items[0].label', ComponentExpression::source( 'this.title', PropertyInstanceValueKind::STRING ) )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() );
	}

	public function test_expression_only_repeater_assignments_require_contiguous_rows(): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'contiguous rows starting at index 0' );

		ComponentBlock::for_key( 'FeatureCard' )
			->expression_prop( 'items[1].label', ComponentExpression::source( 'this.title', PropertyInstanceValueKind::STRING ) )
			->to_block();
	}

	public function test_expression_and_static_class_assignments_conflict_in_both_directions(): void {
		$this->configure( $this->provider( 'memory' ) );

		$expression_first = ComponentBlock::for_key( 'FeatureCard' )
			->expression_prop(
				'rootClass',
				ComponentExpression::source( 'this.classStyleIds', PropertyInstanceValueKind::CLASS_STYLE_SET )
			);

		try {
			$expression_first->class_prop( 'rootClass', ClassStyleSet::none() );
			self::fail( 'A static class assignment must not overwrite an expression assignment.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'already has an assignment', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'already has an assignment' );

		ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'rootClass', ClassStyleSet::none() )
			->expression_prop(
				'rootClass',
				ComponentExpression::source( 'this.classStyleIds', PropertyInstanceValueKind::CLASS_STYLE_SET )
			);
	}

	public function test_checked_lane_requires_a_keyed_component_and_raw_expression_escape_hatch_remains_available(): void {
		$this->configure( $this->provider( 'memory' ) );

		try {
			ComponentBlock::new()
				->ref( 77 )
				->expression_prop( 'title', ComponentExpression::source( 'this.title', PropertyInstanceValueKind::STRING ) );
			self::fail( 'A numeric ref must not enable checked expression authoring.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'requires for_key() or ref_by_key()', $exception->getMessage() );
		}

		$attributes = $this->extract_attributes(
			ComponentBlock::new()
				->ref( 77 )
				->prop_expression( 'invented', 'dangerous()' )
				->to_block()
				->to_string()
		);

		self::assertSame( '{dangerous()}', $attributes['invented'] );
	}

	public function test_checked_method_signature_keeps_expression_contract_visible_to_agents(): void {
		$method     = new ReflectionMethod( ComponentBlock::class, 'expression_prop' );
		$parameters = $method->getParameters();

		self::assertCount( 2, $parameters );
		self::assertSame( 'string', (string) $parameters[0]->getType() );
		self::assertSame( ComponentExpression::class, (string) $parameters[1]->getType() );
	}

	private function configure( ComponentContractCatalogProviderInterface $provider ): void {
		Environment::configure(
			new NullStorage(),
			new NullMode(),
			new NullAssetRegistry(),
			new SchemaExpressionRefResolver(),
			$provider
		);
	}

	private function provider( string $kind ): ComponentContractCatalogProviderInterface {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema(
				'FeatureCard',
				array(
					$this->property( 'Title', 'title', 'string' ),
					$this->property( 'Metadata', 'metadata', 'object' ),
					$this->property( 'Tags', 'tags', 'array' ),
					$this->property( 'Root Class', 'rootClass', 'array', 'class' ),
					$this->property(
						'Visibility',
						'visibility',
						'string',
						'condition',
						array( $this->property( 'Conditional Label', 'conditionalLabel', 'string' ) )
					),
					$this->property(
						'Styling',
						'styling',
						'object',
						'group',
						array(
							$this->property( 'Label', 'label', 'string' ),
							$this->property(
								'Advanced',
								'advanced',
								'object',
								'group',
								array( $this->property( 'Note', 'note', 'string' ) )
							),
						)
					),
					$this->property(
						'Items',
						'items',
						'array',
						'repeater',
						array(
							$this->property( 'Label', 'label', 'string' ),
							$this->property(
								'Styling',
								'styling',
								'object',
								'group',
								array( $this->property( 'Note', 'note', 'string' ) )
							),
							$this->property(
								'Children',
								'children',
								'array',
								'repeater',
								array( $this->property( 'Label', 'label', 'string' ) )
							),
						)
					),
				),
				array()
			)
		);

		return 'accepted' === $kind
			? AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )
			: InMemoryComponentContractCatalogProvider::from_catalog( $catalog );
	}

	/**
	 * @param array<int, array<string, mixed>>|null $children Child definitions.
	 * @return array<string, mixed>
	 */
	private function property(
		string $name,
		string $key,
		string $primitive,
		?string $specialized = null,
		?array $children = null
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		$property = array( 'name' => $name, 'key' => $key, 'type' => $type );
		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}

	/**
	 * @return array<string, string>
	 */
	private function extract_attributes( string $markup ): array {
		preg_match( '/<!-- wp:etch\/component (\{.*?\}) -->/s', $markup, $matches );
		self::assertNotEmpty( $matches, 'Failed to find component block attrs in: ' . $markup );

		$block_attributes = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

		return $block_attributes['attributes'];
	}
}

final class SchemaExpressionRefResolver implements ComponentRefResolverInterface {

	public function ref_by_key( string $component_key ): int {
		return 'FeatureCard' === $component_key ? 77 : 0;
	}

	public function key_by_ref( int $ref ): ?string {
		return 77 === $ref ? 'FeatureCard' : null;
	}
}
