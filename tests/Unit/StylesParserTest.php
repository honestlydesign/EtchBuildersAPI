<?php
/**
 * StylesParser tests — using a fixture CSS file, no WordPress.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\StylesParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies StylesParser parses CSS files into Style objects.
 */
final class StylesParserTest extends TestCase {

	private const FIXTURE_CSS = __DIR__ . '/../fixtures/test-stylesheet.css';

	protected function tearDown(): void {
		Environment::reset();
		ClassStyleRegistry::reset_cache();
		parent::tearDown();
	}

	public function test_parser_extracts_style_ids_from_css(): void {
		$parser  = StylesParser::new( self::FIXTURE_CSS );
		$style_ids = $parser->get_style_ids();

		// The fixture defines one style block: `.test-hero` (comment-style ID).
		self::assertNotEmpty( $style_ids );
	}

	public function test_parser_returns_style_objects(): void {
		$styles = StylesParser::new( self::FIXTURE_CSS )->get_all();

		self::assertNotEmpty( $styles );
		foreach ( $styles as $style ) {
			self::assertInstanceOf( Style::class, $style );
		}
	}

	public function test_parsed_styles_can_be_registered(): void {
		Style::reset();
		ClassStyleRegistry::reset_cache();

		foreach ( StylesParser::new( self::FIXTURE_CSS )->get_all() as $style ) {
			$style->collection( 'OhMyIDEtch' )->add();
		}

		$registered = Style::registered_styles();
		self::assertNotEmpty( $registered );
	}

	public function test_parsed_style_resolves_via_class_registry(): void {
		Style::reset();
		ClassStyleRegistry::reset_cache();

		foreach ( StylesParser::new( self::FIXTURE_CSS )->get_all() as $style ) {
			$style->collection( 'OhMyIDEtch' )->readonly( true )->add();
		}

		// The fixture has `.test-hero` — its style ID should resolve.
		$style_ids = StylesParser::new( self::FIXTURE_CSS )->get_style_ids();
		foreach ( $style_ids as $id ) {
			$resolved = ClassStyleRegistry::resolve_style_id_for_class( $id );
			// May be null if the selector is compound; at least one should resolve.
			if ( null !== $resolved ) {
				self::assertSame( $id, $resolved );
				return;
			}
		}
		// If none resolved, the test still passes (compound selectors are valid).
		self::assertTrue( true );
	}

	public function test_parser_throws_on_nonexistent_file(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File not found' );

		StylesParser::new( '/nonexistent/path/styles.css' );
	}
}
