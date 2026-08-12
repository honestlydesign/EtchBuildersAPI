<?php
/**
 * Explicit class-token provenance tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassProvenance;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassToken;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves each supported class origin is explicit and immutable.
 */
final class ClassTokenTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Style::reset();
		parent::tearDown();
	}

	public function test_site_presentation_retains_the_exact_current_style_reference(): void {
		Style::new()
			->id( 'opaque-hero-id' )
			->selector( '.hero' )
			->css( 'display: grid' )
			->type( 'class' )
			->add();
		$reference = ClassStyleReference::registered( 'opaque-hero-id' );

		$token = ClassToken::site_presentation( $reference );

		self::assertSame( 'hero', $token->token() );
		self::assertSame( ClassProvenance::SITE_PRESENTATION, $token->provenance() );
		self::assertSame( $reference, $token->style_reference() );
		self::assertNull( $token->origin() );
	}

	public function test_named_non_site_provenance_is_exposed_without_a_style_reference(): void {
		$utility   = ClassToken::project_utility( 'u-hidden' );
		$framework = ClassToken::external_framework( 'grid', 'ACSS' );
		$runtime   = ClassToken::runtime_state( 'rt-active', 'Etch Runtime' );

		self::assertSame( ClassProvenance::PROJECT_UTILITY, $utility->provenance() );
		self::assertSame( ClassProvenance::EXTERNAL_FRAMEWORK, $framework->provenance() );
		self::assertSame( ClassProvenance::RUNTIME_STATE, $runtime->provenance() );
		self::assertSame( 'ACSS', $framework->origin() );
		self::assertSame( 'Etch Runtime', $runtime->origin() );
		self::assertNull( $utility->style_reference() );
		self::assertNull( $framework->style_reference() );
		self::assertNull( $runtime->style_reference() );
	}

	/**
	 * @dataProvider invalid_token_provider
	 */
	public function test_all_non_site_tokens_require_one_unchanged_html_class_token( string $token ): void {
		$this->expectException( InvalidArgumentException::class );

		ClassToken::project_utility( $token );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_token_provider(): array {
		return array(
			'empty'             => array( '' ),
			'only whitespace'   => array( "\t" ),
			'leading space'     => array( ' card' ),
			'trailing newline'  => array( "card\n" ),
			'multiple classes'  => array( 'card featured' ),
		);
	}

	/**
	 * @dataProvider missing_origin_provider
	 */
	public function test_external_and_runtime_tokens_require_a_non_empty_exact_origin( string $origin ): void {
		$this->expectException( InvalidArgumentException::class );

		ClassToken::external_framework( 'grid', $origin );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function missing_origin_provider(): array {
		return array(
			'empty'            => array( '' ),
			'only whitespace'  => array( '  ' ),
			'leading space'    => array( ' ACSS' ),
			'trailing space'   => array( 'ACSS ' ),
		);
	}
}
