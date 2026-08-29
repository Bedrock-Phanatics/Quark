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

use quark\block\inventory\EnderChestInventory;
use quark\block\tile\EnderChest as TileEnderChest;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\SupportType;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;

class EnderChest extends Transparent implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	public function getLightLevel() : int{
		return 7;
	}

	protected function recalculateCollisionBoxes() : array{
		//these are slightly bigger than in PC
		return [AxisAlignedBB::one()->contract(0.025, 0, 0.025)->trim(Facing::UP, 0.05)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$enderChest = $this->position->getWorld()->getTile($this->position);
			if($enderChest instanceof TileEnderChest && $this->getSide(Facing::UP)->isTransparent()){
				$enderChest->setViewerCount($enderChest->getViewerCount() + 1);
				$player->setCurrentWindow(new EnderChestInventory($this->position, $player->getEnderInventory()));
			}
		}

		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::OBSIDIAN()->asItem()->setCount(8)
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
