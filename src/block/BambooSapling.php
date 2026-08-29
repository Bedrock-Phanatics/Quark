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

use quark\block\utils\StaticSupportTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\event\block\StructureGrowEvent;
use quark\item\Bamboo as ItemBamboo;
use quark\item\Fertilizer;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

final class BambooSapling extends Flowable{
	use StaticSupportTrait;

	private bool $ready = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->ready);
	}

	public function isReady() : bool{ return $this->ready; }

	/** @return $this */
	public function setReady(bool $ready) : self{
		$this->ready = $ready;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return
			$supportBlock->getTypeId() === BlockTypeIds::GRAVEL ||
			$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			$supportBlock->hasTypeTag(BlockTypeTags::SAND);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer || $item instanceof ItemBamboo){
			if($this->grow($player)){
				$item->pop();
				return true;
			}
		}
		return false;
	}

	private function grow(?Player $player) : bool{
		$world = $this->position->getWorld();
		if(!$world->getBlock($this->position->up())->canBeReplaced()){
			return false;
		}

		$tx = new BlockTransaction($world);
		$bamboo = VanillaBlocks::BAMBOO();
		$tx->addBlock($this->position, $bamboo)
			->addBlock($this->position->up(), (clone $bamboo)->setLeafSize(Bamboo::SMALL_LEAVES));

		$ev = new StructureGrowEvent($this, $tx, $player);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		return $tx->apply();
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($this->ready){
			$this->ready = false;
			if($world->getFullLight($this->position) < 9 || !$this->grow(null)){
				$world->setBlock($this->position, $this);
			}
		}elseif($world->getBlock($this->position->up())->canBeReplaced()){
			$this->ready = true;
			$world->setBlock($this->position, $this);
		}
	}

	public function asItem() : Item{
		return VanillaItems::BAMBOO();
	}
}
