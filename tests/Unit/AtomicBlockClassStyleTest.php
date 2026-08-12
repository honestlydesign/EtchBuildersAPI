<?php
/**
 * Atomic block class styling tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\DynamicElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\DynamicImageBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\SvgBlock;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use PHPUnit\Framework\TestCase;

/**
 * Proves one Class-Owned Style operation emits the class and opaque ID together.
 */
final class AtomicBlockClassStyleTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Style::reset();
		parent::tearDown();
	}

	public function test_element_block_attaches_the_selector_class_and_exact_opaque_style_id_atomically(): void {
		Style::new()
			->id( 'opaque-hero-title-id' )
			->selector( '.hero__title' )
			->css( 'color: red' )
			->type( 'class' )
			->add();

		$markup = ElementBlock::new()
			->tag( 'h2' )
			->class_style( ClassStyleReference::registered( 'opaque-hero-title-id' ) )
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/element {"tag":"h2","attributes":{"class":"hero__title"},"styles":["opaque-hero-title-id"]} --><!-- /wp:etch/element -->',
			$markup
		);
		self::assertSame( array( 'opaque-hero-title-id' ), array_map( 'strval', array_keys( Style::registered_styles() ) ) );
	}

	public function test_every_class_style_block_builder_uses_the_same_atomic_operation(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.card' )
			->css( 'display: grid' )
			->type( 'class' )
			->add();
		$reference = ClassStyleReference::registered( 'opaque-card-id' );

		$markups = array(
			DynamicElementBlock::new()->tag( 'article' )->class_style( $reference )->to_block()->to_string(),
			DynamicImageBlock::new()->class_style( $reference )->to_block()->to_string(),
			SvgBlock::new()->class_style( $reference )->to_block()->to_string(),
		);

		self::assertSame(
			array(
				'<!-- wp:etch/dynamic-element {"tag":"article","attributes":{"class":"card"},"styles":["opaque-card-id"]} --><!-- /wp:etch/dynamic-element -->',
				'<!-- wp:etch/dynamic-image {"tag":"img","attributes":{"class":"card"},"styles":["opaque-card-id"]} /-->',
				'<!-- wp:etch/svg {"tag":"svg","attributes":{"class":"card"},"styles":["opaque-card-id"]} --><!-- /wp:etch/svg -->',
			),
			$markups
		);
	}

	public function test_repeated_attachment_is_idempotent_and_preserves_existing_order(): void {
		Style::new()
			->id( 'opaque-card-id' )
			->selector( '.card' )
			->css( 'display: grid' )
			->type( 'class' )
			->add();
		$reference = ClassStyleReference::registered( 'opaque-card-id' );

		$markup = ElementBlock::new()
			->tag( 'article' )
			->attribute( 'class', 'existing' )
			->style( 'existing-style-id' )
			->class_style( $reference )
			->class_style( $reference )
			->to_block()
			->to_string();

		self::assertStringContainsString( '"class":"existing card"', $markup );
		self::assertStringContainsString( '"styles":["existing","existing-style-id","opaque-card-id"]', $markup );
		self::assertSame( 1, substr_count( $markup, 'opaque-card-id' ) );
	}

	public function test_persisted_numeric_opaque_id_is_attached_without_registry_or_storage_mutation(): void {
		$persisted = array(
			'123' => array(
				'selector'   => '.hero',
				'collection' => 'User styles',
				'css'        => 'display: grid',
				'type'       => 'class',
			),
		);
		$storage = new AtomicBlockClassStyleStorage( array( 'etch_styles' => $persisted ) );
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$markup = ElementBlock::new()
			->tag( 'section' )
			->class_style( ClassStyleReference::registered( '123' ) )
			->to_block()
			->to_string();

		self::assertStringContainsString( '"class":"hero"', $markup );
		self::assertStringContainsString( '"styles":["123"]', $markup );
		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
		self::assertSame( 0, $storage->set_calls );
		self::assertSame( 0, $storage->delete_calls );
	}

	public function test_stale_reference_fails_before_mutating_the_block(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.before' )
			->css( 'color: red' )
			->type( 'class' )
			->add();
		$reference = ClassStyleReference::registered( 'opaque-style-id' );
		Style::reset();
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.after' )
			->css( 'color: blue' )
			->type( 'class' )
			->add();
		$block = ElementBlock::new()->tag( 'div' );

		try {
			$block->class_style( $reference );
			self::fail( 'A stale Class Style Reference must be rejected.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'changed selector identity', $exception->getMessage() );
		}

		self::assertSame(
			'<!-- wp:etch/element {"tag":"div","attributes":[]} --><!-- /wp:etch/element -->',
			$block->to_block()->to_string()
		);
	}
}

/**
 * Storage spy proving atomic block styling is observational.
 */
final class AtomicBlockClassStyleStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @param array<string, mixed> $values Initial values.
	 */
	public function __construct( private array $values = array() ) {
	}

	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function set( string $key, mixed $value ): bool {
		++$this->set_calls;
		$this->values[ $key ] = $value;

		return true;
	}

	public function delete( string $key ): bool {
		++$this->delete_calls;
		unset( $this->values[ $key ] );

		return true;
	}
}
