<?php
/**
 * Signals that active persisted style identity cannot be trusted for linkage.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Exceptions;

use InvalidArgumentException;

/**
 * A fail-closed storage identity error that block linkage must not suppress.
 */
final class PersistedStyleIdentityException extends InvalidArgumentException {
}
