<?php
/**
 * Semantic lookup result for one component authoring contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyInstanceValueKind;

/**
 * Exposes exact authoring facts without exposing Etch wire implementation.
 */
final class ComponentContractLookupResult {

	/**
	 * @param array<int, array<string, mixed>> $property_paths Exact path records.
	 * @param array<int, string>               $slots Exact slot names.
	 * @param array<int, string>               $class_property_paths Exact class paths.
	 * @param array<int, string>               $recipe_ids Stable recipe IDs.
	 */
	private function __construct(
		private readonly string $component_key,
		private readonly ComponentContractStatus $status,
		private readonly array $property_paths,
		private readonly array $slots,
		private readonly array $class_property_paths,
		private readonly array $recipe_ids
	) {
	}

	public static function from_contract( ComponentContract $contract ): self {
		$property_paths = array();
		foreach ( $contract->properties() as $property ) {
			$property_contract = $property->property_contract();
			$type              = array( 'primitive' => $property_contract->primitive()->value );
			if ( null !== $property_contract->specialized() ) {
				$type['specialized'] = $property_contract->specialized();
			}

			$property_paths[] = array(
				'declaration_path' => $property->declaration_path(),
				'value_path'       => $property->value_path(),
				'type'             => $type,
				'value_kinds'      => array_map(
					static fn ( PropertyInstanceValueKind $kind ): string => $kind->value,
					$property_contract->instance_value_kinds()
				),
				'status'           => $property_contract->status()->value,
			);
		}

		return new self(
			$contract->component_key(),
			$contract->status(),
			$property_paths,
			$contract->slots(),
			$contract->class_property_paths(),
			$contract->recipe_ids()
		);
	}

	public function component_key(): string {
		return $this->component_key;
	}

	public function status(): ComponentContractStatus {
		return $this->status;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function property_paths(): array {
		return $this->property_paths;
	}

	/**
	 * @return array<int, string>
	 */
	public function slots(): array {
		return $this->slots;
	}

	/**
	 * @return array<int, string>
	 */
	public function class_property_paths(): array {
		return $this->class_property_paths;
	}

	/**
	 * @return array<int, string>
	 */
	public function recipe_ids(): array {
		return $this->recipe_ids;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'component_key'        => $this->component_key,
			'status'               => $this->status->value,
			'property_paths'       => $this->property_paths,
			'slots'                => $this->slots,
			'class_property_paths' => $this->class_property_paths,
			'recipe_ids'           => $this->recipe_ids,
		);
	}
}
