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

namespace quark\block\tile;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ContainerSetDataPacket;
use quark\block\inventory\window\BrewingStandInventoryWindow;
use quark\crafting\BrewingRecipe;
use quark\event\block\BrewingFuelUseEvent;
use quark\event\block\BrewItemEvent;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\world\sound\PotionFinishBrewingSound;
use function array_map;
use function count;

class BrewingStand extends Spawnable implements ContainerTile, Nameable{
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ContainerTileTrait;

	public const BREW_TIME_TICKS = 400; // Brew time in ticks

	private const TAG_BREW_TIME = "BrewTime"; //TAG_Short
	private const TAG_BREW_TIME_PE = "CookTime"; //TAG_Short
	private const TAG_MAX_FUEL_TIME = "FuelTotal"; //TAG_Short
	private const TAG_REMAINING_FUEL_TIME = "Fuel"; //TAG_Byte
	private const TAG_REMAINING_FUEL_TIME_PE = "FuelAmount"; //TAG_Short

	private Inventory $inventory;

	private int $brewTime = 0;
	private int $maxFuelTime = 0;
	private int $remainingFuelTime = 0;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(5);
		//a weak reference keeps the listener from tying the tile into a reference cycle
		$tile = \WeakReference::create($this);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(static function(Inventory $unused) use ($tile) : void{
			$brewingStand = $tile->get();
			if($brewingStand !== null && $brewingStand->isAttached()){
				$pos = $brewingStand->getPosition();
				$pos->getWorld()->scheduleDelayedBlockUpdate($pos, 1);
			}
		}));
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);

		$this->brewTime = $nbt->getShort(self::TAG_BREW_TIME, $nbt->getShort(self::TAG_BREW_TIME_PE, 0));
		$this->maxFuelTime = $nbt->getShort(self::TAG_MAX_FUEL_TIME, 0);
		$this->remainingFuelTime = $nbt->getByte(self::TAG_REMAINING_FUEL_TIME, $nbt->getShort(self::TAG_REMAINING_FUEL_TIME_PE, 0));
		if($this->maxFuelTime === 0){
			$this->maxFuelTime = $this->remainingFuelTime;
		}
		if($this->remainingFuelTime === 0){
			$this->maxFuelTime = $this->remainingFuelTime = $this->brewTime = 0;
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);

		$nbt->setShort(self::TAG_BREW_TIME_PE, $this->brewTime);
		$nbt->setShort(self::TAG_MAX_FUEL_TIME, $this->maxFuelTime);
		$nbt->setShort(self::TAG_REMAINING_FUEL_TIME_PE, $this->remainingFuelTime);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addNameSpawnData($nbt);

		$nbt->setShort(self::TAG_BREW_TIME_PE, $this->brewTime);
		$nbt->setShort(self::TAG_MAX_FUEL_TIME, $this->maxFuelTime);
		$nbt->setShort(self::TAG_REMAINING_FUEL_TIME_PE, $this->remainingFuelTime);
	}

	public function getDefaultName() : string{
		return "Brewing Stand";
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllWindows();

			parent::close();
		}
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	private function checkFuel(Item $item) : void{
		$ev = new BrewingFuelUseEvent($this);
		if(!$item->equals(VanillaItems::BLAZE_POWDER(), true, false)){
			$ev->cancel();
		}

		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		$item->pop();
		$this->inventory->setItem(BrewingStandInventoryWindow::SLOT_FUEL, $item);

		$this->maxFuelTime = $this->remainingFuelTime = $ev->getFuelTime();
	}

	/**
	 * @return BrewingRecipe[]
	 * @phpstan-return array<int, BrewingRecipe>
	 */
	private function getBrewableRecipes() : array{
		$ingredient = $this->inventory->getItem(BrewingStandInventoryWindow::SLOT_INGREDIENT);
		if($ingredient->isNull()){
			return [];
		}

		$recipes = [];
		$craftingManager = $this->getPosition()->getWorld()->getServer()->getCraftingManager();
		foreach([BrewingStandInventoryWindow::SLOT_BOTTLE_LEFT, BrewingStandInventoryWindow::SLOT_BOTTLE_MIDDLE, BrewingStandInventoryWindow::SLOT_BOTTLE_RIGHT] as $slot){
			$input = $this->inventory->getItem($slot);
			if($input->isNull()){
				continue;
			}

			if(($recipe = $craftingManager->matchBrewingRecipe($input, $ingredient)) !== null){
				$recipes[$slot] = $recipe;
			}
		}

		return $recipes;
	}

	public function onUpdate() : bool{
		if($this->closed){
			return false;
		}

		$scope = $this->pulse->start();

		$prevBrewTime = $this->brewTime;
		$prevRemainingFuelTime = $this->remainingFuelTime;
		$prevMaxFuelTime = $this->maxFuelTime;

		$ret = false;

		$fuel = $this->inventory->getItem(BrewingStandInventoryWindow::SLOT_FUEL);
		$ingredient = $this->inventory->getItem(BrewingStandInventoryWindow::SLOT_INGREDIENT);

		$recipes = $this->getBrewableRecipes();
		$canBrew = count($recipes) !== 0;

		if($this->remainingFuelTime <= 0 && $canBrew){
			$this->checkFuel($fuel);
		}

		if($this->remainingFuelTime > 0){
			if($canBrew){
				if($this->brewTime === 0){
					$this->brewTime = self::BREW_TIME_TICKS;
					--$this->remainingFuelTime;
				}

				--$this->brewTime;

				if($this->brewTime <= 0){
					$anythingBrewed = false;
					foreach($recipes as $slot => $recipe){
						$input = $this->inventory->getItem($slot);
						$output = $recipe->getResultFor($input);
						if($output === null){
							continue;
						}

						$ev = new BrewItemEvent($this, $slot, $input, $output, $recipe);
						$ev->call();
						if($ev->isCancelled()){
							continue;
						}

						$this->inventory->setItem($slot, $ev->getResult());
						$anythingBrewed = true;
					}

					if($anythingBrewed){
						$pos = $this->getPosition();
						$pos->getWorld()->addSound($pos->add(0.5, 0.5, 0.5), new PotionFinishBrewingSound());
					}

					$ingredient->pop();
					$this->inventory->setItem(BrewingStandInventoryWindow::SLOT_INGREDIENT, $ingredient);

					$this->brewTime = 0;
				}else{
					$ret = true;
				}
			}else{
				$this->brewTime = 0;
			}
		}else{
			$this->brewTime = $this->remainingFuelTime = $this->maxFuelTime = 0;
		}

		$viewers = array_map(fn(Player $p) => $p->getNetworkSession()->getInvManager(), $this->inventory->getViewers());
		foreach($viewers as $v){
			if($v === null){
				continue;
			}
			if($prevBrewTime !== $this->brewTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_BREWING_STAND_BREW_TIME, $this->brewTime);
			}
			if($prevRemainingFuelTime !== $this->remainingFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_BREWING_STAND_FUEL_AMOUNT, $this->remainingFuelTime);
			}
			if($prevMaxFuelTime !== $this->maxFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_BREWING_STAND_FUEL_TOTAL, $this->maxFuelTime);
			}
		}

		$this->pulse->stop($scope);

		return $ret;
	}
}
