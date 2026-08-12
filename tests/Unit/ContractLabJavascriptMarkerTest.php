<?php
/**
 * Contract Lab minimal JavaScript marker tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarker;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarkerRunner;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabJavascriptMarkerClientInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves one passive marker assertion can piggyback an existing browser flow.
 */
final class ContractLabJavascriptMarkerTest extends TestCase {

	public function test_marketing_marker_is_bound_to_the_existing_file_based_script_path(): void {
		$marker = ContractLabJavascriptMarker::marketing_reference();

		self::assertSame( 'marketing-ready', $marker->logical_id() );
		self::assertSame( 'marketing-home', $marker->fixture_id() );
		self::assertSame( 'marketing-hero', $marker->script_id() );
		self::assertSame( 'etchMarketingReady', $marker->property() );
		self::assertSame( 'true', $marker->expected_value() );
		self::assertSame( $marker->to_array(), ContractLabJavascriptMarker::from_array( $marker->to_array() )->to_array() );

		$recipe_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/MarketingReferenceSiteAuthoringRecipe.php' );
		$script_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/AuthoringFixtures/marketing.js' );
		self::assertIsString( $recipe_source );
		self::assertIsString( $script_source );
		self::assertStringContainsString( 'Javascript::set_from_file', $recipe_source );
		self::assertStringContainsString( 'marketing-hero', $recipe_source );
		self::assertStringContainsString( 'document.documentElement.dataset.etchMarketingReady', $script_source );
		self::assertStringNotContainsString( 'set_manifest_entry', $recipe_source );
		self::assertStringNotContainsString( 'watch', $script_source );
	}

	public function test_runner_reports_one_passive_marker_assertion_on_the_existing_flow(): void {
		$marker = ContractLabJavascriptMarker::marketing_reference();
		$client = new class implements ContractLabJavascriptMarkerClientInterface {

			/** @var array<int, string> */
			public array $markers = array();

			public function read_marker( ContractLabJavascriptMarker $marker ): ?string {
				$this->markers[] = $marker->logical_id();

				return 'true';
			}
		};

		$result = ContractLabJavascriptMarkerRunner::run( $marker, $client );

		self::assertSame( 'observed', $result->status() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertSame( 'true', $result->observed_value() );
		self::assertSame( array( 'marketing-ready' ), $client->markers );
	}

	public function test_missing_marker_is_failure_and_transport_or_prerequisite_errors_are_not(): void {
		$marker = ContractLabJavascriptMarker::marketing_reference();
		$missing_client = new class implements ContractLabJavascriptMarkerClientInterface {
			public function read_marker( ContractLabJavascriptMarker $marker ): ?string {
				return null;
			}
		};
		$missing = ContractLabJavascriptMarkerRunner::run( $marker, $missing_client );

		self::assertSame( 'failed', $missing->status() );
		self::assertFalse( $missing->assertions_passed() );
		self::assertNull( $missing->observed_value() );
		self::assertStringContainsString( 'marker missing', $missing->failure_message() );

		$unavailable_client = new class implements ContractLabJavascriptMarkerClientInterface {
			public function read_marker( ContractLabJavascriptMarker $marker ): ?string {
				throw new ContractLabObservationException( 'unavailable', 'Browser navigation did not complete.' );
			}
		};
		$unavailable = ContractLabJavascriptMarkerRunner::run( $marker, $unavailable_client );
		self::assertSame( 'inconclusive', $unavailable->status() );
		self::assertFalse( $unavailable->assertions_passed() );

		$unsupported_client = new class implements ContractLabJavascriptMarkerClientInterface {
			public function read_marker( ContractLabJavascriptMarker $marker ): ?string {
				throw new ContractLabObservationException( 'unsupported', 'The host does not enqueue the file-based fixture script.' );
			}
		};
		$unsupported = ContractLabJavascriptMarkerRunner::run( $marker, $unsupported_client );
		self::assertSame( 'skipped', $unsupported->status() );
		self::assertFalse( $unsupported->assertions_passed() );
	}

	public function test_marker_definition_rejects_empty_or_unknown_values(): void {
		$this->expectException( InvalidArgumentException::class );
		ContractLabJavascriptMarker::new( 'marker', 'fixture', 'script', '', 'true' );
	}
}
