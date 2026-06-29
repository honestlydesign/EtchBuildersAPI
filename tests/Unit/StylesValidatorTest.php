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
			'.title { color: red; }',
			StylesParserMode::CLASS_PROP
		);

		self::assertSame( array(), $errors );
	}

	public function test_valid_fixed_style_blocks_return_empty_errors(): void {
		$errors = StylesValidator::validate(
			'.card:hover { color: red; } [data-card] { color: blue; }',
			StylesParserMode::FIXED
		);

		self::assertSame( array(), $errors );
	}

	public function test_duplicate_root_selector_returns_error(): void {
		$errors = StylesValidator::validate(
			'.card { color: red; } .card { color: blue; }',
			StylesParserMode::FIXED
		);

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'Duplicate selector `.card`', implode( "\n", $errors ) );
	}

	public function test_duplicate_selector_list_with_comma_spacing_difference_returns_error(): void {
		$errors = StylesValidator::validate(
			'.card-a,.card-b { color: red; } .card-a, .card-b { color: blue; }',
			StylesParserMode::FIXED
		);

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'Duplicate selector `.card-a, .card-b`', implode( "\n", $errors ) );
	}

	public function test_nested_media_inside_style_block_returns_empty_errors(): void {
		$errors = StylesValidator::validate(
			'.card { display: grid; @media (max-width: 48rem) { grid-template-columns: 1fr; } }',
			StylesParserMode::FIXED
		);

		self::assertSame( array(), $errors );
	}

	public function test_nested_container_inside_style_block_returns_empty_errors(): void {
		$errors = StylesValidator::validate(
			'.card { display: grid; @container (min-width: 40rem) { grid-template-columns: 1fr 1fr; } }',
			StylesParserMode::FIXED
		);

		self::assertSame( array(), $errors );
	}

	public function test_nested_global_at_rule_names_in_strings_and_comments_are_valid(): void {
		$errors = StylesValidator::validate(
			'.card { content: "@keyframes"; /* @supports note */ color: red; }',
			StylesParserMode::FIXED
		);

		self::assertSame( array(), $errors );
	}

	public function test_real_nested_keyframes_returns_error(): void {
		$errors = StylesValidator::validate(
			'.card { color: red; @keyframes fade { from { opacity: 0; } to { opacity: 1; } } }',
			StylesParserMode::FIXED
		);

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'cannot nest global at-rules such as @keyframes', implode( "\n", $errors ) );
	}

	public function test_root_level_media_returns_guidance_error(): void {
		$errors = StylesValidator::validate(
			'.card { display: grid; } @media (max-width: 48rem) { .card { grid-template-columns: 1fr; } }',
			StylesParserMode::FIXED
		);

		$message = implode( "\n", $errors );

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'StylesParser cannot parse root-level @media', $message );
		self::assertStringContainsString( 'Wrong: .foo { color: red; } @media', $message );
		self::assertStringContainsString( 'Right: .foo { color: red; @media', $message );
	}

	public function test_root_level_container_returns_guidance_error(): void {
		$errors = StylesValidator::validate(
			'.card { display: grid; } @container (min-width: 48rem) { .card { grid-template-columns: 1fr; } }',
			StylesParserMode::FIXED
		);

		$message = implode( "\n", $errors );

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'StylesParser cannot parse root-level @container', $message );
		self::assertStringContainsString( 'Wrong: .foo { color: red; } @container', $message );
		self::assertStringContainsString( 'Right: .foo { color: red; @container', $message );
	}

	public function test_root_level_keyframes_returns_stylesheet_guidance_error(): void {
		$errors = StylesValidator::validate(
			'.card { color: red; } @keyframes fade { from { opacity: 0; } to { opacity: 1; } }',
			StylesParserMode::FIXED
		);

		$message = implode( "\n", $errors );

		self::assertNotSame( array(), $errors );
		self::assertStringContainsString( 'StylesParser cannot parse root-level @keyframes', $message );
		self::assertStringContainsString( 'Use Stylesheet or ->stylesheet()', $message );
	}

	public function test_extract_referenced_custom_media_finds_paren_dashed_names(): void {
		$content = <<<CSS
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
		$content = '.hero { color: red; }';

		self::assertSame( array(), StylesValidator::extract_referenced_custom_media( $content ) );
	}

	public function test_extract_referenced_custom_media_deduplicates(): void {
		$content = '.a { @media (--tablet) { x: 1; } } .b { @media (--tablet) { y: 2; } }';

		self::assertSame( array( 'tablet' ), StylesValidator::extract_referenced_custom_media( $content ) );
	}
}
