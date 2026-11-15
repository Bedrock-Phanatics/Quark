<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\block\Block;
use quark\block\BlockIdentifier;
use quark\block\BlockTypeInfo;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\data\runtime\RuntimeDataWriter;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Data-driven block for the common presets. Subclass it for custom behavior, keeping the constructor signature.
 * Its states are exactly the settings states, which lets the state ID be cached until a state changes.
 */
class CustomBlock extends Block{
	/** @var array<int, int> */
	private array $stateValues = [];
	private ?int $stateId = null;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private readonly CustomBlockSettings $settings){
		foreach($settings->states as $state){
			$this->stateValues[] = $state->getDefaultIndex();
		}
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getSettings() : CustomBlockSettings{ return $this->settings; }

	final public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$this->describeStates($w, true);
	}

	final protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeStates($w, false);
	}

	private function describeStates(RuntimeDataDescriber $w, bool $itemStates) : void{
		if(!$w instanceof RuntimeDataWriter){
			//readers decode new values into the block
			$this->stateId = null;
		}
		foreach($this->settings->states as $i => $state){
			if($state->isItemState() === $itemStates){
				$w->boundedIntAuto(0, $state->getMaxIndex(), $this->stateValues[$i]);
			}
		}
	}

	public function getStateId() : int{
		return $this->stateId ??= parent::getStateId();
	}

	public function getState(string $name) : bool|int|string{
		$i = $this->settings->stateIndexes[$name] ?? throw new \InvalidArgumentException("Unknown state '$name'");
		return $this->settings->states[$i]->getValues()[$this->stateValues[$i]];
	}

	/** @return $this */
	public function setState(string $name, bool|int|string $value) : static{
		$i = $this->settings->stateIndexes[$name] ?? throw new \InvalidArgumentException("Unknown state '$name'");
		$this->stateValues[$i] = $this->settings->states[$i]->indexOf($value) ?? throw new \InvalidArgumentException("Invalid value for state '$name'");
		$this->collisionBoxes = null;
		$this->stateId = null;
		return $this;
	}

	/** @internal */
	public function getStateIndex(string $name) : int{
		return $this->stateValues[$this->settings->stateIndexes[$name] ?? throw new \InvalidArgumentException("Unknown state '$name'")];
	}

	/** @internal */
	public function setStateIndex(string $name, int $index) : void{
		$i = $this->settings->stateIndexes[$name] ?? throw new \InvalidArgumentException("Unknown state '$name'");
		if($index < 0 || $index > $this->settings->states[$i]->getMaxIndex()){
			throw new \InvalidArgumentException("Invalid index for state '$name'");
		}
		$this->stateValues[$i] = $index;
		$this->collisionBoxes = null;
		$this->stateId = null;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->settings->facingState !== null && $player !== null){
			$this->setState($this->settings->facingState, self::facingName(Facing::opposite($player->getHorizontalFacing())));
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	/** @internal */
	public static function facingName(int $facing) : string{
		return match($facing){
			Facing::NORTH => "north",
			Facing::SOUTH => "south",
			Facing::WEST => "west",
			Facing::EAST => "east",
			default => throw new \InvalidArgumentException("Expected a horizontal facing")
		};
	}

	protected function recalculateCollisionBoxes() : array{
		$boxes = [];
		foreach($this->settings->collisionBoxes as $box){
			$boxes[] = clone $box;
		}
		return $boxes;
	}

	public function isTransparent() : bool{ return $this->settings->transparent; }

	public function isSolid() : bool{ return $this->settings->solid; }

	public function getLightLevel() : int{ return $this->settings->lightLevel; }

	public function getLightFilter() : int{ return $this->settings->lightFilter ?? parent::getLightFilter(); }

	public function getFrictionFactor() : float{ return $this->settings->frictionFactor; }

	public function getFlameEncouragement() : int{ return $this->settings->flameEncouragement; }

	public function getFlammability() : int{ return $this->settings->flammability; }

	public function canBeReplaced() : bool{ return $this->settings->replaceable; }

	public function canBeFlowedInto() : bool{ return $this->settings->collisionBoxes === [] && !$this->settings->solid; }

	public function getSupportType(int $facing) : SupportType{
		return $this->isFullCube() ? SupportType::FULL : SupportType::NONE;
	}
}
