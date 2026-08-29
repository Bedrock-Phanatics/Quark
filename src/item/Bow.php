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

namespace quark\item;

use quark\entity\Location;
use quark\entity\projectile\Arrow as ArrowEntity;
use quark\entity\projectile\Projectile;
use quark\event\entity\EntityShootBowEvent;
use quark\event\entity\ProjectileLaunchEvent;
use quark\item\enchantment\VanillaEnchantments;
use quark\player\Player;
use quark\world\sound\BowShootSound;
use function intdiv;
use function min;

class Bow extends Tool implements Releasable{

	public function getFuelTime() : int{
		return 200;
	}

	public function getMaxDurability() : int{
		return 385;
	}

	public function onReleaseUsing(Player $player, array &$returnedItems) : ItemUseResult{
		$arrow = VanillaItems::ARROW();
		$inventory = match(true){
			$player->getOffHandInventory()->contains($arrow) => $player->getOffHandInventory(),
			$player->getInventory()->contains($arrow) => $player->getInventory(),
			default => null
		};

		if($player->hasFiniteResources() && $inventory === null){
			return ItemUseResult::FAIL;
		}

		$location = $player->getLocation();

		$diff = $player->getItemUseDuration();
		$p = $diff / 20;
		$baseForce = min((($p ** 2) + $p * 2) / 3, 1);

		$entity = new ArrowEntity(Location::fromObject(
			$player->getEyePos(),
			$player->getWorld(),
			($location->yaw > 180 ? 360 : 0) - $location->yaw,
			-$location->pitch
		), $player, $baseForce >= 1);
		$entity->setMotion($player->getDirectionVector());

		$infinity = $this->hasEnchantment(VanillaEnchantments::INFINITY());
		if($infinity){
			$entity->setPickupMode(ArrowEntity::PICKUP_CREATIVE);
		}
		if(($punchLevel = $this->getEnchantmentLevel(VanillaEnchantments::PUNCH())) > 0){
			$entity->setPunchKnockback($punchLevel);
		}
		if(($powerLevel = $this->getEnchantmentLevel(VanillaEnchantments::POWER())) > 0){
			$entity->setBaseDamage($entity->getBaseDamage() + (($powerLevel + 1) / 2));
		}
		if($this->hasEnchantment(VanillaEnchantments::FLAME())){
			$entity->setOnFire(intdiv($entity->getFireTicks(), 20) + 100);
		}
		$ev = new EntityShootBowEvent($player, $this, $entity, $baseForce * 3);

		if($baseForce < 0.1 || $diff < 5 || $player->isSpectator()){
			$ev->cancel();
		}

		$ev->call();

		$entity = $ev->getProjectile(); //This might have been changed by plugins

		if($ev->isCancelled()){
			$entity->flagForDespawn();
			return ItemUseResult::FAIL;
		}

		$entity->setMotion($entity->getMotion()->multiply($ev->getForce()));

		if($entity instanceof Projectile){
			$projectileEv = new ProjectileLaunchEvent($entity);
			$projectileEv->call();
			if($projectileEv->isCancelled()){
				$ev->getProjectile()->flagForDespawn();
				return ItemUseResult::FAIL;
			}

			$ev->getProjectile()->spawnToAll();
			$location->getWorld()->addSound($location, new BowShootSound());
		}else{
			$entity->spawnToAll();
		}

		if($player->hasFiniteResources()){
			if(!$infinity){ //TODO: tipped arrows are still consumed when Infinity is applied
				$inventory?->removeItem($arrow);
			}
			$this->applyDamage(1);
		}

		return ItemUseResult::SUCCESS;
	}

	public function canStartUsingItem(Player $player) : bool{
		return !$player->hasFiniteResources() || $player->getOffHandInventory()->contains($arrow = VanillaItems::ARROW()) || $player->getInventory()->contains($arrow);
	}
}
