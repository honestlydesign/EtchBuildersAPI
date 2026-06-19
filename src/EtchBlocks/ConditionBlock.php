<?php
/**
 * Type-safe builder for etch/condition block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasChildren;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Types\BlockBase;
use HonestlyDesign\EtchBuilders\Types\ConditionOperator;

/**
 * Builds etch/condition block with consistent fluent API.
 *
 * Pattern:
 *   ConditionBlock::new()
 *     ->condition_operator(ConditionOperator::truthy('item.value'))
 *     ->child($contentBlock)
 *     ->to_block();
 *
 * For compound conditions:
 *   ConditionBlock::new()
 *     ->condition_operator(
 *         ConditionOperator::truthy('slots.default.empty')
 *             ->and(ConditionOperator::truthy('props.label'))
 *     )
 *     ->child($fallbackBlock)
 *     ->to_block();
 */
final class ConditionBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasChildren;

	/**
	 * Condition expression string.
	 *
	 * @var string
	 */
	private string $condition_string = '';

	/**
	 * Structured condition data.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $condition = null;

	/**
	 * Base block attributes.
	 *
	 * @var BlockBase
	 */
	private BlockBase $base;

	/**
	 * Child blocks.
	 *
	 * @var array<int, Block>
	 */
	private array $children = array();

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->base = BlockBase::new();
	}

	/**
	 * Create a new ConditionBlock builder.
	 *
	 * @return self
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Set the condition using a ConditionOperator instance.
	 *
	 * This automatically sets both the condition object and condition string.
	 *
	 * @param ConditionOperator $condition The condition operator instance.
	 * @return self
	 */
	public function condition_operator( ConditionOperator $condition ): self {
		$this->condition        = $condition->to_array();
		$this->condition_string = $condition->to_string();
		return $this;
	}

	/**
	 * Set the condition string manually.
	 *
	 * @param string $condition_string The condition expression (e.g., 'item.value > 10').
	 * @return self
	 */
	public function condition_string( string $condition_string ): self {
		$this->condition_string = $condition_string;
		return $this;
	}

	/**
	 * Set the condition object manually (optional if using condition_operator).
	 *
	 * @param string      $left_hand  Left side of condition.
	 * @param string      $operator   Operator (isTruthy, ===, !==, ==, !=, <, >, <=, >=).
	 * @param string|null $right_hand Right side of condition (null for unary operators).
	 * @return self
	 */
	public function condition( string $left_hand, string $operator, ?string $right_hand ): self {
		$this->condition = array(
			'leftHand'  => $left_hand,
			'operator'  => $operator,
			'rightHand' => $right_hand,
		);
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 */
	public function to_block(): Block {
		$block_attrs = array_merge(
			array( 'conditionString' => $this->condition_string ),
			$this->base->to_array()
		);

		if ( null !== $this->condition ) {
			$block_attrs['condition'] = $this->condition;
		}

		$block = Block::new( 'condition', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
