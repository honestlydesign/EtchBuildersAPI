<?php
/**
 * Generates readable Authoring reference material from versioned authority.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\Support\Json;
use InvalidArgumentException;

/**
 * Creates a deterministic Markdown projection without becoming a second API.
 */
final class AuthoringReferenceMaterialGenerator {

	public const SCHEMA_VERSION = '1';

	private function __construct() {
	}

	public static function generate(
		AuthoringContractCatalog $contract,
		AuthoringRecipeCatalog $recipes,
		AuthoringNegativeRecipeCatalog $negative_recipes,
		AuthoringCompositeRecipeCatalog $composite_recipes,
		AuthoringReferenceMethodology $methodology,
		?ComponentContractCatalog $components = null
	): AuthoringReferenceMaterial {
		$components ??= ComponentContractCatalog::from_contracts();
		$recipe_records = self::recipe_records( $recipes, $negative_recipes, $composite_recipes );
		$diagnostics    = self::diagnostic_records( $negative_recipes );
		self::assert_catalog_links( $contract, $recipe_records, $diagnostics );

		$component_records = array_map(
			static fn ( ComponentContract $component ): array => $component->to_array(),
			$components->all()
		);
		$metadata = array(
			'reference_schema_version' => self::SCHEMA_VERSION,
			'catalog_schema_version'   => $contract->schema_version(),
			'catalog_contract_version' => $contract->contract_version(),
			'package_version'          => $contract->package_version(),
			'source_digest'            => $contract->source_digest(),
			'recipe_schema_version'    => '1',
			'recipe_versions'          => self::recipe_versions( $recipe_records ),
			'recipe_digest'            => hash( 'sha256', Json::encode( $recipe_records ) ),
			'component_digest'         => hash( 'sha256', Json::encode( $component_records ) ),
		);
		$capability_records = self::capability_records( $contract, $recipe_records, $diagnostics );
		$links              = self::links( $capability_records, $component_records, $recipe_records, $diagnostics );
		$markdown           = self::markdown( $metadata, $methodology, $capability_records, $component_records, $recipe_records, $diagnostics );

		return AuthoringReferenceMaterial::from_array(
			array(
				'metadata'     => $metadata,
				'methodology'  => $methodology->to_array(),
				'capabilities' => $capability_records,
				'components'   => $component_records,
				'recipes'      => $recipe_records,
				'diagnostics'  => $diagnostics,
				'links'        => $links,
				'markdown'     => $markdown,
			)
		);
	}

