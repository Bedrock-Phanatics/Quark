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
use quark\block\utils\FortuneDropHelper;
use quark\block\utils\StaticSupportTrait;
use quark\entity\Entity;
use quark\entity\Living;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\item\Fertilizer;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\SweetBerriesPickSound;
use function mt_rand;

class SweetBerryBush extends Flowable implements Ageable{
	use AgeableTrait;
	use StaticSupportTrait;

	public const STAGE_SAPLING = 0;
	public const STAGE_BUSH_NO_BERRIES = 1;
	public const STAGE_BUSH_SOME_BERRIES = 2;
	public const STAGE_MATURE = 3;
	public const MAX_AGE = self::STAGE_MATURE;

	public function getBerryDropAmount() : int{
		if($this->age === self::STAGE_MATURE){
			return mt_rand(2, 3);
		}elseif($this->age >= self::STAGE_BUSH_SOME_BERRIES){
			return mt_rand(1, 2);
		}
		return 0;
	}

	/**
	 * @deprecated
	 */
	protected function canBeSupportedBy(Block $block) : bool{
		return $block->getTypeId() !== BlockTypeIds::FARMLAND && //bedrock-specific thing (bug?)
			($block->hasTypeTag(BlockTypeTags::DIRT) || $block->hasTypeTag(BlockTypeTags::MUD));
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $this->canBeSupportedBy($supportBlock);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		if($this->age < self::STAGE_MATURE && $item instanceof Fertilizer){
			$block = clone $this;
			$block->age++;
			if(BlockEventHelper::grow($this, $block, $player)){
				$item->pop();
			}
		}elseif(($dropAmount = $this->getBerryDropAmount()) > 0){
			$world->setBlock($this->position, $this->setAge(self::STAGE_BUSH_NO_BERRIES));
			$world->dropItem($this->position, $this->asItem()->setCount($dropAmount));
			$world->addSound($this->position, new SweetBerriesPickSound());
		}

		return true;
	}

	public function asItem() : Item{
		return VanillaItems::SWEET_BERRIES();
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$count = match($this->age){
			self::STAGE_MATURE => FortuneDropHelper::discrete($item, 2, 3),
			self::STAGE_BUSH_SOME_BERRIES => FortuneDropHelper::discrete($item, 1, 2),
			default => 0
		};
		return [
			$this->asItem()->setCount($count)
		];
	}

	public function ticksRandomly() : bool{
		return $this->age < self::STAGE_MATURE;
	}

	public function onRandomTick() : void{
		if($this->age < self::STAGE_MATURE && mt_rand(0, 2) === 1){
			$block = clone $this;
			++$block->age;
			BlockEventHelper::grow($this, $block, null);
		}
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		if($this->age >= self::STAGE_BUSH_NO_BERRIES && $entity instanceof Living){
			$entity->resetFallDistance();

			//TODO: in MCPE, this only triggers if moving while inside the bush block - we don't have the system to deal
			//with that reliably right now
			$entity->attack(new EntityDamageByBlockEvent($this, $entity, EntityDamageByBlockEvent::CAUSE_CONTACT, 1));
		}
		return true;
	}
}
