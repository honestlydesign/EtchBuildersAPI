<?php
/**
 * Contract Lab package gate evidence tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabPackageGateEvidence;
use HonestlyDesign\EtchBuilders\ContractLabPackageGateSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves that package gate identity is derived from an execution envelope.
 */
final class ContractLabPackageGateEvidenceTest extends TestCase {
	private ContractLabPackageGateFixture $fixture;

	protected function setUp(): void {
		$this->fixture = ContractLabPackageGateFixture::create();
	}

	protected function tearDown(): void {
		$this->fixture->close();
	}

	public function test_execution_envelope_derives_status_and_output_digest(): void {
		$evidence = $this->fixture->evidence( 'package', 0, 'actual process output', 'Canonical package command exited with code 0.' );

		self::assertTrue( $evidence->is_passed() );
		self::assertSame( 0, $evidence->exit_code() );
		self::assertSame( hash( 'sha256', 'actual process output' ), $evidence->output_digest() );
		self::assertSame( 'composer test', $evidence->command_line() );
	}

	public function test_nonzero_process_exit_is_failed_even_when_summary_is_positive(): void {
		$evidence = $this->fixture->evidence( 'source', 1, 'failure output', 'All checks passed.' );

		self::assertSame( 'failed', $evidence->status() );
		self::assertFalse( $evidence->is_passed() );
	}

	public function test_package_gate_commands_are_canonical_and_closed(): void {
		self::assertSame( 'composer test -- --filter ContractLab', ContractLabPackageGateEvidence::command( 'catalog' ) );

		$this->expectException( InvalidArgumentException::class );
		ContractLabPackageGateEvidence::command( 'arbitrary' );
	}

	public function test_gate_set_rejects_mixed_candidate_identity(): void {
		$evidence = array();
		$other_fixture = ContractLabPackageGateFixture::create();
		$other_fixture->diverge();
		foreach ( array( 'package', 'source', 'catalog', 'recipe' ) as $gate_id ) {
			$evidence[] = ( 'recipe' === $gate_id ? $other_fixture : $this->fixture )->evidence( $gate_id, null, '', 'Infrastructure unavailable.' );
		}
		$other_fixture->close();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'one exact source revision' );

		ContractLabPackageGateSet::from_evidence( $evidence );
	}

	public function test_identity_rejects_a_dirty_checkout_before_any_gate_runs(): void {
		$this->fixture->dirty();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'clean Git repository' );

		$this->fixture->identity();
	}

	public function test_identity_detects_artifact_or_head_changes_after_capture(): void {
		$identity = $this->fixture->identity();
		$this->fixture->diverge();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'identity changed' );

		$identity->assert_unchanged();
	}
}
