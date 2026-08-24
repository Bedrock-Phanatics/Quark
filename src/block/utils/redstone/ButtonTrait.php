<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use Generator;
use pocketmine\block\Block;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\block\utils\redstone\Transmittable;
use pocketmine\block\utils\redstone\RedstoneBlockUtils;

trait ButtonTrait{

	public function getPowerLevel() : int{
		return $this->pressed ? 15 : 0;
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return true;
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingOnSupportBlocks() : Generator{
		$supporting_side = $this->getSupportingSide();
		$supporting = RedstoneBlockUtils::getBlockAtSide($this->position, $supporting_side);
		$skip_side = Facing::opposite($supporting_side);
		foreach(Facing::ALL as $side){
			if($side !== $skip_side){
				$block = RedstoneBlockUtils::getBlockAtSide($supporting->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingBlocks() : Generator{
		yield from $this->getRelyingOnSupportBlocks();
		$skip_side = $this->getSupportingSide();
		foreach(Facing::HORIZONTAL as $side){
			if($side !== $skip_side){
				$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	public function getSupportingSide() : int{
		return Facing::opposite($this->facing);
	}

	public function canStronglyPower(int $side) : bool{
		return $side === $this->getSupportingSide();
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		return $blockClicked->isSolid() && parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->pressed){
			$this->pressed = false;
			foreach($this->getRelyingOnSupportBlocks() as $block){
				$block->power($this);
			}
		}

		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$previous_state = $this->pressed;
		if(parent::onInteract($item, $face, $clickVector, $player, $returnedItems)){
			if($this->pressed !== $previous_state && $this->pressed){
				foreach($this->getRelyingBlocks() as $side){
					$side->power($this);
				}
			}
			return true;
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing))->isSolid()){
			$this->position->getWorld()->useBreakOn($this->position);
		}else{
			parent::onNearbyBlockChange();
		}
	}

	public function onScheduledUpdate() : void{
		$previous_state = $this->pressed;
		parent::onScheduledUpdate();
		if($this->pressed !== $previous_state){
			foreach($this->getRelyingBlocks() as $side){
				$side->power($this);
			}
		}
	}
}