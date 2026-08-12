<?php
/**
 * Read-only Authoring Query commands.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractLookup;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractStatus;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use InvalidArgumentException;

/**
 * Exposes the exact version-matched authoring surface to local agents.
 *
 * The query is intentionally assembled from already-validated catalogs. It
 * does not enumerate PHP, inspect Etch internals, or manufacture snippets.
 */
final class AuthoringQuery {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private readonly array $recipes_by_id;

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private readonly array $diagnostics_by_code;

	private function __construct(
		private readonly AuthoringContractCatalog $contract_catalog,
		private readonly ComponentContractCatalogProviderInterface $component_contracts,
		array $recipes_by_id,
		array $diagnostics_by_code
	) {
		$this->recipes_by_id      = $recipes_by_id;
		$this->diagnostics_by_code = $diagnostics_by_code;
	}

	/**
	 * Build a query over one version-matched catalog set.
	 *
	 * @throws InvalidArgumentException When catalog cross-references cannot be
	 *                                  resolved without guessing.
	 */
	public static function from_catalogs(
		AuthoringContractCatalog $contract_catalog,
		AuthoringRecipeCatalog $recipes,
		AuthoringNegativeRecipeCatalog $negative_recipes,
		AuthoringCompositeRecipeCatalog $composite_recipes,
		?ComponentContractCatalogProviderInterface $component_contracts = null
	): self {
		$recipes_by_id = array();
		foreach ( $recipes->all() as $recipe ) {
			self::add_recipe( $recipes_by_id, self::positive_recipe_record( $recipe ) );
		}
		foreach ( $negative_recipes->all() as $recipe ) {
			self::add_recipe( $recipes_by_id, self::negative_recipe_record( $recipe ) );
		}
		foreach ( $composite_recipes->all() as $recipe ) {
			self::add_recipe( $recipes_by_id, self::composite_recipe_record( $recipe ) );
		}

		$diagnostics_by_code = self::diagnostic_records( $negative_recipes );
		foreach ( $contract_catalog->capabilities() as $capability ) {
			if ( ! $capability->status()->is_admitted() ) {
				continue;
			}
			foreach ( $capability->recipe_ids() as $recipe_id ) {
				if ( ! isset( $recipes_by_id[ $recipe_id ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring Query cannot resolve recipe ID "%s" for capability "%s".', $recipe_id, $capability->id() )
					);
				}
			}
			foreach ( $capability->diagnostic_ids() as $diagnostic_id ) {
				if ( ! isset( $diagnostics_by_code[ $diagnostic_id ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring Query cannot resolve diagnostic ID "%s" for capability "%s".', $diagnostic_id, $capability->id() )
					);
				}
			}
		}

		$component_contracts ??= InMemoryComponentContractCatalogProvider::empty();
		foreach ( $component_contracts->catalog()->all() as $contract ) {
			if ( ComponentContractStatus::SUPPORTED !== $contract->status() ) {
				continue;
			}
			foreach ( $contract->recipe_ids() as $recipe_id ) {
				if ( ! isset( $recipes_by_id[ $recipe_id ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Authoring Query cannot resolve recipe ID "%s" for component "%s".', $recipe_id, $contract->component_key() )
					);
				}
			}
		}

		return new self( $contract_catalog, $component_contracts, $recipes_by_id, $diagnostics_by_code );
	}

	/**
	 * Execute `builder:help --intent capability-id`.
	 */
	public function help( string $intent ): AuthoringQueryResult {
		return $this->intent_query( $intent );
	}

	/**
	 * Alias for integrations that prefer semantic method names.
	 */
	public function for_intent( string $intent ): AuthoringQueryResult {
		return $this->help( $intent );
	}

	/**
	 * Execute the explicit checked-escape query.
	 */
	public function escape( string $intent ): AuthoringQueryResult {
		try {
			$capability = $this->contract_catalog->capability( $intent );
		} catch ( InvalidArgumentException ) {
			return $this->help( $intent );
		}

		if ( ! $capability->status()->is_checked_escape() ) {
			return $this->help( $intent );
		}

		return $this->supported_intent_result( $capability, 'checked_escape', 'builder:escape' );
	}

	/**
	 * Execute `builder:component --key component-key`.
	 */
	public function component( string $component_key ): AuthoringQueryResult {
		$catalog = $this->component_contracts->catalog();
		if ( ! $catalog->has( $component_key ) ) {
			$alternatives = $this->component_alternatives();
			return AuthoringQueryResult::from_record(
				array(
					'command'      => 'builder:component',
					'query'        => 'component',
					'status'       => 'unknown',
					'catalog'      => $this->catalog_metadata(),
					'component_key' => $component_key,
					'alternatives' => $alternatives,
					'error'        => array(
						'code'          => 'AUTHORING_QUERY_UNKNOWN_COMPONENT',
						'message'       => sprintf( 'Unknown component key "%s". Query an exact supported key.', $component_key ),
						'alternatives'  => $alternatives,
					),
				)
			);
		}

		$lookup    = ComponentContractLookup::from_provider( $this->component_contracts )->for_key( $component_key );
		$component = $lookup->to_array();
		if ( ComponentContractStatus::SUPPORTED !== $lookup->status() ) {
			$alternatives = $this->component_alternatives();
			return AuthoringQueryResult::from_record(
				array(
					'command'       => 'builder:component',
					'query'         => 'component',
					'status'        => $lookup->status()->value,
					'catalog'       => $this->catalog_metadata(),
					'component_key' => $component_key,
					'alternatives'  => $alternatives,
					'error'         => array(
						'code'         => 'AUTHORING_QUERY_COMPONENT_PENDING',
						'message'      => sprintf( 'Component "%s" is pending admission; exact props, slots, and class-property paths are unavailable.', $component_key ),
						'alternatives' => $alternatives,
					),
				)
			);
		}

		return AuthoringQueryResult::from_record(
			array(
				'command'       => 'builder:component',
				'query'         => 'component',
				'status'        => 'supported',
				'catalog'       => $this->catalog_metadata(),
				'component_key' => $component_key,
				'component'     => $component,
				'alternatives'  => array(),
				'error'         => null,
			)
		);
	}

	/**
	 * Alias for integrations that prefer semantic method names.
	 */
	public function for_component( string $component_key ): AuthoringQueryResult {
		return $this->component( $component_key );
	}

	/**
	 * Execute `builder:explain diagnostic-code`.
	 */
	public function explain( string $diagnostic_code ): AuthoringQueryResult {
		if ( ! isset( $this->diagnostics_by_code[ $diagnostic_code ] ) ) {
			$alternatives = $this->diagnostic_alternatives();
			return AuthoringQueryResult::from_record(
				array(
					'command'      => 'builder:explain',
					'query'        => 'diagnostic',
					'status'       => 'unknown',
					'catalog'      => $this->catalog_metadata(),
					'diagnostic_code' => $diagnostic_code,
					'alternatives' => $alternatives,
					'error'        => array(
						'code'         => 'AUTHORING_QUERY_UNKNOWN_DIAGNOSTIC',
						'message'      => sprintf( 'Unknown diagnostic code "%s". Query an exact catalog diagnostic.', $diagnostic_code ),
						'alternatives' => $alternatives,
					),
				)
			);
		}

		return AuthoringQueryResult::from_record(
			array(
				'command'      => 'builder:explain',
				'query'        => 'diagnostic',
				'status'       => 'known',
				'catalog'      => $this->catalog_metadata(),
				'diagnostic'   => $this->diagnostics_by_code[ $diagnostic_code ],
				'alternatives' => array(),
				'error'        => null,
			)
		);
	}

	/**
	 * Alias for integrations that prefer semantic method names.
	 */
	public function for_diagnostic( string $diagnostic_code ): AuthoringQueryResult {
		return $this->explain( $diagnostic_code );
	}

	/**
	 * @param array<string, array<string, mixed>> $recipes_by_id
	 * @param array<string, mixed>                $recipe
	 */
	private static function add_recipe( array &$recipes_by_id, array $recipe ): void {
		$id = $recipe['id'];
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Authoring Query recipe records require a string ID.' );
		}
		if ( isset( $recipes_by_id[ $id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring Query has duplicate recipe ID "%s" across catalogs.', $id ) );
		}

		$recipes_by_id[ $id ] = $recipe;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function positive_recipe_record( AuthoringCapabilityRecipeInterface $recipe ): array {
		$record = $recipe->to_array();
		$record['kind']  = 'positive';
		$record['class'] = $recipe::class;

		return $record;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function negative_recipe_record( AuthoringNegativeRecipeInterface $recipe ): array {
		$record = $recipe->to_array();
		$record['kind']  = 'negative';
		$record['class'] = $recipe::class;

		return $record;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function composite_recipe_record( AuthoringCompositeRecipeInterface $recipe ): array {
		$record = $recipe->to_array();
		$record['kind']  = 'composite';
		$record['class'] = $recipe::class;

		return $record;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function diagnostic_records( AuthoringNegativeRecipeCatalog $recipes ): array {
		$diagnostics = array();
		foreach ( $recipes->all() as $recipe ) {
			$expected = $recipe->expected_outcome()->diagnostic_record();
			$code     = $expected['code'];
			$inputs   = $recipe->inputs();
			if ( isset( $diagnostics[ $code ] ) ) {
				$existing = $diagnostics[ $code ];
				if ( $existing['severity'] !== $expected['severity'] || $existing['message'] !== $expected['message'] || $existing['identity'] !== $expected['identity'] ) {
					throw new InvalidArgumentException( sprintf( 'Authoring Query has conflicting records for diagnostic code "%s".', $code ) );
				}
			} else {
				$diagnostics[ $code ] = array(
					'code'          => $expected['code'],
					'severity'       => $expected['severity'],
					'message'        => $expected['message'],
					'identity'       => $expected['identity'],
					'alternatives'   => array(),
					'recipe_ids'     => array(),
					'capability_ids' => array(),
				);
			}

			if ( isset( $inputs['remediation'] ) && is_string( $inputs['remediation'] ) && ! in_array( $inputs['remediation'], $diagnostics[ $code ]['alternatives'], true ) ) {
				$diagnostics[ $code ]['alternatives'][] = $inputs['remediation'];
			}
			if ( ! in_array( $recipe->id(), $diagnostics[ $code ]['recipe_ids'], true ) ) {
				$diagnostics[ $code ]['recipe_ids'][] = $recipe->id();
			}
			foreach ( $recipe->capability_ids() as $capability_id ) {
				if ( ! in_array( $capability_id, $diagnostics[ $code ]['capability_ids'], true ) ) {
					$diagnostics[ $code ]['capability_ids'][] = $capability_id;
				}
			}
		}

		return $diagnostics;
	}

	private function intent_query( string $intent ): AuthoringQueryResult {
		try {
			$capability = $this->contract_catalog->capability( $intent );
		} catch ( InvalidArgumentException ) {
			$alternatives = $this->intent_alternatives();
			return AuthoringQueryResult::from_record(
				array(
					'command'      => 'builder:help',
					'query'        => 'intent',
					'status'       => 'unknown',
					'catalog'      => $this->catalog_metadata(),
					'intent'       => $intent,
					'alternatives' => $alternatives,
					'error'        => array(
						'code'         => 'AUTHORING_QUERY_UNKNOWN_INTENT',
						'message'      => sprintf( 'Unknown authoring intent "%s". Query an exact supported intent.', $intent ),
						'alternatives' => $alternatives,
					),
				)
			);
		}

		$status = $capability->status();
		if ( $status->is_supported() ) {
			return $this->supported_intent_result(
				$capability,
				$status->value,
				'builder:help'
			);
		}

		$alternatives = array();
		if ( $status->is_checked_escape() ) {
			$alternatives[] = array(
				'kind'    => 'escape',
				'id'      => $intent,
				'command' => 'builder:escape --intent ' . $intent,
			);
		}
		$alternatives = array_merge( $alternatives, $this->intent_alternatives() );
		$error_code = $status->is_pending()
			? 'AUTHORING_QUERY_INTENT_PENDING'
			: ( $status->is_checked_escape() ? 'AUTHORING_QUERY_ESCAPE_REQUIRED' : 'AUTHORING_QUERY_INTENT_UNSUPPORTED' );
		$message = $status->is_checked_escape()
			? sprintf( 'Authoring intent "%s" is available only through an explicit checked escape query.', $intent )
			: sprintf( 'Authoring intent "%s" is %s: %s', $intent, $status->value, $capability->status_reason() );

		return AuthoringQueryResult::from_record(
			array(
				'command'      => 'builder:help',
				'query'        => 'intent',
				'status'       => $status->value,
				'catalog'      => $this->catalog_metadata(),
				'intent'       => $intent,
				'alternatives' => $alternatives,
				'error'        => array(
					'code'         => $error_code,
					'message'      => $message,
					'alternatives' => $alternatives,
				),
			)
		);
	}

	private function supported_intent_result( AuthoringCapability $capability, string $status, string $command ): AuthoringQueryResult {
		$recipes = array();
		foreach ( $capability->recipe_ids() as $recipe_id ) {
			$recipes[] = $this->recipes_by_id[ $recipe_id ];
		}
		$diagnostics = array();
		foreach ( $capability->diagnostic_ids() as $diagnostic_id ) {
			$diagnostics[] = $this->diagnostics_by_code[ $diagnostic_id ];
		}

		return AuthoringQueryResult::from_record(
			array(
				'command'       => $command,
				'query'         => 'intent',
				'status'        => $status,
				'catalog'        => $this->catalog_metadata(),
				'intent'         => $capability->id(),
				'capability'     => $capability->to_array(),
				'interfaces'     => array_map(
					static fn ( AuthoringInterfaceFact $fact ): array => $fact->to_array(),
					$this->contract_catalog->interfaces_for( $capability->id() )
				),
				'prerequisites'  => $capability->prerequisite_ids(),
				'recipes'        => $recipes,
				'diagnostics'    => $diagnostics,
				'alternatives'   => array(),
				'error'          => null,
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function catalog_metadata(): array {
		return array(
			'schema_version'   => $this->contract_catalog->schema_version(),
			'contract_version' => $this->contract_catalog->contract_version(),
			'package_version'  => $this->contract_catalog->package_version(),
			'source_digest'    => $this->contract_catalog->source_digest(),
		);
	}

	/**
	 * @return array<int, array{kind: string, id: string, command: string}>
	 */
	private function intent_alternatives(): array {
		$alternatives = array();
		foreach ( $this->contract_catalog->capabilities() as $capability ) {
			if ( ! $capability->status()->is_supported() ) {
				continue;
			}
			$alternatives[] = array(
				'kind'    => 'intent',
				'id'      => $capability->id(),
				'command' => 'builder:help --intent ' . $capability->id(),
			);
		}

		return $alternatives;
	}

	/**
	 * @return array<int, array{kind: string, id: string, command: string}>
	 */
	private function component_alternatives(): array {
		$alternatives = array();
		foreach ( $this->component_contracts->catalog()->all() as $contract ) {
			if ( ComponentContractStatus::SUPPORTED !== $contract->status() ) {
				continue;
			}
			$alternatives[] = array(
				'kind'    => 'component',
				'id'      => $contract->component_key(),
				'command' => 'builder:component --key ' . $contract->component_key(),
			);
		}

		return $alternatives;
	}

	/**
	 * @return array<int, array{kind: string, id: string, command: string}>
	 */
	private function diagnostic_alternatives(): array {
		$alternatives = array();
		foreach ( array_keys( $this->diagnostics_by_code ) as $code ) {
			$alternatives[] = array(
				'kind'    => 'diagnostic',
				'id'      => $code,
				'command' => 'builder:explain ' . $code,
			);
		}

		return $alternatives;
	}
}
