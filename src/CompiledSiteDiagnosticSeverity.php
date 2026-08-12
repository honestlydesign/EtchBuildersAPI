<?php
/**
 * Diagnostic severities carried by a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Stable diagnostic severity values.
 */
enum CompiledSiteDiagnosticSeverity: string {

	case INFO = 'info';

	case WARNING = 'warning';

	case ERROR = 'error';
}
