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

namespace quark\block\utils;

use quark\block\Block;
use quark\entity\projectile\Projectile;
use quark\item\Durable;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\Item;
use quark\item\ItemTypeIds;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\BlazeShootSound;
use quark\world\sound\FireExtinguishSound;
use quark\world\sound\FlintSteelSound;

trait CandleTrait{
	use LightableTrait;

	public function getLightLevel() : int{
		return $this->lit ? 3 : 0;
	}

	/**
	 * @param Item[] &$returnedItems
	 * @see Block::onInteract()
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item->getTypeId() === ItemTypeIds::FIRE_CHARGE || $item->getTypeId() === ItemTypeIds::FLINT_AND_STEEL || $item->hasEnchantment(VanillaEnchantments::FIRE_ASPECT())){
			if($this->lit){
				return true;
			}
			if($item instanceof Durable){
				$item->applyDamage(1);
			}elseif($item->getTypeId() === ItemTypeIds::FIRE_CHARGE){
				$item->pop();
				//TODO: not sure if this is intentional, but it's what Bedrock currently does as of 1.20.10
				$this->position->getWorld()->addSound($this->position, new BlazeShootSound());
			}
			$this->position->getWorld()->addSound($this->position, new FlintSteelSound());
			$this->position->getWorld()->setBlock($this->position, $this->setLit(true));

			return true;
		}
		if($item->isNull()){ //candle can only be extinguished with an empty hand
			if(!$this->lit){
				return true;
			}
			$this->position->getWorld()->addSound($this->position, new FireExtinguishSound());
			$this->position->getWorld()->setBlock($this->position, $this->setLit(false));

			return true;
		}

		//yes, this is intentional! in vanilla, if the candle is not interacted with, a block is placed.
		return false;
	}

	/** @see Block::onProjectileHit() */
	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		if(!$this->lit && $projectile->isOnFire()){
			$this->position->getWorld()->setBlock($this->position, $this->setLit(true));
		}
	}
}
