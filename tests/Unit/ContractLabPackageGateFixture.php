<?php

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabPackageGateIdentity;
use HonestlyDesign\EtchBuilders\ContractLabPackageGateEvidence;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelResult;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelRunner;
use HonestlyDesign\EtchBuilders\ContractLabFrontendFixture;
use HonestlyDesign\EtchBuilders\ContractLabFrontendHttpResponse;
use HonestlyDesign\EtchBuilders\ContractLabFrontendObservation;
use HonestlyDesign\EtchBuilders\ContractLabFrontendProbe;
use HonestlyDesign\EtchBuilders\ContractLabFrontendProbeResult;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarker;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarkerResult;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarkerRunner;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabBrowserSentinelClientInterface;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabFrontendHttpClientInterface;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabJavascriptMarkerClientInterface;
use RuntimeException;

/**
 * Creates a private clean Git fixture for package-gate identity tests.
 */
final class ContractLabPackageGateFixture {

	private function __construct( private readonly string $root ) {
	}

	public static function create(): self {
		$root = sys_get_temp_dir() . '/contract-lab-package-gate-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $root . '/artifact', 0700, true ) && ! is_dir( $root . '/artifact' ) ) {
			throw new RuntimeException( 'Could not create the package-gate test fixture.' );
		}
		file_put_contents( $root . '/artifact/etch.php', "<?php\nreturn 'fixture';\n" );
		self::run( 'init -q', $root );
		self::run( 'config user.email contract-lab@example.test', $root );
		self::run( 'config user.name ContractLab', $root );
		self::run( 'add artifact/etch.php', $root );
		self::run( 'commit -qm fixture', $root );

		return new self( $root );
	}

	public function identity(): ContractLabPackageGateIdentity {
		return ContractLabPackageGateIdentity::inspect( $this->root, $this->root . '/artifact' );
	}

	public function diverge(): void {
		file_put_contents( $this->root . '/artifact/etch.php', "<?php\nreturn 'different-fixture';\n" );
		self::run( 'add artifact/etch.php', $this->root );
		self::run( 'commit -qm diverged-fixture', $this->root );
	}

	public function dirty(): void {
		file_put_contents( $this->root . '/untracked.txt', "dirty\n" );
	}

	public function evidence( string $gate_id, ?int $exit_code = 0, string $output = 'unit-test gate output', string $summary = 'Unit test execution envelope.' ): ContractLabPackageGateEvidence {
		$identity = $this->identity();
		$factory = \Closure::bind(
			static function (
				string $gate_id,
				string $status,
				ContractLabPackageGateIdentity $identity,
				string $source_revision,
				string $artifact_fingerprint,
				string $command,
				?int $exit_code,
				string $output_digest,
				string $summary
			): ContractLabPackageGateEvidence {
				return new ContractLabPackageGateEvidence( $gate_id, $status, $identity, $source_revision, $artifact_fingerprint, $command, $exit_code, $output_digest, $summary );
			},
			null,
			ContractLabPackageGateEvidence::class
		);
		$evidence = $factory(
			$gate_id,
			null === $exit_code ? 'inconclusive' : ( 0 === $exit_code ? 'passed' : 'failed' ),
			$identity,
			$identity->source_revision(),
			$identity->artifact_fingerprint(),
			ContractLabPackageGateEvidence::command( $gate_id ),
			$exit_code,
			hash( 'sha256', null === $exit_code ? '' : $output ),
			$summary
		);

		return $evidence;
	}

	public function close(): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $this->root );
	}

	private static function run( string $arguments, string $working_directory ): void {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( 'git ' . $arguments, $descriptors, $pipes, $working_directory );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start Git for the package-gate test fixture.' );
		}
		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'Could not initialize the package-gate test fixture.' );
		}
	}
}

/**
 * Produces test observations through the same executable runner boundaries as
 * the maintainer gate. Tests may vary the adapter output without minting a
 * production provenance receipt themselves.
 */
final class ContractLabExecutedEvidenceFixture {

	public static function frontend( bool $changed = false ): ContractLabFrontendProbeResult {
		$fixture = ContractLabFrontendFixture::new(
			'marketing-home',
			'/contract-fixtures/marketing-home/',
			array(
				'dom'        => 'marketing-home',
				'stylesheet' => '.marketing-card',
				'class'      => 'marketing-card',
				'slot'       => 'headline',
				'loop'       => 'item-1',
				'dynamic'    => 'title',
			)
		);
		$tag = $changed ? 'strong' : 'h1';
		$client = new class( $tag ) implements ContractLabFrontendHttpClientInterface {
			public function __construct( private readonly string $tag ) {
			}

			public function get( string $path ): ContractLabFrontendHttpResponse {
				return ContractLabFrontendHttpResponse::new(
					200,
					sprintf(
						'<main data-contract-fixture="marketing-home" class="marketing-card"><%1$s data-contract-slot="headline" data-contract-loop="item-1" data-contract-dynamic="title">Contract Lab Marketing</%1$s><style>.marketing-card { color: rgb(17 24 39); }</style></main>',
						$this->tag
					),
					array( 'content-type' => 'text/html' )
				);
			}
		};

		return ContractLabFrontendProbe::run( $fixture, $client );
	}

	public static function browser( ContractLabBrowserSentinel $sentinel, ContractLabFrontendObservation $before, ContractLabFrontendObservation $after ): ContractLabBrowserSentinelResult {
		$client = new class( $before, $after ) implements ContractLabBrowserSentinelClientInterface {
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

		return ContractLabBrowserSentinelRunner::run( $sentinel, $client );
	}

	public static function javascript( ?string $value = 'true' ): ContractLabJavascriptMarkerResult {
		$marker = ContractLabJavascriptMarker::marketing_reference();
		$client = new class( $value ) implements ContractLabJavascriptMarkerClientInterface {
			public function __construct( private readonly ?string $value ) {
			}

			public function read_marker( ContractLabJavascriptMarker $marker ): ?string {
				return $this->value;
			}
		};

		return ContractLabJavascriptMarkerRunner::run( $marker, $client );
	}
}
