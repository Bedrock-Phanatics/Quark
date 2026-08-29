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

use quark\block\tile\Cauldron as TileCauldron;
use quark\block\utils\SupportType;
use quark\item\Item;
use quark\item\ItemTypeIds;
use quark\item\Potion;
use quark\item\PotionType;
use quark\item\SplashPotion;
use quark\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use function assert;

final class Cauldron extends Transparent{

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileCauldron);

		//empty cauldrons don't use this information
		$tile->setCustomWaterColor(null);
		$tile->setPotionItem(null);
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 11 / 16) //bottom of the cauldron
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return $facing === Facing::UP ? SupportType::EDGE : SupportType::NONE;
	}

	/**
	 * @param Item[] &$returnedItems
	 */
	private function fill(int $amount, FillableCauldron $result, Item $usedItem, Item $returnedItem, array &$returnedItems) : void{
		$this->position->getWorld()->setBlock($this->position, $result->setFillLevel($amount));
		$this->position->getWorld()->addSound($this->position->add(0.5, 0.5, 0.5), $result->getFillSound());

		$usedItem->pop();
		$returnedItems[] = $returnedItem;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item->getTypeId() === ItemTypeIds::WATER_BUCKET){
			$this->fill(FillableCauldron::MAX_FILL_LEVEL, VanillaBlocks::WATER_CAULDRON(), $item, VanillaItems::BUCKET(), $returnedItems);
		}elseif($item->getTypeId() === ItemTypeIds::LAVA_BUCKET){
			$this->fill(FillableCauldron::MAX_FILL_LEVEL, VanillaBlocks::LAVA_CAULDRON(), $item, VanillaItems::BUCKET(), $returnedItems);
		}elseif($item->getTypeId() === ItemTypeIds::POWDER_SNOW_BUCKET){
			//TODO: powder snow cauldron
		}elseif($item instanceof Potion || $item instanceof SplashPotion){
			if($item->getType() === PotionType::WATER){
				$this->fill(WaterCauldron::WATER_BOTTLE_FILL_AMOUNT, VanillaBlocks::WATER_CAULDRON(), $item, VanillaItems::GLASS_BOTTLE(), $returnedItems);
			}else{
				$this->fill(PotionCauldron::POTION_FILL_AMOUNT, VanillaBlocks::POTION_CAULDRON()->setPotionItem($item), $item, VanillaItems::GLASS_BOTTLE(), $returnedItems);
			}
		}

		return true;
	}

	public function onNearbyBlockChange() : void{
		$world = $this->position->getWorld();
		if($world->getBlock($this->position->up())->getTypeId() === BlockTypeIds::WATER){
			$cauldron = VanillaBlocks::WATER_CAULDRON()->setFillLevel(FillableCauldron::MAX_FILL_LEVEL);
			$world->setBlock($this->position, $cauldron);
			$world->addSound($this->position->add(0.5, 0.5, 0.5), $cauldron->getFillSound());
		}
	}
}
