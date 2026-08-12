<?php
/**
 * Typed global stylesheet policy tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\GlobalStyleFragment;
use HonestlyDesign\EtchBuilders\PortalStyle;
use HonestlyDesign\EtchBuilders\Stylesheet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the checked global stylesheet fragment seam.
 */
final class GlobalStylesheetPolicyTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Stylesheet::reset_active_owner_keys();
		Stylesheet::reset_custom_media();
		parent::tearDown();
	}

	public function test_all_supported_global_categories_append_without_changing_etch_wire_shape(): void {
		$portal = PortalStyle::new(
			'[data-dialog] .dialog__portal',
			'position: fixed;',
			'Etch renders this node outside its serialized component host.'
		);

		$stylesheet = Stylesheet::new()
			->id( 'site-global' )
			->name( 'Site Global' )
			->global_fragment( GlobalStyleFragment::tokens( ':root { --color-brand: red; }' ) )
			->global_fragment( GlobalStyleFragment::framework( '@layer framework { .framework-button { display: inline-flex; } }' ) )
			->global_fragment( GlobalStyleFragment::utility( '.u-stack { display: grid; }' ) )
			->global_fragment( GlobalStyleFragment::font( '@font-face { font-family: Example; src: url(example.woff2); }' ) )
			->global_fragment( GlobalStyleFragment::base( 'html, body { margin: 0; }' ) )
			->global_fragment( GlobalStyleFragment::portal( $portal ) );

		self::assertSame(
			array(
				'name' => 'Site Global',
				'css'  => implode(
					"\n",
					array(
						':root { --color-brand: red; }',
						'@layer framework { .framework-button { display: inline-flex; } }',
						'.u-stack { display: grid; }',
						'@font-face { font-family: Example; src: url(example.woff2); }',
						'html, body { margin: 0; }',
						'[data-dialog] .dialog__portal { position: fixed; }',
					)
				),
			),
			$stylesheet->to_array()
		);
	}

	/**
	 * @dataProvider entity_presentation_category_provider
	 */
	public function test_entity_presentation_is_rejected_from_non_utility_global_categories( string $category ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'owner-local Style' );

		$factory = array(
			'tokens' => static fn (): GlobalStyleFragment => GlobalStyleFragment::tokens( '.hero { color: red; }' ),
			'base'   => static fn (): GlobalStyleFragment => GlobalStyleFragment::base( '.hero { color: red; }' ),
			'font'   => static fn (): GlobalStyleFragment => GlobalStyleFragment::font( '@font-face { font-family: Example; } .hero { color: red; }' ),
		);

		$factory[ $category ]();
	}

	public function test_utility_category_is_explicitly_allowed_but_requires_a_class_rule(): void {
		self::assertSame( '.u-flow { display: flex; }', GlobalStyleFragment::utility( '.u-flow { display: flex; }' )->css() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'utility category requires' );

		GlobalStyleFragment::utility( ':root { --flow: grid; }' );
	}

	public function test_framework_category_can_contain_framework_classes(): void {
		self::assertSame(
			'@layer framework { .framework-button { display: inline-flex; } }',
			GlobalStyleFragment::framework( '@layer framework { .framework-button { display: inline-flex; } }' )->css()
		);
	}

	public function test_raw_stylesheet_css_remains_a_legacy_compatibility_escape(): void {
		self::assertSame(
			'.hero { color: red; }',
			Stylesheet::new()
				->id( 'legacy-global' )
				->name( 'Legacy Global' )
				->css( '.hero { color: red; }' )
				->to_array()['css']
		);
	}

	public function test_raw_setter_cannot_overwrite_a_checked_fragment_chain(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'checked global fragments' );

		Stylesheet::new()
			->id( 'site-global' )
			->name( 'Site Global' )
			->global_fragment( GlobalStyleFragment::tokens( ':root { --color-brand: red; }' ) )
			->css( '.hero { color: red; }' );
	}

	public function test_empty_fragment_is_rejected_before_stylesheet_mutation(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'CSS must be non-empty' );

		GlobalStyleFragment::base( '   ' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function entity_presentation_category_provider(): array {
		return array(
			'tokens' => array( 'tokens' ),
			'base'   => array( 'base' ),
			'font'   => array( 'font' ),
		);
	}
}
