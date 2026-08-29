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

use quark\block\tile\ShulkerBox as TileShulkerBox;
use quark\block\utils\AnyFacing;
use quark\block\utils\AnyFacingTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

class ShulkerBox extends Opaque implements AnyFacing{
	use AnyFacingTrait;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		//NOOP - we don't read or write facing here, because the tile persists it
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$shulker = $this->position->getWorld()->getTile($this->position);
		if($shulker instanceof TileShulkerBox){
			$shulker->setFacing($this->facing);
		}
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$shulker = $this->position->getWorld()->getTile($this->position);
		if($shulker instanceof TileShulkerBox){
			$this->facing = $shulker->getFacing();
		}

		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face;

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function addDataFromTile(TileShulkerBox $tile, Item $item) : void{
		$shulkerNBT = $tile->getCleanedNBT();
		if($shulkerNBT !== null){
			$item->setNamedTag($shulkerNBT);
		}
		if($tile->hasName()){
			$item->setCustomName($tile->getName());
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drop = $this->asItem();
		if(($tile = $this->position->getWorld()->getTile($this->position)) instanceof TileShulkerBox){
			$this->addDataFromTile($tile, $drop);
		}
		return [$drop];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		$result = parent::getPickedItem($addUserData);
		if($addUserData && ($tile = $this->position->getWorld()->getTile($this->position)) instanceof TileShulkerBox){
			$this->addDataFromTile($tile, $result);
		}
		return $result;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){

			$shulker = $this->position->getWorld()->getTile($this->position);
			if($shulker instanceof TileShulkerBox){
				if(
					$this->getSide($this->facing)->isSolid() ||
					!$shulker->canOpenWith($item->getCustomName())
				){
					return true;
				}

				$player->setCurrentWindow($shulker->getInventory());
			}
		}

		return true;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
