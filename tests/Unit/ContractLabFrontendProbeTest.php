<?php
/**
 * Composite Contract Lab frontend probe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabFrontendFixture;
use HonestlyDesign\EtchBuilders\ContractLabFrontendFixtureCatalog;
use HonestlyDesign\EtchBuilders\ContractLabFrontendCssNormalizer;
use HonestlyDesign\EtchBuilders\ContractLabFrontendHttpResponse;
use HonestlyDesign\EtchBuilders\ContractLabFrontendProbe;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabFrontendHttpClientInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves that one HTTP fixture can carry several ordered frontend outcomes.
 */
final class ContractLabFrontendProbeTest extends TestCase {

	public function test_composite_fixture_observes_ordered_dom_css_and_capability_markers(): void {
		$fixture = ContractLabFrontendFixture::new(
			'marketing-home',
			'/contract-fixtures/marketing-home',
			array(
				'dom'        => 'marketing-home',
				'stylesheet' => '.marketing-card',
				'class'      => 'marketing-card',
				'slot'       => 'headline',
				'loop'       => 'item-1',
				'dynamic'    => 'title',
			)
		);
		$catalog = ContractLabFrontendFixtureCatalog::new( array( $fixture ) );
		$client  = new class implements ContractLabFrontendHttpClientInterface {

			/** @var array<int, string> */
			public array $paths = array();

			public function get( string $path ): ContractLabFrontendHttpResponse {
				$this->paths[] = $path;

				if ( '/assets/marketing.css' === $path ) {
					return ContractLabFrontendHttpResponse::new(
						200,
						'.marketing-card { display: grid; } .marketing-card h1 { color: red; }',
						array( 'content-type' => 'text/css' )
					);
				}

				return ContractLabFrontendHttpResponse::new(
					200,
					'<!doctype html><html><head><link rel="stylesheet" href="/assets/marketing.css"><style>.inline { order: 1; }</style></head><body><main data-contract-fixture="marketing-home" class="marketing-card"><h1 data-contract-slot="headline" data-contract-loop="item-1" data-contract-dynamic="title">First</h1><p>Second</p></main></body></html>',
					array( 'content-type' => 'text/html' )
				);
			}
		};

		$result = ContractLabFrontendProbe::run_all( $catalog, $client )[0];

		self::assertSame( 'observed', $result->status() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertSame( array( '/contract-fixtures/marketing-home', '/assets/marketing.css' ), $client->paths );
		$observation = $result->observation();
		self::assertNotNull( $observation );
		self::assertSame( 'main', $observation->dom()[0]['name'] );
		self::assertSame( 'h1', $observation->dom()[0]['children'][0]['name'] );
		self::assertSame( 'p', $observation->dom()[0]['children'][1]['name'] );
		self::assertSame( '.marketing-card', $observation->stylesheets()[0]['rules'][0]['selector'] );
		self::assertSame( '.marketing-card h1', $observation->stylesheets()[0]['rules'][1]['selector'] );
		self::assertSame( 'inline', $observation->stylesheets()[1]['source'] );
		self::assertSame(
			array( 'dom', 'stylesheet', 'class', 'slot', 'loop', 'dynamic' ),
			array_column( $observation->capabilities(), 'capability' )
		);
		self::assertSame(
			array( 'observed', 'observed', 'observed', 'observed', 'observed', 'observed' ),
			array_column( $observation->capabilities(), 'status' )
		);
	}

	public function test_unsupported_fixture_is_an_explicit_skip_and_not_a_pass(): void {
		$fixture = ContractLabFrontendFixture::new( 'woo-loop', '/contract-fixtures/woo-loop', array( 'loop' => 'product' ) );
		$client  = new class implements ContractLabFrontendHttpClientInterface {
			public function get( string $path ): ContractLabFrontendHttpResponse {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Optional frontend prerequisite unavailable for %s.', $path ) );
			}
		};

		$result = ContractLabFrontendProbe::run( $fixture, $client );

		self::assertSame( 'skipped', $result->status() );
		self::assertFalse( $result->assertions_passed() );
		self::assertSame( 'Optional frontend prerequisite unavailable for /contract-fixtures/woo-loop.', $result->reason() );
		self::assertNull( $result->observation() );
	}

	public function test_transport_unavailability_is_inconclusive_and_missing_marker_fails(): void {
		$fixture = ContractLabFrontendFixture::new( 'dynamic-card', '/contract-fixtures/dynamic-card', array( 'dynamic' => 'title' ) );
		$unavailable = new class implements ContractLabFrontendHttpClientInterface {
			public function get( string $path ): ContractLabFrontendHttpResponse {
				throw new ContractLabObservationException( 'unavailable', 'LocalWP HTTP transport is unavailable.' );
			}
		};
		$inconclusive = ContractLabFrontendProbe::run( $fixture, $unavailable );

		self::assertSame( 'inconclusive', $inconclusive->status() );
		self::assertFalse( $inconclusive->assertions_passed() );

		$missing_marker_client = new class implements ContractLabFrontendHttpClientInterface {
			public function get( string $path ): ContractLabFrontendHttpResponse {
				return ContractLabFrontendHttpResponse::new( 200, '<main>Rendered without the required marker.</main>', array( 'content-type' => 'text/html' ) );
			}
		};
		$failed = ContractLabFrontendProbe::run( $fixture, $missing_marker_client );

		self::assertSame( 'failed', $failed->status() );
		self::assertFalse( $failed->assertions_passed() );
		self::assertSame( array( 'dynamic' ), $failed->failures() );
		self::assertStringContainsString( 'dynamic', $failed->failure_message() );
	}

	public function test_fixture_catalog_rejects_duplicate_paths_and_unknown_capabilities(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown frontend capability' );
		ContractLabFrontendFixture::new( 'bad', '/bad', array( 'private-etcha-internal' => 'x' ) );
	}

	public function test_fixture_catalog_projection_is_canonical_and_duplicate_paths_are_rejected(): void {
		$first  = ContractLabFrontendFixture::new( 'first', '/contract-fixtures/shared', array( 'dom' => 'first' ) );
		$second = ContractLabFrontendFixture::new( 'second', '/contract-fixtures/second', array( 'class' => 'second' ) );
		$catalog = ContractLabFrontendFixtureCatalog::new( array( $first, $second ) );

		self::assertSame( $catalog->to_array(), ContractLabFrontendFixtureCatalog::from_array( $catalog->to_array() )->to_array() );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate HTTP path' );
		ContractLabFrontendFixtureCatalog::new(
			array(
				$first,
				ContractLabFrontendFixture::new( 'duplicate', '/contract-fixtures/shared', array( 'dom' => 'duplicate' ) ),
			)
		);
	}

	public function test_nested_css_rules_keep_rule_order_and_external_stylesheets_are_skipped(): void {
		$rules = ContractLabFrontendCssNormalizer::normalize( '@media screen { .first { color: red; } .second { color: blue; } } .third { order: 1; }' );
		self::assertSame( '@media screen', $rules[0]['at_rule'] );
		self::assertSame( array( '.first', '.second' ), array_column( $rules[0]['rules'], 'selector' ) );
		self::assertSame( '.third', $rules[1]['selector'] );

		$fixture = ContractLabFrontendFixture::new( 'external-css', '/contract-fixtures/external-css', array( 'stylesheet' => '.external' ) );
		$client  = new class implements ContractLabFrontendHttpClientInterface {
			public function get( string $path ): ContractLabFrontendHttpResponse {
				return ContractLabFrontendHttpResponse::new( 200, '<main data-contract-fixture="external-css"><link rel="stylesheet" href="https://example.test/external.css"></main>', array( 'content-type' => 'text/html' ) );
			}
		};
		$result = ContractLabFrontendProbe::run( $fixture, $client );

		self::assertSame( 'skipped', $result->status() );
		self::assertStringContainsString( 'same-origin', (string) $result->reason() );
	}

	public function test_native_css_nesting_keeps_selector_rules_inside_the_parent_rule(): void {
		$rules = ContractLabFrontendCssNormalizer::normalize( '@layer reset { body { display: flex; main { flex-grow: 1; } } }' );

		self::assertSame( 'body', $rules[0]['rules'][0]['selector'] );
		self::assertSame( 'display', $rules[0]['rules'][0]['declarations'][0]['property'] );
		self::assertSame( 'main', $rules[0]['rules'][0]['rules'][0]['selector'] );
		self::assertSame( 'flex-grow', $rules[0]['rules'][0]['rules'][0]['declarations'][0]['property'] );
	}

	public function test_native_css_nesting_preserves_declaration_and_nested_rule_order(): void {
		$rules = ContractLabFrontendCssNormalizer::normalize( '.parent { color: red; .child { display: block; } background: blue; }' );

		self::assertSame(
			array(
				array( 'kind' => 'declaration', 'index' => 0 ),
				array( 'kind' => 'rule', 'index' => 0 ),
				array( 'kind' => 'declaration', 'index' => 1 ),
			),
			$rules[0]['order']
		);
	}

	public function test_malformed_css_fails_closed_without_emitting_raw_html(): void {
		$fixture = ContractLabFrontendFixture::new( 'malformed-css', '/contract-fixtures/malformed-css', array( 'stylesheet' => '.broken' ) );
		$client  = new class implements ContractLabFrontendHttpClientInterface {
			public function get( string $path ): ContractLabFrontendHttpResponse {
				return ContractLabFrontendHttpResponse::new( 200, '<main data-contract-fixture="malformed-css"><style>.broken { color red; }</style></main>', array( 'content-type' => 'text/html' ) );
			}
		};
		$result = ContractLabFrontendProbe::run( $fixture, $client );

		self::assertSame( 'failed', $result->status() );
		self::assertStringContainsString( 'declaration without a colon', (string) $result->reason() );
		self::assertStringNotContainsString( '<main', json_encode( $result->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_wordpress_http_adapter_is_a_local_public_api_boundary(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/WordPressFrontendHttpClient.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'wp_safe_remote_get', $source );
		self::assertStringContainsString( 'wp_get_environment_type', $source );
		self::assertStringNotContainsString( 'curl_exec', $source );
		self::assertStringNotContainsString( 'file_get_contents', $source );
	}
}
