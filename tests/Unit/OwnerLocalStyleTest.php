<?php
/**
 * Owner-local CSS and explicit style escape tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\PortalStyle;
use HonestlyDesign\EtchBuilders\ScopedSelector;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Stylesheet;
use HonestlyDesign\EtchBuilders\StylesValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the safe owner-local CSS authoring seam.
 */
final class OwnerLocalStyleTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Style::reset();
		Stylesheet::reset_custom_media();
		parent::tearDown();
	}

	public function test_owner_local_validator_accepts_native_nested_pseudo_state_descendant_media_and_container_rules(): void {
		$css = <<<'CSS'
	color: var(--hero-color);
&:hover { color: red; }
&[data-state="open"] { color: green; }
& .hero__title { font-weight: 700; }
@media (min-width: 48rem) { & .hero__title { font-size: 2rem; } }
@container hero (min-width: 30rem) { grid-template-columns: 1fr 1fr; }
CSS;

		self::assertSame( array(), StylesValidator::validate_owner_local_css( '.hero', $css ) );
	}

	public function test_owner_local_style_keeps_selector_and_css_body_separate_in_etch_wire_shape(): void {
		$css = 'color: red; &:hover { color: blue; } @media (min-width: 48rem) { color: green; }';

		$style_id = Style::new()
			->id( 'hero-style-id' )
			->selector( '.hero' )
			->owner_local_css( $css )
			->add();

		self::assertSame( 'hero-style-id', $style_id );
		self::assertSame(
			array(
				'selector'   => '.hero',
				'collection' => 'default',
				'css'        => $css,
				'type'       => 'class',
			),
			Style::registered_styles()['hero-style-id']
		);
	}

	public function test_owner_local_style_rejects_global_at_rules_with_stylesheet_guidance(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Stylesheet or PortalStyle' );

		Style::new()
			->id( 'hero-style-id' )
			->selector( '.hero' )
			->owner_local_css( '@keyframes fade { from { opacity: 0; } to { opacity: 1; } }' )
			->add();
	}

	public function test_owner_local_style_rejects_sass_parent_synthesis(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'flat BEM root' );

		Style::new()
			->id( 'hero-style-id' )
			->selector( '.hero' )
			->owner_local_css( '&__title { color: red; }' )
			->add();
	}

	public function test_owner_local_style_rejects_repeated_owner_inside_nested_media(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must contain the ruleset body only' );

		Style::new()
			->id( 'hero-style-id' )
			->selector( '.hero' )
			->owner_local_css( '@media (min-width: 48rem) { .hero { color: red; } }' )
			->add();
	}

	public function test_owner_local_css_cannot_be_downgraded_to_raw_css_by_chaining_css(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Stylesheet or PortalStyle' );

		Style::new()
			->id( 'hero-style-id' )
			->selector( '.hero' )
			->owner_local_css( 'color: red;' )
			->css( '@keyframes fade { from { opacity: 0; } to { opacity: 1; } }' )
			->add();
	}

	public function test_legacy_css_remains_a_raw_compatibility_escape(): void {
		$style_id = Style::new()
			->id( 'legacy-style-id' )
			->selector( '.hero' )
			->css( '@keyframes fade { from { opacity: 0; } to { opacity: 1; } }' )
			->add();

		self::assertSame( 'legacy-style-id', $style_id );
		self::assertSame(
			'@keyframes fade { from { opacity: 0; } to { opacity: 1; } }',
			Style::registered_styles()['legacy-style-id']['css']
		);
	}

	public function test_scoped_selector_requires_reason_and_is_explicitly_accepted(): void {
		$style = Style::new()
			->id( 'scoped-style-id' )
			->scoped_selector( ScopedSelector::new( '[data-dialog] p > .dialog__title', 'Etch renders this node outside its serialized host.' ) )
			->css( 'color: red;' );

		self::assertSame( '[data-dialog] p > .dialog__title', $style->to_array()['selector'] );
		self::assertTrue( $style->is_scoped_selector() );

		$style->add();
		self::assertSame( 'custom', Style::registered_styles()['scoped-style-id']['type'] );
	}

	public function test_scoped_selector_without_reason_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'reason' );

		ScopedSelector::new( '[data-dialog] p', '   ' );
	}

	public function test_portal_style_requires_a_bem_namespaced_selector_and_reason(): void {
		$portal = PortalStyle::new(
			'[data-dialog] .dialog__portal',
			'position: fixed; &:focus-visible { outline: 2px solid currentColor; }',
			'Etch renders the dialog portal outside the serialized component host.'
		);

		$stylesheet = Stylesheet::new()
			->id( 'site-global' )
			->name( 'Site Global' )
			->portal_style( $portal );

		self::assertSame(
			array(
				'name' => 'Site Global',
				'css'  => "[data-dialog] .dialog__portal { position: fixed; &:focus-visible { outline: 2px solid currentColor; } }",
			),
			$stylesheet->to_array()
		);
	}

	public function test_portal_style_rejects_unscoped_global_selector(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'BEM-namespaced' );

		PortalStyle::new( 'p', 'color: red;', 'A reason is present but the selector is global.' );
	}
}
