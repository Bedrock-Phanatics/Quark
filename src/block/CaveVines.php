<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\block;

use quark\block\utils\Ageable;
use quark\block\utils\AgeableTrait;
use quark\block\utils\BlockEventHelper;
use quark\block\utils\StaticSupportTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Entity;
use quark\item\Fertilizer;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\sound\GlowBerriesPickSound;
use function mt_rand;

class CaveVines extends Flowable implements Ageable{
	use AgeableTrait;
	use StaticSupportTrait;

	public const MAX_AGE = 25;

	protected bool $berries = false;
	protected bool $head = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_AGE, $this->age);
		$w->bool($this->berries);
		$w->bool($this->head);
	}

	public function hasBerries() : bool{ return $this->berries; }

	/** @return $this */
	public function setBerries(bool $berries) : self{
		$this->berries = $berries;
		return $this;
	}

	public function isHead() : bool{ return $this->head; }

	/** @return $this */
	public function setHead(bool $head) : self{
		$this->head = $head;
		return $this;
	}

	public function canClimb() : bool{
		return true;
	}

	public function getLightLevel() : int{
		return $this->berries ? 14 : 0;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::UP);
		return $supportBlock->getSupportType(Facing::DOWN) === SupportType::FULL || $supportBlock->hasSameTypeId($this);
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->age = mt_rand(0, self::MAX_AGE);
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->berries){
			$this->position->getWorld()->dropItem($this->position, $this->asItem());
			$this->position->getWorld()->addSound($this->position, new GlowBerriesPickSound());

			$this->position->getWorld()->setBlock($this->position, $this->setBerries(false));
			return true;
		}
		if($item instanceof Fertilizer){
			$newState = (clone $this)
				->setBerries(true)
				->setHead(!$this->getSide(Facing::DOWN)->hasSameTypeId($this));
			if(BlockEventHelper::grow($this, $newState, $player)){
				$item->pop();
			}
			return true;
		}
		return false;
	}

	public function onRandomTick() : void{
		$head = !$this->getSide(Facing::DOWN)->hasSameTypeId($this);
		if($head !== $this->head){
			$this->position->getWorld()->setBlock($this->position, $this->setHead($head));
		}

		if($this->age < self::MAX_AGE && mt_rand(1, 10) === 1){
			$growthPos = $this->position->getSide(Facing::DOWN);
			$world = $growthPos->getWorld();
			if($world->isInWorld($growthPos->getFloorX(), $growthPos->getFloorY(), $growthPos->getFloorZ())){
				$block = $world->getBlock($growthPos);
				if($block->getTypeId() === BlockTypeIds::AIR){
					$newState = VanillaBlocks::CAVE_VINES()
						->setAge($this->age + 1)
						->setBerries(mt_rand(1, 9) === 1);
					BlockEventHelper::grow($block, $newState, null);
				}
			}
		}
	}

	public function ticksRandomly() : bool{
		return true;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return $this->berries ? [$this->asItem()] : [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function asItem() : Item{
		return VanillaItems::GLOW_BERRIES();
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
