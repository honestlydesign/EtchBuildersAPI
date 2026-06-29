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
use HonestlyDesign\EtchBuilders\StylesParserRuleScanner;
use PHPUnit\Framework\TestCase;

/**
 * Verifies StylesParser parses CSS files into Style objects.
 */
final class StylesParserTest extends TestCase {

	private const FIXTURE_CSS = __DIR__ . '/../fixtures/test-stylesheet.css';

	/**
	 * Temporary CSS files created during tests.
	 *
	 * @var array<int, string>
	 */
	private array $temporary_files = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file_path ) {
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}
		}

		Style::reset();
		Environment::reset();
		ClassStyleRegistry::reset_cache();
		parent::tearDown();
	}

	public function test_parser_uses_single_class_selector_as_style_id(): void {
		$parser  = StylesParser::new( self::FIXTURE_CSS );
		$style_ids = $parser->get_style_ids();

		self::assertContains( 'test-hero', $style_ids );
		self::assertSame( '.test-hero', $parser->get_from_id( 'test-hero' )?->to_array()['selector'] ?? null );
	}

	public function test_parser_generates_stable_id_for_compound_selector(): void {
		$first_parse_ids  = StylesParser::new( self::FIXTURE_CSS )->get_style_ids();
		$second_parse_ids = StylesParser::new( self::FIXTURE_CSS )->get_style_ids();

		$first_generated = array_values(
			array_filter(
				$first_parse_ids,
				static fn ( string $style_id ): bool => str_starts_with( $style_id, 'omide-style-' )
			)
		);
		$second_generated = array_values(
			array_filter(
				$second_parse_ids,
				static fn ( string $style_id ): bool => str_starts_with( $style_id, 'omide-style-' )
			)
		);

		self::assertCount( 1, $first_generated );
		self::assertSame( $first_generated, $second_generated );
		self::assertMatchesRegularExpression( '/^omide-style-[A-Za-z0-9_-]{12}$/', $first_generated[0] );
		self::assertSame( '.test-card:hover', StylesParser::new( self::FIXTURE_CSS )->get_from_id( $first_generated[0] )?->to_array()['selector'] ?? null );
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

		self::assertSame( 'test-hero', ClassStyleRegistry::resolve_style_id_for_class( 'test-hero' ) );
	}

	public function test_parser_uses_optional_legacy_comment_id_when_creating_style(): void {
		$parser = StylesParser::new(
			$this->write_temp_css( '/* old-custom-id */ .comment-free-card { color: red; }' )
		);

		self::assertSame( array( 'old-custom-id' ), $parser->get_style_ids() );
		self::assertSame( '.comment-free-card', $parser->get_from_id( 'old-custom-id' )?->to_array()['selector'] ?? null );
	}

	public function test_parser_generates_id_when_no_legacy_comment_is_present(): void {
		$parser = StylesParser::new(
			$this->write_temp_css( '.comment-free-card:hover { color: red; }' )
		);

		$ids = $parser->get_style_ids();

		self::assertCount( 1, $ids );
		self::assertMatchesRegularExpression( '/^omide-style-[A-Za-z0-9_-]{12}$/', $ids[0] );
		self::assertSame( '.comment-free-card:hover', $parser->get_from_id( $ids[0] )?->to_array()['selector'] ?? null );
	}

	public function test_parser_reuses_persisted_id_for_matching_selector(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'legacy-custom-card' => array(
					'selector' => '.legacy-card:hover',
					'css'      => 'color: green;',
					'type'     => 'custom',
				),
			)
		);

		$parser = StylesParser::new(
			$this->write_temp_css( '.legacy-card:hover { color: red; }' )
		);

		self::assertSame( array( 'legacy-custom-card' ), $parser->get_style_ids() );
	}

	public function test_parser_selector_match_wins_over_legacy_comment_id(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'persisted-selector-owner' => array(
					'selector' => '.selector-owned-card:hover',
					'css'      => 'color: green;',
					'type'     => 'custom',
				),
			)
		);

		$parser = StylesParser::new(
			$this->write_temp_css( '/* stale-comment-id */ .selector-owned-card:hover { color: red; }' )
		);

		self::assertSame( array( 'persisted-selector-owner' ), $parser->get_style_ids() );
		self::assertNull( $parser->get_from_id( 'stale-comment-id' ) );
	}

	public function test_parser_reuses_persisted_id_for_selector_list_with_comma_spacing_difference(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'legacy-selector-list' => array(
					'selector' => '.selector-list-a, .selector-list-b',
					'css'      => 'color: green;',
					'type'     => 'custom',
				),
			)
		);

		$parser = StylesParser::new(
			$this->write_temp_css( '.selector-list-a,.selector-list-b { color: red; }' )
		);

		self::assertSame( array( 'legacy-selector-list' ), $parser->get_style_ids() );
	}

	public function test_parser_reuses_in_memory_id_for_matching_selector(): void {
		Style::new()
			->id( 'memory-card-id' )
			->selector( '.memory-card:hover' )
			->css( 'color: green;' )
			->add();

		$parser = StylesParser::new(
			$this->write_temp_css( '.memory-card:hover { color: red; }' )
		);

		self::assertSame( array( 'memory-card-id' ), $parser->get_style_ids() );
	}

	public function test_parser_throws_on_duplicate_persisted_selector_ids(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'first-ambiguous-id'  => array(
					'selector' => '.ambiguous-card',
					'css'      => 'color: red;',
					'type'     => 'class',
				),
				'second-ambiguous-id' => array(
					'selector' => '.ambiguous-card',
					'css'      => 'color: blue;',
					'type'     => 'class',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Multiple existing Etch styles use selector `.ambiguous-card`' );
		$this->expectExceptionMessage( 'first-ambiguous-id' );
		$this->expectExceptionMessage( 'second-ambiguous-id' );

		StylesParser::new( $this->write_temp_css( '.ambiguous-card { color: red; }' ) );
	}

	public function test_parser_throws_on_in_memory_and_persisted_selector_id_conflict(): void {
		Style::new()
			->id( 'memory-conflict-id' )
			->selector( '.mixed-conflict-card' )
			->css( 'color: green;' )
			->add();

		Environment::storage()->set(
			'etch_styles',
			array(
				'persisted-conflict-id' => array(
					'selector' => '.mixed-conflict-card',
					'css'      => 'color: blue;',
					'type'     => 'class',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Multiple existing Etch styles use selector `.mixed-conflict-card`' );
		$this->expectExceptionMessage( 'memory-conflict-id' );
		$this->expectExceptionMessage( 'persisted-conflict-id' );

		StylesParser::new( $this->write_temp_css( '.mixed-conflict-card { color: red; }' ) );
	}

	public function test_rule_scanner_handles_comments_strings_nested_braces_and_selector_helpers(): void {
		$rules = StylesParserRuleScanner::scan_style_rules(
			<<<'CSS'
/* legacy-id */
.card   >   .item {
	content: "{";
	background-image: url("brace-\"}-asset.svg");
	/* ignored closing brace: } */
	@media (--tablet) {
		color: blue;
	}
}

.card:hover {
	color: green;
}
CSS
		);

		self::assertCount( 2, $rules );
		self::assertSame( '.card   >   .item', $rules[0]['selector'] );
		self::assertStringContainsString( 'brace-\"}-asset.svg', $rules[0]['css'] );
		self::assertStringContainsString( '@media (--tablet)', $rules[0]['css'] );
		self::assertSame( '.card:hover', $rules[1]['selector'] );
		self::assertSame( '.card > .item', StylesParserRuleScanner::normalize_selector_key( " \n.card   >   .item\t" ) );
		self::assertSame(
			'[data-label="hello  world"] .item',
			StylesParserRuleScanner::normalize_selector_key( ' [data-label="hello  world"]    .item ' )
		);
		self::assertSame( '.list-a, .list-b', StylesParserRuleScanner::normalize_selector_key( '.list-a,.list-b' ) );
		self::assertSame( '.list-a, .list-b', StylesParserRuleScanner::normalize_selector_key( '.list-a, .list-b' ) );
		self::assertSame( 'card', StylesParserRuleScanner::single_class_token( '.card' ) );
		self::assertNull( StylesParserRuleScanner::single_class_token( '.card:hover' ) );
		self::assertNull( StylesParserRuleScanner::single_class_token( '.1card' ) );
		self::assertSame(
			StylesParserRuleScanner::generated_style_id_for_selector( '.card:hover' ),
			StylesParserRuleScanner::generated_style_id_for_selector( " \n.card:hover\t" )
		);
		self::assertMatchesRegularExpression(
			'/^omide-style-[A-Za-z0-9_-]{12}$/',
			StylesParserRuleScanner::generated_style_id_for_selector( '.card:hover' )
		);
	}

	public function test_parser_throws_on_nonexistent_file(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'File not found' );

		StylesParser::new( '/nonexistent/path/styles.css' );
	}

	/**
	 * Write CSS to a temporary file consumed by StylesParser.
	 *
	 * @param string $content CSS content.
	 */
	private function write_temp_css( string $content ): string {
		$file_path = tempnam( sys_get_temp_dir(), 'etch-parser-' );
		self::assertIsString( $file_path );

		$css_path = $file_path . '.css';
		rename( $file_path, $css_path );
		file_put_contents( $css_path, $content );

		$this->temporary_files[] = $css_path;

		return $css_path;
	}
}
