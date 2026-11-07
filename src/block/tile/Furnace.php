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
use quark\block\Furnace as BlockFurnace;
use quark\block\inventory\window\FurnaceInventoryWindow;
use quark\crafting\FurnaceRecipe;
use quark\crafting\FurnaceType;
use quark\event\inventory\FurnaceBurnEvent;
use quark\event\inventory\FurnaceSmeltEvent;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\player\Player;
use function array_map;
use function max;

abstract class Furnace extends Spawnable implements ContainerTile, Nameable{
	use NameableTrait;
	use ContainerTileTrait;

	public const TAG_BURN_TIME = "BurnTime";
	public const TAG_COOK_TIME = "CookTime";
	public const TAG_MAX_TIME = "MaxTime";

	protected Inventory $inventory;
	private int $remainingFuelTime = 0;
	private int $cookTime = 0;
	private int $maxFuelTime = 0;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(3);
		//a weak reference keeps the listener from tying the tile into a reference cycle
		$tile = \WeakReference::create($this);
		$this->inventory->getListeners()->add(CallbackInventoryListener::onAnyChange(
			static function(Inventory $unused) use ($tile) : void{
				$furnace = $tile->get();
				if($furnace !== null && $furnace->isAttached()){
					$pos = $furnace->getPosition();
					$pos->getWorld()->scheduleDelayedBlockUpdate($pos, 1);
				}
			})
		);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->remainingFuelTime = max(0, $nbt->getShort(self::TAG_BURN_TIME, $this->remainingFuelTime));

		$this->cookTime = $nbt->getShort(self::TAG_COOK_TIME, $this->cookTime);
		if($this->remainingFuelTime === 0){
			$this->cookTime = 0;
		}

		$this->maxFuelTime = $nbt->getShort(self::TAG_MAX_TIME, $this->maxFuelTime);
		if($this->maxFuelTime === 0){
			$this->maxFuelTime = $this->remainingFuelTime;
		}

		$this->loadName($nbt);
		$this->loadItems($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setShort(self::TAG_BURN_TIME, $this->remainingFuelTime);
		$nbt->setShort(self::TAG_COOK_TIME, $this->cookTime);
		$nbt->setShort(self::TAG_MAX_TIME, $this->maxFuelTime);
		$this->saveName($nbt);
		$this->saveItems($nbt);
	}

	public function getDefaultName() : string{
		return "Furnace";
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

	protected function checkFuel(Item $fuel) : void{
		$ev = new FurnaceBurnEvent($this, $fuel, $fuel->getFuelTime());
		$ev->call();
		if($ev->isCancelled()){
			return;
		}

		$this->maxFuelTime = $this->remainingFuelTime = $ev->getBurnTime();
		$this->onStartSmelting();

		if($this->remainingFuelTime > 0 && $ev->isBurning()){
			$this->inventory->setItem(FurnaceInventoryWindow::SLOT_FUEL, $fuel->getFuelResidue());
		}
	}

	protected function onStartSmelting() : void{
		$block = $this->getBlock();
		if($block instanceof BlockFurnace && !$block->isLit()){
			$block->setLit(true);
			$this->getPosition()->getWorld()->setBlock($block->getPosition(), $block);
		}
	}

	protected function onStopSmelting() : void{
		$block = $this->getBlock();
		if($block instanceof BlockFurnace && $block->isLit()){
			$block->setLit(false);
			$this->getPosition()->getWorld()->setBlock($block->getPosition(), $block);
		}
	}

	abstract public function getFurnaceType() : FurnaceType;

	public function onUpdate() : bool{
		//TODO: move this to Block
		if($this->closed){
			return false;
		}

		$scope = $this->pulse->start();

		$prevCookTime = $this->cookTime;
		$prevRemainingFuelTime = $this->remainingFuelTime;
		$prevMaxFuelTime = $this->maxFuelTime;

		$ret = false;

		$fuel = $this->inventory->getItem(FurnaceInventoryWindow::SLOT_FUEL);
		$raw = $this->inventory->getItem(FurnaceInventoryWindow::SLOT_INPUT);
		$product = $this->inventory->getItem(FurnaceInventoryWindow::SLOT_RESULT);

		$furnaceType = $this->getFurnaceType();
		$smelt = $this->getPosition()->getWorld()->getServer()->getCraftingManager()->getFurnaceRecipeManager($furnaceType)->match($raw);
		$canSmelt = ($smelt instanceof FurnaceRecipe && $raw->getCount() > 0 && (($smelt->getResult()->canStackWith($product) && $product->getCount() < $product->getMaxStackSize()) || $product->isNull()));

		if($this->remainingFuelTime <= 0 && $canSmelt && $fuel->getFuelTime() > 0 && $fuel->getCount() > 0){
			$this->checkFuel($fuel);
		}

		if($this->remainingFuelTime > 0){
			--$this->remainingFuelTime;

			if($smelt instanceof FurnaceRecipe && $canSmelt){
				++$this->cookTime;

				if($this->cookTime >= $furnaceType->getCookDurationTicks()){
					$product = $smelt->getResult()->setCount($product->getCount() + 1);

					$ev = new FurnaceSmeltEvent($this, $raw, $product);
					$ev->call();

					if(!$ev->isCancelled()){
						$this->inventory->setItem(FurnaceInventoryWindow::SLOT_RESULT, $ev->getResult());
						$raw->pop();
						$this->inventory->setItem(FurnaceInventoryWindow::SLOT_INPUT, $raw);
					}

					$this->cookTime -= $furnaceType->getCookDurationTicks();
				}
			}elseif($this->remainingFuelTime <= 0){
				$this->remainingFuelTime = $this->cookTime = $this->maxFuelTime = 0;
			}else{
				$this->cookTime = 0;
			}
			$ret = true;
		}else{
			$this->onStopSmelting();
			$this->remainingFuelTime = $this->cookTime = $this->maxFuelTime = 0;
		}

		$viewers = array_map(fn(Player $p) => $p->getNetworkSession()->getInvManager(), $this->inventory->getViewers());
		foreach($viewers as $v){
			if($v === null){
				continue;
			}
			if($prevCookTime !== $this->cookTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_SMELT_PROGRESS, $this->cookTime);
			}
			if($prevRemainingFuelTime !== $this->remainingFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_REMAINING_FUEL_TIME, $this->remainingFuelTime);
			}
			if($prevMaxFuelTime !== $this->maxFuelTime){
				$v->syncData($this->inventory, ContainerSetDataPacket::PROPERTY_FURNACE_MAX_FUEL_TIME, $this->maxFuelTime);
			}
		}

		$this->pulse->stop($scope);

		return $ret;
	}
}
