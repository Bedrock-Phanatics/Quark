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

use quark\block\tile\Campfire as TileCampfire;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\Lightable;
use quark\block\utils\LightableTrait;
use quark\block\utils\SupportType;
use quark\crafting\FurnaceRecipe;
use quark\crafting\FurnaceType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Entity;
use quark\entity\Living;
use quark\entity\projectile\Projectile;
use quark\entity\projectile\SplashPotion;
use quark\event\block\CampfireCookEvent;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\event\entity\EntityDamageEvent;
use quark\inventory\Inventory;
use quark\item\Durable;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\Item;
use quark\item\ItemTypeIds;
use quark\item\PotionType;
use quark\item\Shovel;
use quark\item\VanillaItems;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\RayTraceResult;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\sound\BlazeShootSound;
use quark\world\sound\FireExtinguishSound;
use quark\world\sound\FlintSteelSound;
use quark\world\sound\ItemFrameAddItemSound;
use function count;
use function min;
use function mt_rand;

class Campfire extends Transparent implements Lightable, HorizontalFacing{
	use HorizontalFacingTrait{
		HorizontalFacingTrait::describeBlockOnlyState as encodeFacingState;
	}
	use LightableTrait{
		LightableTrait::describeBlockOnlyState as encodeLitState;
	}

	private const UPDATE_INTERVAL_TICKS = 10;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->encodeFacingState($w);
		$this->encodeLitState($w);
	}

	private function getTile() : ?TileCampfire{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileCampfire ? $tile : null;
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	public function getLightLevel() : int{
		return $this->lit ? 15 : 0;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaItems::CHARCOAL()->setCount(2)
		];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, 9 / 16)];
	}

	/**
	 * Returns the inventory held by the tile at this block's position, or null if there is no campfire tile there.
	 */
	public function getInventory() : ?Inventory{
		return $this->getTile()?->getInventory();
	}

	protected function getFurnaceType() : FurnaceType{
		return FurnaceType::CAMPFIRE;
	}

	protected function getEntityCollisionDamage() : int{
		return 1;
	}

	/**
	 * Sets the number of ticks during the item in the given slot has been cooked.
	 */
	public function setCookingTime(int $slot, int $time) : void{
		if($slot < 0 || $slot > 3){
			throw new \InvalidArgumentException("Slot must be in range 0-3");
		}
		if($time < 0 || $time > $this->getFurnaceType()->getCookDurationTicks()){
			throw new \InvalidArgumentException("CookingTime must be in range 0-" . $this->getFurnaceType()->getCookDurationTicks());
		}
		$this->getTile()?->setCookingTime($slot, $time);
	}

	/**
	 * Returns the number of ticks during the item in the given slot has been cooked.
	 */
	public function getCookingTime(int $slot) : int{
		return $this->getTile()?->getCookingTime($slot) ?? 0;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($this->getSide(Facing::DOWN) instanceof Campfire){
			return false;
		}
		if($player !== null){
			$this->facing = $player->getHorizontalFacing();
		}
		$this->lit = true;
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$this->lit){
			if($item->getTypeId() === ItemTypeIds::FIRE_CHARGE){
				$item->pop();
				$this->ignite();
				$this->position->getWorld()->addSound($this->position, new BlazeShootSound());
				return true;
			}elseif($item->getTypeId() === ItemTypeIds::FLINT_AND_STEEL || $item->hasEnchantment(VanillaEnchantments::FIRE_ASPECT())){
				if($item instanceof Durable){
					$item->applyDamage(1);
				}
				$this->ignite();
				return true;
			}
		}elseif($item instanceof Shovel){
			$item->applyDamage(1);
			$this->extinguish();
			return true;
		}

		$tile = $this->getTile();
		if($tile !== null && $this->position->getWorld()->getServer()->getCraftingManager()->getFurnaceRecipeManager($this->getFurnaceType())->match($item) !== null){
			$ingredient = clone $item;
			$ingredient->setCount(1);
			if(count($tile->getInventory()->addItem($ingredient)) === 0){
				$item->pop();
				$this->position->getWorld()->addSound($this->position, new ItemFrameAddItemSound());
				return true;
			}
		}
		return false;
	}

	public function onNearbyBlockChange() : void{
		if($this->lit && $this->getSide(Facing::UP)->getTypeId() === BlockTypeIds::WATER){
			$this->extinguish();
			//TODO: Waterlogging
		}
	}

	public function onEntityInside(Entity $entity) : bool{
		if(!$this->lit){
			if($entity->isOnFire()){
				$this->ignite();
				return false;
			}
		}elseif($entity instanceof Living){
			$entity->attack(new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_FIRE, $this->getEntityCollisionDamage()));
		}
		return true;
	}

	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		if($this->lit && $projectile instanceof SplashPotion && $projectile->getPotionType() === PotionType::WATER){
			$this->extinguish();
		}
	}

	public function onScheduledUpdate() : void{
		$tile = $this->lit ? $this->getTile() : null;
		if($tile === null){
			return;
		}
		$world = $this->position->getWorld();
		$inventory = $tile->getInventory();
		$furnaceType = $this->getFurnaceType();
		$maxCookDuration = $furnaceType->getCookDurationTicks();
		foreach($inventory->getContents() as $slot => $item){
			if($inventory->getMatchingItemCount($slot, $item, true) !== $item->getCount()){
				continue;
			}
			$cookingTime = min($maxCookDuration, $tile->getCookingTime($slot) + self::UPDATE_INTERVAL_TICKS);
			$tile->setCookingTime($slot, $cookingTime);
			if($cookingTime < $maxCookDuration){
				continue;
			}

			$result =
				($recipe = $world->getServer()->getCraftingManager()->getFurnaceRecipeManager($furnaceType)->match($item)) instanceof FurnaceRecipe ?
					$recipe->getResult() :
					VanillaItems::AIR();

			$ev = new CampfireCookEvent($this, $slot, $item, $result);
			$ev->call();

			if($this->getTile() !== $tile){
				return;
			}
			if($ev->isCancelled() || $inventory->getMatchingItemCount($slot, $item, true) !== $item->getCount() || $tile->getCookingTime($slot) !== $cookingTime){
				continue;
			}

			$inventory->setItem($slot, VanillaItems::AIR());
			$world->dropItem($this->position->add(0.5, 1, 0.5), $ev->getResult());
		}
		if(mt_rand(1, 6) === 1){
			$world->addSound($this->position, $furnaceType->getCookSound());
		}
		$world->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS);
	}

	private function extinguish() : void{
		$this->position->getWorld()->addSound($this->position, new FireExtinguishSound());
		$this->position->getWorld()->setBlock($this->position, $this->setLit(false));
	}

	private function ignite() : void{
		$this->position->getWorld()->addSound($this->position, new FlintSteelSound());
		$this->position->getWorld()->setBlock($this->position, $this->setLit(true));
		$this->position->getWorld()->scheduleDelayedBlockUpdate($this->position, self::UPDATE_INTERVAL_TICKS);
	}
}
