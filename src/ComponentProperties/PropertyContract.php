<?php
/**
 * One immutable Etch property authoring contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use InvalidArgumentException;

/**
 * Relates an exact Etch type pair to its supported definition and instance value.
 */
final class PropertyContract {

	private readonly PropertyPrimitive $primitive;

	private readonly ?string $specialized;

	/**
	 * @var class-string<ComponentPropertyInterface>
	 */
	private readonly string $definition_builder;

	/**
	 * @var array<int, PropertyInstanceValueKind>
	 */
	private readonly array $instance_value_kinds;

	private readonly PropertyWireShape $wire_shape;

	private readonly PropertyContractStatus $status;

	/**
	 * Constructor.
	 *
	 * @param class-string<ComponentPropertyInterface> $definition_builder Supported typed definition builder.
	 * @param array<int, PropertyInstanceValueKind>     $instance_value_kinds Allowed semantic instance values.
	 */
	public function __construct(
		PropertyPrimitive $primitive,
		?string $specialized,
		string $definition_builder,
		array $instance_value_kinds,
		PropertyWireShape $wire_shape,
		PropertyContractStatus $status
	) {
		if ( null !== $specialized && ( '' === $specialized || trim( $specialized ) !== $specialized ) ) {
			throw new InvalidArgumentException( 'Property contract specialized discriminator must be null or a non-empty exact string.' );
		}

		if ( ! is_a( $definition_builder, ComponentPropertyInterface::class, true ) ) {
			throw new InvalidArgumentException( 'Property contract definition builder must implement ComponentPropertyInterface.' );
		}

		if ( array() === $instance_value_kinds ) {
			throw new InvalidArgumentException( 'Property contract must declare at least one instance value kind.' );
		}

		$seen_kinds = array();
		foreach ( $instance_value_kinds as $kind ) {
			if ( ! $kind instanceof PropertyInstanceValueKind ) {
				throw new InvalidArgumentException( 'Property contract instance value kinds must use PropertyInstanceValueKind.' );
			}

			if ( isset( $seen_kinds[ $kind->value ] ) ) {
				throw new InvalidArgumentException( 'Property contract cannot contain duplicate instance value kinds.' );
			}

			$seen_kinds[ $kind->value ] = true;
		}

		$this->primitive            = $primitive;
		$this->specialized          = $specialized;
		$this->definition_builder   = $definition_builder;
		$this->instance_value_kinds = array_values( $instance_value_kinds );
		$this->wire_shape           = $wire_shape;
		$this->status               = $status;
	}

	public function primitive(): PropertyPrimitive {
		return $this->primitive;
	}

	public function specialized(): ?string {
		return $this->specialized;
	}

	/**
	 * @return class-string<ComponentPropertyInterface>
	 */
	public function definition_builder(): string {
		return $this->definition_builder;
	}

	/**
	 * @return array<int, PropertyInstanceValueKind>
	 */
	public function instance_value_kinds(): array {
		return $this->instance_value_kinds;
	}

	public function wire_shape(): PropertyWireShape {
		return $this->wire_shape;
	}

	public function status(): PropertyContractStatus {
		return $this->status;
	}

	/**
	 * Deterministic identity for the exact primitive/specialized pair.
	 */
	public function type_key(): string {
		return null === $this->specialized
			? $this->primitive->value
			: $this->primitive->value . '/' . $this->specialized;
	}

	/**
	 * Return a deterministic machine-readable catalog record.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$type = array( 'primitive' => $this->primitive->value );
		if ( null !== $this->specialized ) {
			$type['specialized'] = $this->specialized;
		}

		return array(
			'type'                 => $type,
			'definition_builder'   => $this->definition_builder,
			'instance_value_kinds' => array_map(
				static fn ( PropertyInstanceValueKind $kind ): string => $kind->value,
				$this->instance_value_kinds
			),
			'wire_shape'           => $this->wire_shape->value,
			'status'               => $this->status->value,
		);
	}
}
