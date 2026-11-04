<?php

declare(strict_types=1);

namespace quark\block\custom;

use pocketmine\math\AxisAlignedBB;

/**
 * Immutable server behavior shared by every state of one CustomBlock type.
 * @internal Built by BlockDefinition.
 */
final readonly class CustomBlockSettings{
	/** @var array<string, int> */
	public array $stateIndexes;

	/**
	 * @param list<BlockState>    $states
	 * @param list<AxisAlignedBB> $collisionBoxes
	 */
	public function __construct(
		public array $states = [],
		public bool $transparent = false,
		public bool $solid = true,
		public array $collisionBoxes = [new AxisAlignedBB(0, 0, 0, 1, 1, 1)],
		public int $lightLevel = 0,
		public ?int $lightFilter = null,
		public float $frictionFactor = 0.6,
		public int $flameEncouragement = 0,
		public int $flammability = 0,
		public bool $replaceable = false,
		public ?string $facingState = null
	){
		$indexes = [];
		foreach($states as $i => $state){
			if(isset($indexes[$state->getName()])){
				throw new \InvalidArgumentException("Duplicate block state '" . $state->getName() . "'");
			}
			$indexes[$state->getName()] = $i;
		}
		$this->stateIndexes = $indexes;
		if($lightLevel < 0 || $lightLevel > 15 || ($lightFilter !== null && ($lightFilter < 0 || $lightFilter > 15))){
			throw new \InvalidArgumentException("Light levels must be 0 - 15");
		}
		if($frictionFactor <= 0.0 || $frictionFactor > 1.0 || $flameEncouragement < 0 || $flammability < 0 || $flameEncouragement > 100 || $flammability > 100){
			throw new \InvalidArgumentException("Friction must be in (0, 1] and flammability values 0 - 100");
		}
		if($facingState !== null && !isset($indexes[$facingState])){
			throw new \InvalidArgumentException("Facing state '$facingState' is not defined");
		}
	}
}
