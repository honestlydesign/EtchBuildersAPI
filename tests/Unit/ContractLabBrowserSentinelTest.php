<?php
/**
 * Contract Lab browser preservation sentinel tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelCatalog;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelRunner;
use HonestlyDesign\EtchBuilders\ContractLabFrontendObservation;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabBrowserSentinelClientInterface;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Proves that distinct destructive save paths preserve semantic observations.
 */
final class ContractLabBrowserSentinelTest extends TestCase {

	public function test_catalog_requires_distinct_save_actions_and_keeps_entity_context(): void {
		$document    = ContractLabBrowserSentinel::new( 'document-home', 'document', 'fixture-home', '/wp-admin/site-editor.php', 'document-save' );
		$component   = ContractLabBrowserSentinel::new( 'component-hero', 'component', 'fixture-home', '/wp-admin/edit.php?post_type=wp_block', 'component-save' );
		$pattern     = ContractLabBrowserSentinel::new( 'pattern-hero', 'pattern', 'fixture-home', '/wp-admin/edit.php?post_type=wp_block', 'pattern-save' );
		$global_asset = ContractLabBrowserSentinel::new( 'global-styles', 'global-asset', 'fixture-home', '/wp-admin/options-general.php', 'global-asset-save' );
		$catalog     = ContractLabBrowserSentinelCatalog::new( array( $document, $component, $pattern, $global_asset ) );

		self::assertSame( array( 'document', 'component', 'pattern', 'global-asset' ), array_map( static fn ( ContractLabBrowserSentinel $sentinel ): string => $sentinel->entity_type(), $catalog->all() ) );
		self::assertSame( $catalog->to_array(), ContractLabBrowserSentinelCatalog::from_array( $catalog->to_array() )->to_array() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate save action' );
		ContractLabBrowserSentinelCatalog::new(
			array(
				$document,
				$component,
				$pattern,
				$global_asset,
				ContractLabBrowserSentinel::new( 'pattern-copy', 'pattern', 'fixture-home', '/wp-admin/edit.php?post_type=wp_block', 'document-save' ),
			)
		);
	}

	public function test_runner_saves_then_reloads_and_reports_semantic_match(): void {
		$sentinel = ContractLabBrowserSentinel::new( 'document-home', 'document', 'fixture-home', '/wp-admin/site-editor.php', 'document-save' );
		$client   = new class( $this->observation( 'First' ) ) implements ContractLabBrowserSentinelClientInterface {

			/** @var array<int, string> */
			public array $calls = array();

			public function __construct( private readonly ContractLabFrontendObservation $observation ) {
			}

			public function capture( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				$this->calls[] = 'capture:' . $sentinel->logical_id();

				return $this->observation;
			}

			public function save( ContractLabBrowserSentinel $sentinel ): void {
				$this->calls[] = 'save:' . $sentinel->save_action_id();
			}

			public function reload( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				$this->calls[] = 'reload:' . $sentinel->fixture_id();

				return $this->observation;
			}
		};

		$result = ContractLabBrowserSentinelRunner::run( $sentinel, $client );

		self::assertSame( 'matched', $result->status() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertSame( array( 'capture:document-home', 'save:document-save', 'reload:fixture-home' ), $client->calls );
		self::assertNotNull( $result->before() );
		self::assertNotNull( $result->after() );
	}

	public function test_runner_retains_before_and_after_context_for_drift(): void {
		$sentinel = ContractLabBrowserSentinel::new( 'pattern-hero', 'pattern', 'fixture-pattern', '/wp-admin/edit.php?post_type=wp_block', 'pattern-save' );
		$before   = $this->observation( 'Before' );
		$after    = $this->observation( 'After' );
		$client   = new class( $before, $after ) implements ContractLabBrowserSentinelClientInterface {

			public function __construct(
				private readonly ContractLabFrontendObservation $before,
				private readonly ContractLabFrontendObservation $after
			) {
			}

			public function capture( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				return $this->before;
			}

			public function save( ContractLabBrowserSentinel $sentinel ): void {
			}

			public function reload( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				return $this->after;
			}
		};

		$result = ContractLabBrowserSentinelRunner::run( $sentinel, $client );

		self::assertSame( 'drift', $result->status() );
		self::assertFalse( $result->assertions_passed() );
		self::assertSame( $before->to_array(), $result->before()?->to_array() );
		self::assertSame( $after->to_array(), $result->after()?->to_array() );
		self::assertStringContainsString( 'pattern-hero', $result->failure_message() );
		self::assertStringContainsString( 'semantic drift', $result->failure_message() );
	}

	public function test_runner_distinguishes_unsupported_prerequisites_and_browser_infrastructure(): void {
		$sentinel = ContractLabBrowserSentinel::new( 'global-assets', 'global-asset', 'fixture-assets', '/wp-admin/options-general.php', 'global-asset-save' );
		$skipped_client = new class implements ContractLabBrowserSentinelClientInterface {
			public function capture( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				throw new ContractLabObservationException( 'unsupported', 'Global asset editor is not installed.' );
			}

			public function save( ContractLabBrowserSentinel $sentinel ): void {
			}

			public function reload( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				throw new LogicException( 'unreachable' );
			}
		};
		$skipped = ContractLabBrowserSentinelRunner::run( $sentinel, $skipped_client );

		self::assertSame( 'skipped', $skipped->status() );
		self::assertFalse( $skipped->assertions_passed() );

		$inconclusive_client = new class implements ContractLabBrowserSentinelClientInterface {
			public function capture( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				throw new ContractLabObservationException( 'unavailable', 'Browser transport disconnected.' );
			}

			public function save( ContractLabBrowserSentinel $sentinel ): void {
			}

			public function reload( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation {
				throw new LogicException( 'unreachable' );
			}
		};
		$inconclusive = ContractLabBrowserSentinelRunner::run( $sentinel, $inconclusive_client );

		self::assertSame( 'inconclusive', $inconclusive->status() );
		self::assertFalse( $inconclusive->assertions_passed() );
	}

	private function observation( string $content ): ContractLabFrontendObservation {
		return ContractLabFrontendObservation::observed(
			'fixture-home',
			'/contract-fixtures/home',
			200,
			array(
				array(
					'type'       => 'element',
					'name'       => 'main',
					'attributes' => array( 'data-contract-fixture' => 'fixture-home' ),
					'children'   => array( array( 'type' => 'text', 'value' => $content ) ),
				),
			),
			array(),
			array( array( 'capability' => 'dom', 'marker' => 'fixture-home', 'status' => 'observed' ) )
		);
	}
}
