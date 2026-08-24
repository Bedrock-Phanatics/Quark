<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;

use Generator;
use pocketmine\block\RedstoneTorch as VanillaRedstoneTorch;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\player\Player;
use pocketmine\block\utils\redstone\PowerSource;
use pocketmine\block\utils\redstone\ToggleablePowerSource;
use pocketmine\block\utils\redstone\Transmittable;
use pocketmine\block\utils\redstone\Waitable;
use pocketmine\block\utils\redstone\RedstoneBlockUtils;
use pocketmine\block\utils\redstone\RedstoneTorchBlockData;
use pocketmine\world\redstone\RedstoneWorldState;
use pocketmine\world\redstone\RedstoneManager;

trait RedstoneTorchBehavior{
	use RedstoneBlockAccessTrait;

	public function getPowerLevel() : int{
		return $this->lit ? 15 : 0;
	}

	public function getOutputPowerLevel() : int{
		return $this->getPowerLevel();
	}

	public function canPower(int $side) : bool{
		return $side !== Facing::opposite($this->facing);
	}

	public function canStronglyPower(int $side) : bool{
		return $side === Facing::UP;
	}

	public function switch(bool $state) : void{
		if($state === $this->lit){
			return;
		}

		$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setLit($state), false);
		foreach($this->getRelyingBlocks() as $block){
			$block->power($this);
		}
	}

	public function power(?PowerSource $source = null) : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		RedstoneManager::getInstance()->get($this->position->getWorld())->scheduleWaitableUpdate($this, RedstoneWorldState::redstoneTicks(1));
	}

	private function updateState(bool $manual) : void{
		$state = !RedstoneManager::getInstance()->get($this->position->getWorld())->isStronglyPowered(RedstoneBlockUtils::getBlockAtSide($this->position, Facing::opposite($this->facing)), $this->facing);
		if($state === $this->lit){
			return;
		}

		if($manual){
			$world = RedstoneManager::getInstance()->get($this->position->getWorld());
			$data = $world->getExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
			if(!($data instanceof RedstoneTorchBlockData)){
				$data = new RedstoneTorchBlockData();
				$world->setExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $data);
			}
			$tick = $world->tick;
			if($state){
				$data->count($tick);
			}
			if($data->isBurntOut($tick)){
				$state = false;
			}
		}
		$this->switch($state);
	}

	public function onRedstoneTickReceive() : void{
		$this->updateState(true);
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingBlocks() : Generator{
		$sides = Facing::HORIZONTAL;
		$sides[] = Facing::DOWN;
		foreach($sides as $side){
			$block = RedstoneBlockUtils::getBlockAtSide($this->position, $side);
			if($block instanceof Transmittable){
				yield $block;
			}
		}

		yield from $this->getRelyingOnSupportBlocks();
	}

	/**
	 * @return Generator<Transmittable>
	 */
	protected function getRelyingOnSupportBlocks() : Generator{
		$up = RedstoneBlockUtils::getBlockAtSide($this->position, Facing::UP);
		if($up instanceof Transmittable){
			yield $up;
		}
		foreach(Facing::ALL as $side){
			if($side !== Facing::DOWN){
				$block = RedstoneBlockUtils::getBlockAtSide($up->position, $side);
				if($block instanceof Transmittable){
					yield $block;
				}
			}
		}
	}

	public function onPostPlace() : void{
		$this->power();
		foreach($this->getRelyingBlocks() as $block){
			$block->power($this);
		}
	}

	public function onScheduledUpdate() : void{
		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		$this->updateState(false);
	}

	public function onNearbyBlockChange() : void{
		if(!RedstoneManager::getInstance()->isEnabledAt($this->position)){ return; }
		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		$this->updateState(false);
		parent::onNearbyBlockChange();
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->lit){
			$this->lit = false;
			foreach($this->getRelyingOnSupportBlocks() as $block){
				$block->power($this);
			}
		}

		RedstoneManager::getInstance()->get($this->position->getWorld())->removeExtraDataAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		return parent::onBreak($item, $player, $returnedItems);
	}
}