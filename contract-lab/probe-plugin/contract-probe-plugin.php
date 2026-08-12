<?php
/**
 * Plugin Name: Etch Builders Contract Probe Plugin
 * Description: Maintainer-only normalized Contract Lab observation endpoint.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 *
 * @package EtchBuildersContractProbe
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/ContractProbePlugin.php';

\EtchBuildersContractProbe\ContractProbePlugin::register();
