<?php
/**
 * Narrow file-based JavaScript tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Javascript;
use BadMethodCallException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies file-based JavaScript placeholders and conflict behavior.
 */
final class JavascriptTest extends TestCase {

	private const FIXTURE_JS = __DIR__ . '/../fixtures/test-script.js';

	protected function tearDown(): void {
		Javascript::reset();
		parent::tearDown();
	}

	public function test_file_source_is_base64_encoded_and_injected_at_markup_boundary(): void {
		$placeholder = Javascript::set_from_file( 'test-script', self::FIXTURE_JS );
		$markup      = '<!-- wp:etch/text {"script":"' . $placeholder . '"} /-->';

		self::assertStringNotContainsString( $placeholder, Javascript::inject_placeholders( $markup ) );
		self::assertStringContainsString(
			base64_encode( "window.__etchBuilderTest = true;\n" ),
			Javascript::inject_placeholders( $markup )
		);
	}

	public function test_identical_file_registration_is_idempotent(): void {
		$first  = Javascript::set_from_file( 'test-script', self::FIXTURE_JS );
		$before = Javascript::snapshot();
		$second = Javascript::set_from_file( 'test-script', self::FIXTURE_JS );

		self::assertSame( $first, $second );
		self::assertSame( $before, Javascript::snapshot() );
	}

	public function test_conflicting_script_id_fails_without_replacing_existing_registration(): void {
		$first = Javascript::set_from_file( 'test-script', self::FIXTURE_JS );
		$before = Javascript::snapshot();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'already registered' );
		try {
			Javascript::set_from_file( 'test-script', __FILE__ );
		} finally {
			self::assertSame( $before, Javascript::snapshot() );
		}

		self::assertNotSame( '', $first );
	}

	/**
	 * @dataProvider invalid_file_registration_provider
	 */
	public function test_invalid_file_registration_fails( string $script_id, string $file_path, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		Javascript::set_from_file( $script_id, $file_path );
	}

	public function test_manifest_and_component_setters_remain_unsupported(): void {
		$this->expectException( BadMethodCallException::class );
		$this->expectExceptionMessage( 'set_from_file()' );

		Javascript::set( 'component' );
	}

	public function test_manifest_entry_remains_unsupported(): void {
		$this->expectException( BadMethodCallException::class );
		$this->expectExceptionMessage( 'set_from_file()' );

		Javascript::set_manifest_entry( 'component', 'dist/index.js' );
	}

	public function test_unknown_placeholder_is_left_unchanged(): void {
		$markup = '<!-- wp:etch/text {"script":"__OH_MY_ID_ETCH_SCRIPT__UNKNOWN__"} /-->';

		self::assertSame( $markup, Javascript::inject_placeholders( $markup ) );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_file_registration_provider(): array {
		return array(
			'empty id'      => array( '   ', self::FIXTURE_JS, 'script id must be non-empty' ),
			'bad id'        => array( 'test script', self::FIXTURE_JS, 'script id must match' ),
			'empty path'    => array( 'test-script', '   ', 'file path must be non-empty' ),
			'missing file'  => array( 'test-script', __DIR__ . '/../fixtures/missing.js', 'file not readable' ),
		);
	}
}
