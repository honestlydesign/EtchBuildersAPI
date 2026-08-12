<?php
/**
 * Typed block class-provenance tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassProvenance;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassToken;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\DynamicElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\DynamicImageBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\SvgBlock;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves blocks retain provenance without changing Etch wire output.
 */
final class ClassProvenanceBlockTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Style::reset();
		parent::tearDown();
	}

	public function test_site_presentation_attaches_its_exact_class_style_and_metadata(): void {
		Style::new()
			->id( 'opaque-hero-id' )
			->selector( '.hero' )
			->css( 'display: grid' )
			->type( 'class' )
			->add();

		$block = ElementBlock::new()
			->tag( 'section' )
			->class_token( ClassToken::site_presentation( ClassStyleReference::registered( 'opaque-hero-id' ) ) )
			->to_block();

		self::assertSame(
			'<!-- wp:etch/element {"tag":"section","attributes":{"class":"hero"},"styles":["opaque-hero-id"]} --><!-- /wp:etch/element -->',
			$block->to_string()
		);
		self::assertSame( 'hero', $block->class_tokens()[0]->token() );
		self::assertSame( ClassProvenance::SITE_PRESENTATION, $block->class_tokens()[0]->provenance() );
		self::assertSame( 'opaque-hero-id', $block->class_tokens()[0]->style_reference()?->id() );
	}

	public function test_non_site_tokens_never_register_or_persist_styles(): void {
		$storage = new ClassProvenanceStorage();
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$block = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::project_utility( 'u-hidden' ) )
			->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) )
			->class_token( ClassToken::runtime_state( 'rt-active', 'Etch Runtime' ) )
			->to_block();

		self::assertStringContainsString( '"class":"u-hidden grid rt-active"', $block->to_string() );
		self::assertStringNotContainsString( '"styles"', $block->to_string() );
		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( 0, $storage->set_calls );
		self::assertSame(
			array(
				ClassProvenance::PROJECT_UTILITY,
				ClassProvenance::EXTERNAL_FRAMEWORK,
				ClassProvenance::RUNTIME_STATE,
			),
			array_map(
				static fn ( ClassToken $token ): ClassProvenance => $token->provenance(),
				$block->class_tokens()
			)
		);
	}

	public function test_same_provenance_is_idempotent_and_conflicting_provenance_fails_before_mutation(): void {
		$builder = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) )
			->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) );

		$before = $builder->to_block()->to_string();

		try {
			$builder->class_token( ClassToken::runtime_state( 'grid', 'Grid Runtime' ) );
			self::fail( 'Conflicting provenance must fail.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'grid', $exception->getMessage() );
		}

		self::assertSame( $before, $builder->to_block()->to_string() );
		self::assertCount( 1, $builder->to_block()->class_tokens() );
	}

	public function test_same_provenance_with_a_different_origin_also_conflicts_atomically(): void {
		$builder = ElementBlock::new()->tag( 'div' )->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) );
		$before  = $builder->to_block()->to_string();

		$this->expectException( InvalidArgumentException::class );

		try {
			$builder->class_token( ClassToken::external_framework( 'grid', 'Another Framework' ) );
		} finally {
			self::assertSame( $before, $builder->to_block()->to_string() );
		}
	}

	public function test_provenance_survives_detached_copy_and_never_enters_wire_markup(): void {
		$block = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) )
			->to_block();
		$copy = $block->detached_copy();

		self::assertNotSame( $block, $copy );
		self::assertSame( $block->class_tokens(), $copy->class_tokens() );
		self::assertSame( $block->to_string(), $copy->to_string() );
		self::assertStringNotContainsString( 'ACSS', $copy->to_string() );
		self::assertStringNotContainsString( 'external_framework', $copy->to_string() );
	}

	public function test_component_and_pattern_retain_nested_structured_provenance_but_raw_markup_stays_unclassified(): void {
		$block = ElementBlock::new()
			->tag( 'section' )
			->child(
				ElementBlock::new()
					->tag( 'div' )
					->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) )
					->to_block()
			)
			->to_block();

		$component = Component::new( 'Grid', 'Structured component.' )->blocks( $block );
		$pattern   = Pattern::new( 'Grid', 'Structured pattern.' )->blocks( $block );

		self::assertSame( 'grid', $component->get_class_tokens()[0]->token() );
		self::assertSame( 'grid', $pattern->get_class_tokens()[0]->token() );
		self::assertSame( $component->get_blocks(), $pattern->get_blocks() );
		self::assertStringNotContainsString( 'ACSS', $component->get_blocks() );

		$component->blocks( $block->to_string() );
		self::assertSame( array(), $component->get_class_tokens() );
	}

	public function test_all_shared_class_style_builders_expose_the_typed_route(): void {
		$token = ClassToken::project_utility( 'u-hidden' );
		$blocks = array(
			DynamicElementBlock::new()->tag( 'div' )->class_token( $token )->to_block(),
			DynamicImageBlock::new()->class_token( $token )->to_block(),
			SvgBlock::new()->class_token( $token )->to_block(),
		);

		foreach ( $blocks as $block ) {
			self::assertSame( ClassProvenance::PROJECT_UTILITY, $block->class_tokens()[0]->provenance() );
			self::assertStringContainsString( '"class":"u-hidden"', $block->to_string() );
		}
	}

	public function test_existing_class_style_route_is_site_presentation_compatible(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.card' )
			->css( 'display: block' )
			->type( 'class' )
			->add();
		$block = ElementBlock::new()
			->tag( 'div' )
			->class_style( ClassStyleReference::registered( 'opaque-card-id' ) )
			->to_block();

		self::assertSame( ClassProvenance::SITE_PRESENTATION, $block->class_tokens()[0]->provenance() );
		self::assertStringContainsString( '"styles":["opaque-card-id"]', $block->to_string() );
	}

	public function test_later_legacy_mutations_never_submit_typed_non_site_tokens_to_the_registry(): void {
		$markup = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::project_utility( 'u-hidden' ) )
			->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) )
			->class( 'legacy' )
			->attribute( 'class', 'attribute-class' )
			->style( 'manual-style-id' )
			->to_block()
			->to_string();

		self::assertStringContainsString( '"class":"u-hidden grid legacy attribute-class"', $markup );
		self::assertStringContainsString( '"styles":["legacy","attribute-class","manual-style-id"]', $markup );
		self::assertSame( array( 'legacy', 'attribute-class' ), array_keys( Style::registered_styles() ) );
	}

	public function test_non_site_provenance_rejects_a_token_already_emitted_through_the_legacy_lane(): void {
		$builder        = ElementBlock::new()->tag( 'div' )->class( 'grid' );
		$before_markup  = $builder->to_block()->to_string();
		$before_styles  = Style::registered_styles();

		try {
			$builder->class_token( ClassToken::external_framework( 'grid', 'ACSS' ) );
			self::fail( 'A legacy-linked token must not be relabelled as a non-site class.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'grid', $exception->getMessage() );
		}

		self::assertSame( $before_markup, $builder->to_block()->to_string() );
		self::assertSame( $before_styles, Style::registered_styles() );
		self::assertSame( array(), $builder->to_block()->class_tokens() );
	}

	public function test_numeric_html_class_token_survives_metadata_without_php_array_key_coercion(): void {
		$block = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::project_utility( '0' ) )
			->to_block();

		self::assertStringContainsString( '"class":"0"', $block->to_string() );
		self::assertSame( '0', $block->class_tokens()[0]->token() );
		self::assertSame( ClassProvenance::PROJECT_UTILITY, $block->class_tokens()[0]->provenance() );
	}

	public function test_legacy_mutation_preserves_a_typed_token_containing_non_html_whitespace(): void {
		$token = "foo\x0Bbar";
		$block = ElementBlock::new()
			->tag( 'div' )
			->class_token( ClassToken::project_utility( $token ) )
			->class( 'legacy' )
			->to_block();

		self::assertSame( $token, $block->class_tokens()[0]->token() );
		self::assertStringContainsString( '"styles":["legacy"]', $block->to_string() );
		self::assertSame( array( 'legacy' ), array_keys( Style::registered_styles() ) );
	}

	public function test_attribute_replacement_prunes_removed_provenance_and_its_site_style_then_allows_reattachment(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.card' )
			->css( 'display: block' )
			->type( 'class' )
			->add();
		$reference = ClassStyleReference::registered( 'opaque-card-id' );
		$empty     = \HonestlyDesign\EtchBuilders\Types\Attributes::new();
		$builder   = ElementBlock::new()->tag( 'div' )->class_style( $reference )->attributes( $empty );

		$without_class = $builder->to_block();
		self::assertSame( array(), $without_class->class_tokens() );
		self::assertStringNotContainsString( '"class"', $without_class->to_string() );
		self::assertStringNotContainsString( '"styles"', $without_class->to_string() );

		$reattached = $builder->class_style( $reference )->to_block();
		self::assertSame( ClassProvenance::SITE_PRESENTATION, $reattached->class_tokens()[0]->provenance() );
		self::assertStringContainsString( '"class":"card"', $reattached->to_string() );
		self::assertStringContainsString( '"styles":["opaque-card-id"]', $reattached->to_string() );
	}

	public function test_block_rejects_provenance_metadata_without_its_emitted_class(): void {
		$this->expectException( InvalidArgumentException::class );

		Block::new(
			'element',
			array( 'tag' => 'div', 'attributes' => array() ),
			array( ClassToken::external_framework( 'grid', 'ACSS' ) )
		);
	}

	public function test_block_rejects_site_provenance_without_its_exact_style_id(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.card' )
			->css( 'display: block' )
			->type( 'class' )
			->add();

		$this->expectException( InvalidArgumentException::class );

		Block::new(
			'element',
			array( 'tag' => 'div', 'attributes' => array( 'class' => 'card' ) ),
			array( ClassToken::site_presentation( ClassStyleReference::registered( 'opaque-card-id' ) ) )
		);
	}

	public function test_to_block_revalidates_a_site_reference_after_builder_attachment(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.before' )
			->css( 'display: block' )
			->type( 'class' )
			->add();
		$builder = ElementBlock::new()
			->tag( 'div' )
			->class_style( ClassStyleReference::registered( 'opaque-card-id' ) );

		Style::reset();
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.after' )
			->css( 'display: none' )
			->type( 'class' )
			->add();

		$this->expectException( InvalidArgumentException::class );

		$builder->to_block();
	}
}

/**
 * Storage spy proving typed class provenance is request-local metadata.
 */
final class ClassProvenanceStorage implements StorageInterface {

	public int $set_calls = 0;

	public function get( string $key, mixed $default = null ): mixed {
		return $default;
	}

	public function set( string $key, mixed $value ): bool {
		++$this->set_calls;
		return true;
	}

	public function delete( string $key ): bool {
		return true;
	}
}
