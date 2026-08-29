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

use quark\block\utils\DirtType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Fertilizer;
use quark\item\Hoe;
use quark\item\Item;
use quark\item\Potion;
use quark\item\PotionType;
use quark\item\SplashPotion;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\ItemUseOnBlockSound;
use quark\world\sound\WaterSplashSound;

class Dirt extends Opaque{
	protected DirtType $dirtType = DirtType::NORMAL;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->enum($this->dirtType);
	}

	public function getDirtType() : DirtType{ return $this->dirtType; }

	/** @return $this */
	public function setDirtType(DirtType $dirtType) : self{
		$this->dirtType = $dirtType;
		return $this;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		if($face !== Facing::DOWN && $item instanceof Hoe){
			$up = $this->getSide(Facing::UP);
			if($up->getTypeId() !== BlockTypeIds::AIR){
				return true;
			}

			$item->applyDamage(1);

			$newBlock = $this->dirtType === DirtType::NORMAL ? VanillaBlocks::FARMLAND() : VanillaBlocks::DIRT();
			$center = $this->position->add(0.5, 0.5, 0.5);
			$world->addSound($center, new ItemUseOnBlockSound($newBlock));
			$world->setBlock($this->position, $newBlock);
			if($this->dirtType === DirtType::ROOTED){
				$world->dropItem($center, VanillaBlocks::HANGING_ROOTS()->asItem());
			}

			return true;
		}elseif($this->dirtType === DirtType::ROOTED && $item instanceof Fertilizer){
			$down = $this->getSide(Facing::DOWN);
			if($down->getTypeId() !== BlockTypeIds::AIR){
				return true;
			}

			$item->pop();
			$world->setBlock($down->position, VanillaBlocks::HANGING_ROOTS());
			//TODO: bonemeal particles, growth sounds
		}elseif(($item instanceof Potion || $item instanceof SplashPotion) && $item->getType() === PotionType::WATER){
			$item->pop();
			$world->setBlock($this->position, VanillaBlocks::MUD());
			$world->addSound($this->position, new WaterSplashSound(0.5));
			return true;
		}

		return false;
	}
}
