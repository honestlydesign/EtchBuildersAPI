<?php
/**
 * NullMode tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use PHPUnit\Framework\TestCase;

/**
 * Verifies NullMode honors ModeProviderInterface and defaults to non-dev.
 */
final class NullModeTest extends TestCase {

	public function test_implements_mode_provider_interface(): void {
		self::assertInstanceOf( ModeProviderInterface::class, new NullMode() );
	}

	public function test_defaults_to_non_dev_mode(): void {
		self::assertFalse( ( new NullMode() )->is_dev_mode() );
	}
}
