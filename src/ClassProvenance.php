<?php
/**
 * Supported class-token provenance kinds.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Declares who owns and emits a class token without relying on naming guesses.
 */
enum ClassProvenance: string {

	case SITE_PRESENTATION = 'site_presentation';

	case PROJECT_UTILITY = 'project_utility';

	case EXTERNAL_FRAMEWORK = 'external_framework';

	case RUNTIME_STATE = 'runtime_state';
}