	public static function verify(
		array $projection,
		AuthoringContractCatalog $contract,
		AuthoringRecipeCatalog $recipes,
		AuthoringNegativeRecipeCatalog $negative_recipes,
		AuthoringCompositeRecipeCatalog $composite_recipes,
		AuthoringReferenceMethodology $methodology,
		?ComponentContractCatalog $components = null
	): AuthoringReferenceMaterial {
		$generated = self::generate( $contract, $recipes, $negative_recipes, $composite_recipes, $methodology, $components );
		AuthoringReferenceMaterial::from_array( $projection );
		if ( $generated->to_array() !== $projection ) {
			throw new InvalidArgumentException( 'Authoring reference material does not match generated source/catalog facts.' );
		}

		return $generated;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function recipe_records(
		AuthoringRecipeCatalog $recipes,
		AuthoringNegativeRecipeCatalog $negative_recipes,
		AuthoringCompositeRecipeCatalog $composite_recipes
	): array {
		$records = array();
		foreach ( $recipes->all() as $recipe ) {
			$records[] = self::recipe_record( $recipe->to_array(), 'positive', $recipe->id(), $recipe->version(), $recipe->capability_ids(), $recipe->prerequisite_ids(), $recipe->inputs() );
		}
		foreach ( $negative_recipes->all() as $recipe ) {
			$records[] = self::recipe_record( $recipe->to_array(), 'negative', $recipe->id(), $recipe->version(), $recipe->capability_ids(), $recipe->prerequisite_ids(), $recipe->inputs() );
		}
		foreach ( $composite_recipes->all() as $recipe ) {
			$records[] = self::recipe_record( $recipe->to_array(), 'composite', $recipe->id(), $recipe->version(), $recipe->capability_ids(), $recipe->prerequisite_ids(), $recipe->inputs() );
		}

		return $records;
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $capability_ids
	 * @param array<int, string>   $prerequisite_ids
	 * @param array<string, mixed> $inputs
	 * @return array<string, mixed>
	 */
	private static function recipe_record( array $record, string $kind, string $id, string $version, array $capability_ids, array $prerequisite_ids, array $inputs ): array {
		$record['kind']           = $kind;
		$record['capability_ids'] = $capability_ids;
		$record['prerequisite_ids'] = $prerequisite_ids;
		$record['inputs']         = $inputs;
		$record['snippet']        = sprintf( 'Authoring recipe `%s` (contract %s) is executable source authority; run it through the package catalog.', $id, $version );
		$record['anchor']         = 'recipe-' . self::anchor( $id );

		return $record;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function capability_records( AuthoringContractCatalog $contract, array $recipe_records, array $diagnostics ): array {
		$recipes_by_id = array();
		foreach ( $recipe_records as $recipe ) {
			$recipes_by_id[ $recipe['id'] ] = $recipe;
		}
		$diagnostics_by_code = array();
		foreach ( $diagnostics as $diagnostic ) {
			$diagnostics_by_code[ $diagnostic['code'] ] = $diagnostic;
		}
		$records = array();
		foreach ( $contract->capabilities() as $capability ) {
			$record = $capability->to_array();
			$record['interfaces'] = array_map(
				static fn ( AuthoringInterfaceFact $fact ): array => $fact->to_array(),
				$contract->interfaces_for( $capability->id() )
			);
			$record['recipe_links'] = array_map(
				static fn ( string $id ): array => array( 'id' => $id, 'anchor' => $recipes_by_id[ $id ]['anchor'] ),
				$capability->recipe_ids()
			);
			$record['diagnostic_links'] = array_map(
				static fn ( string $code ): array => array( 'id' => $code, 'anchor' => 'diagnostic-' . self::anchor( $code ) ),
				$capability->diagnostic_ids()
			);
			$record['anchor'] = 'intent-' . self::anchor( $capability->id() );
			$records[] = $record;
		}

		return $records;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function diagnostic_records( AuthoringNegativeRecipeCatalog $recipes ): array {
		$records = array();
		foreach ( $recipes->all() as $recipe ) {
			$expected = $recipe->expected_outcome()->diagnostic_record();
			$code = $expected['code'];
			if ( ! isset( $records[ $code ] ) ) {
				$records[ $code ] = array(
					'code'          => $expected['code'],
					'severity'       => $expected['severity'],
					'message'        => $expected['message'],
					'identity'       => $expected['identity'],
					'recipe_ids'     => array(),
					'capability_ids' => array(),
					'alternatives'   => array(),
					'anchor'         => 'diagnostic-' . self::anchor( $code ),
				);
			} elseif (
				$records[ $code ]['severity'] !== $expected['severity']
				|| $records[ $code ]['message'] !== $expected['message']
				|| $records[ $code ]['identity'] !== $expected['identity']
			) {
				throw new InvalidArgumentException( sprintf( 'Authoring reference has conflicting records for diagnostic code "%s".', $code ) );
			}
			if ( isset( $recipe->inputs()['remediation'] ) && is_string( $recipe->inputs()['remediation'] ) ) {
				$records[ $code ]['alternatives'][] = $recipe->inputs()['remediation'];
			}
			$records[ $code ]['recipe_ids'][] = $recipe->id();
			$records[ $code ]['capability_ids'] = array_values( array_unique( array_merge( $records[ $code ]['capability_ids'], $recipe->capability_ids() ) ) );
		}

		return array_values( $records );
	}

	private static function assert_catalog_links( AuthoringContractCatalog $contract, array $recipe_records, array $diagnostics ): void {
		$capability_ids = array_map(
			static fn ( AuthoringCapability $capability ): string => $capability->id(),
			$contract->capabilities()
		);
		$recipe_ids = array_column( $recipe_records, 'id' );
		$diagnostic_ids = array_column( $diagnostics, 'code' );
		foreach ( $recipe_records as $recipe ) {
			foreach ( array_merge( $recipe['capability_ids'], $recipe['prerequisite_ids'] ) as $capability_id ) {
				if ( ! in_array( $capability_id, $capability_ids, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring reference recipe capability ID "%s" is not present in the contract catalog.', $capability_id ) );
				}
			}
		}
		foreach ( $contract->capabilities() as $capability ) {
			foreach ( $capability->recipe_ids() as $recipe_id ) {
				if ( ! in_array( $recipe_id, $recipe_ids, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring reference recipe capability ID "%s" is not present in executable recipes.', $recipe_id ) );
				}
			}
			foreach ( $capability->diagnostic_ids() as $diagnostic_id ) {
				if ( ! in_array( $diagnostic_id, $diagnostic_ids, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Authoring reference diagnostic ID "%s" is not present in executable diagnostics.', $diagnostic_id ) );
				}
			}
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function recipe_versions( array $recipes ): array {
		$versions = array_values( array_unique( array_column( $recipes, 'version' ) ) );
		return $versions;
	}

	/**
	 * @return array<int, array{id: string, kind: string, target: string}>
	 */
	private static function links( array $capabilities, array $components, array $recipes, array $diagnostics ): array {
		$links = array();
		foreach ( $capabilities as $capability ) {
			$links[] = array( 'id' => $capability['id'], 'kind' => 'intent', 'target' => '#' . $capability['anchor'] );
		}
		foreach ( $components as $component ) {
			$links[] = array( 'id' => $component['component_key'], 'kind' => 'component', 'target' => '#component-' . self::anchor( $component['component_key'] ) );
		}
		foreach ( $recipes as $recipe ) {
			$links[] = array( 'id' => $recipe['id'], 'kind' => 'recipe', 'target' => '#' . $recipe['anchor'] );
		}
		foreach ( $diagnostics as $diagnostic ) {
			$links[] = array( 'id' => $diagnostic['code'], 'kind' => 'diagnostic', 'target' => '#' . $diagnostic['anchor'] );
		}

		return $links;
	}

	private static function markdown( array $metadata, AuthoringReferenceMethodology $methodology, array $capabilities, array $components, array $recipes, array $diagnostics ): string {
		$markdown = '# ' . $methodology->title() . "\n\n";
		$markdown .= "> Generated projection. The versioned catalog and executable recipes remain authoritative.\n\n";
		$markdown .= '- Package version: `' . $metadata['package_version'] . "`\n";
		$markdown .= '- Catalog source digest: `' . $metadata['source_digest'] . "`\n";
		$markdown .= '- Recipe digest: `' . $metadata['recipe_digest'] . "`\n";
		$markdown .= '- Component digest: `' . $metadata['component_digest'] . "`\n\n";
		$markdown .= $methodology->to_markdown();
		$markdown .= "## Intents\n\n";
		foreach ( $capabilities as $capability ) {
			$markdown .= '<a id="' . $capability['anchor'] . '"></a>' . "\n### " . $capability['id'] . "\n\n";
			$markdown .= 'Status: `' . $capability['status'] . "`\n\n";
			if ( array() !== $capability['interfaces'] ) {
				$markdown .= "#### Interfaces\n\n";
				foreach ( $capability['interfaces'] as $interface ) {
					$markdown .= '- `' . $interface['class'] . '::' . $interface['method'] . '` — contract `' . $interface['contract_version'] . "`\n";
				}
				$markdown .= "\n";
			}
			if ( array() !== $capability['recipe_links'] ) {
				$markdown .= 'Recipes: ' . implode(
					', ',
					array_map(
						static fn ( array $link ): string => '[' . $link['id'] . '](#' . $link['anchor'] . ')',
						$capability['recipe_links']
					)
				) . "\n\n";
			}
			if ( array() !== $capability['diagnostic_links'] ) {
				$markdown .= 'Diagnostics: ' . implode(
					', ',
					array_map(
						static fn ( array $link ): string => '[' . $link['id'] . '](#' . $link['anchor'] . ')',
						$capability['diagnostic_links']
					)
				) . "\n\n";
			}
		}
		$markdown .= "## Components\n\n";
		foreach ( $components as $component ) {
			$anchor = 'component-' . self::anchor( $component['component_key'] );
			$markdown .= '<a id="' . $anchor . '"></a>' . "\n### " . $component['component_key'] . "\n\n";
			$markdown .= 'Status: `' . $component['status'] . '`; slots: `' . implode( '`, `', $component['slots'] ) . "`\n\n";
			$markdown .= 'Class-property paths: `' . implode( '`, `', $component['class_property_paths'] ) . "`\n\n";
		}
		$markdown .= "## Recipes\n\n";
		foreach ( $recipes as $recipe ) {
			$markdown .= '<a id="' . $recipe['anchor'] . '"></a>' . "\n### " . $recipe['id'] . "\n\n";
			$markdown .= $recipe['snippet'] . "\n\n";
		}
		$markdown .= "## Diagnostics\n\n";
		foreach ( $diagnostics as $diagnostic ) {
			$markdown .= '<a id="' . $diagnostic['anchor'] . '"></a>' . "\n### " . $diagnostic['code'] . "\n\n";
			$markdown .= $diagnostic['message'] . "\n\n";
		}

		return $markdown;
	}

	private static function anchor( string $value ): string {
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ?? '', '-' );
	}
}
