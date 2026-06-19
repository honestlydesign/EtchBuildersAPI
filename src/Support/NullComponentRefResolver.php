<?php
/**
 * Default ComponentRefResolverInterface implementation: resolves nothing.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;

/**
 * Returns 0 / null for all lookups unless overridden by a concrete adapter.
 *
 * Tests that exercise ComponentBlock::ref_by_key() should pre-populate via
 * Environment::configure() with a stub that returns known refs.
 */
final class NullComponentRefResolver implements ComponentRefResolverInterface {

	/**
	 * {@inheritdoc}
	 */
	public function ref_by_key( string $component_key ): int {
		return 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function key_by_ref( int $ref ): ?string {
		return null;
	}
}
