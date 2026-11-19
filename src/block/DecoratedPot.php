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

use pocketmine\nbt\tag\CompoundTag;
use quark\block\tile\DecoratedPot as TileDecoratedPot;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\SupportType;
use quark\item\Item;
use quark\math\AxisAlignedBB;
use quark\math\Facing;

/**
 * TODO: Implement shattering into sherds and bricks when broken with a tool, and vanilla's item format for sherds.
 */
class DecoratedPot extends Transparent implements HorizontalFacing{
	use FacesOppositePlacingPlayerTrait;

	public const int DEFAULT_FACING = Facing::SOUTH;

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(1 / 16, 0, 1 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->withSherds($this->asItem())];
	}

	public function getPickedItem(bool $addUserData = false) : Item{
		return $this->withSherds(parent::getPickedItem(false));
	}

	private function withSherds(Item $item) : Item{
		$tile = $this->position->getWorld()->getTile($this->position);
		$sherds = $tile instanceof TileDecoratedPot ? $tile->getSherds() : null;
		if($sherds !== null){
			$item->setCustomBlockData(CompoundTag::create()->setTag(TileDecoratedPot::TAG_SHERDS, $sherds));
		}
		return $item;
	}
}
