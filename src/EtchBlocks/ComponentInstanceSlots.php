<?php
/**
 * Schema-backed component instance slot assignments.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use InvalidArgumentException;

/**
 * Validates and compiles exact component slot assignments to Etch's wire.
 */
final class ComponentInstanceSlots {

	private readonly string $component_key;

	private readonly ComponentContract $contract;

	/**
	 * Ordered slot records. Names remain values so PHP cannot coerce numeric-looking
	 * strings into integer array keys.
	 *
	 * @var array<int, array{name: string, children: array<int, Block>}>
	 */
	private array $assignments = array();

	/**
	 * Exact assigned names retained as string values for strict duplicate checks.
	 *
	 * @var array<int, string>
	 */
	private array $assigned_names = array();

	public static function for_component(
		string $component_key,
		ComponentContractCatalogProviderInterface $provider
	): self {
		return new self( $provider->catalog()->contract( $component_key ) );
	}

	private function __construct( ComponentContract $contract ) {
		$this->contract      = $contract;
		$this->component_key = $contract->component_key();
	}

	public function component_key(): string {
		return $this->component_key;
	}

	/**
	 * Assign a filled slot. At least one child is required by the signature.
	 */
	public function set( string $name, Block $first_child, Block ...$additional_children ): void {
		$name = $this->require_unassigned_slot( $name );

		$children = array( $first_child->detached_copy() );
		foreach ( $additional_children as $additional_child ) {
			$children[] = $additional_child->detached_copy();
		}

		foreach ( $children as $child ) {
			$this->assert_content_block( $name, $child );
		}

		$this->assignments[]  = array(
			'name'     => $name,
			'children' => $children,
		);
		$this->assigned_names[] = $name;
	}

	/**
	 * Assign an explicit empty slot override.
	 */
	public function set_empty( string $name ): void {
		$name = $this->require_unassigned_slot( $name );

		$this->assignments[]  = array(
			'name'     => $name,
			'children' => array(),
		);
		$this->assigned_names[] = $name;
	}

	public function has_assignments(): bool {
		return array() !== $this->assignments;
	}

	/**
	 * Compile ordered assignments to direct etch/slot-content children.
	 *
	 * @return array<int, Block>
	 */
	public function to_blocks(): array {
		$blocks = array();

		foreach ( $this->assignments as $assignment ) {
			$blocks[] = SlotContentBlock::new()
				->name( $assignment['name'] )
				->children( $assignment['children'] )
				->to_block();
		}

		return $blocks;
	}

	/**
	 * Require an exact component slot that has not already been assigned.
	 */
	private function require_unassigned_slot( string $name ): string {
		$name = $this->contract->require_slot( $name );

		if ( in_array( $name, $this->assigned_names, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" slot "%s" already has a schema-backed assignment.',
					$this->component_key,
					$name
				)
			);
		}

		return $name;
	}

	private function assert_content_block( string $slot_name, Block $child ): void {
		if ( $child->contains_named_outside_boundary( array( 'slot-content', 'slot-placeholder' ), 'component' ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Component "%s" slot "%s" cannot use a raw slot-content or slot-placeholder boundary as Golden Path content.',
					$this->component_key,
					$slot_name
				)
			);
		}
	}
}
