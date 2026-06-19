<?php
/**
 * Styles validator tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\StylesParserMode;
use HonestlyDesign\EtchBuilders\StylesValidator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies focused CSS structure validation behavior.
 */
final class StylesValidatorTest extends TestCase {

	public function test_valid_class_prop_style_block_returns_empty_errors(): void {
		$errors = StylesValidator::validate(
			'/* title */ .title { color: red; }',
			StylesParserMode::CLASS_PROP
		);

		self::assertSame( array(), $errors );
	}

	public function test_root_level_keyframes_outside_style_blocks_returns_error(): void {
		$errors = StylesValidator::validate(
			'/* title */ .title { color: red; } @keyframes fade { from { opacity: 0; } to { opacity: 1; } }',
			StylesParserMode::CLASS_PROP
		);

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'root-level rules outside', implode( "\n", $errors ) );
	}

	public function test_nested_media_inside_style_block_returns_empty_errors(): void {
		$errors = StylesValidator::validate(
			'/* card */ .card { display: grid; @media (max-width: 48rem) { grid-template-columns: 1fr; } }',
			StylesParserMode::FIXED
		);

		self::assertSame( array(), $errors );
	}

	public function test_root_level_media_returns_correction_error(): void {
		$errors = StylesValidator::validate(
			'/* card */ .card { display: grid; } @media (max-width: 48rem) { .card { grid-template-columns: 1fr; } }',
			StylesParserMode::FIXED
		);

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'Move @media inside the relevant style block', implode( "\n", $errors ) );
	}

	public function test_extract_referenced_custom_media_finds_paren_dashed_names(): void {
		$content = <<<CSS
/* hero */
.hero {
	color: red;
	@media (--tablet) { color: blue; }
	@media (--desktop) { color: green; }
}
CSS;

		$refs = StylesValidator::extract_referenced_custom_media( $content );

		self::assertContains( 'tablet', $refs );
		self::assertContains( 'desktop', $refs );
	}

	public function test_extract_referenced_custom_media_returns_empty_when_none(): void {
		$content = '/* hero */ .hero { color: red; }';

		self::assertSame( array(), StylesValidator::extract_referenced_custom_media( $content ) );
	}

	public function test_extract_referenced_custom_media_deduplicates(): void {
		$content = '/* a */ .a { @media (--tablet) { x: 1; } } /* b */ .b { @media (--tablet) { y: 2; } }';

		self::assertSame( array( 'tablet' ), StylesValidator::extract_referenced_custom_media( $content ) );
	}
}
