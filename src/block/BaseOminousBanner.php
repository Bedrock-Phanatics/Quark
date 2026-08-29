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

use quark\block\tile\Banner as TileBanner;
use quark\block\utils\DyeColor;
use quark\block\utils\SupportType;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use function assert;

abstract class BaseOminousBanner extends Transparent{

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileBanner);
		$tile->setBaseColor(DyeColor::WHITE);
		$tile->setPatterns([]);
		$tile->setType(TileBanner::TYPE_OMINOUS);
	}

	public function isSolid() : bool{
		return false;
	}

	public function getMaxStackSize() : int{
		return 16;
	}

	public function getFuelTime() : int{
		return 300;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedBy(Block $block) : bool{
		return $block->isSolid();
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if(!$this->canBeSupportedBy($blockReplace->getSide($this->getSupportingFace()))){
			return false;
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	abstract protected function getSupportingFace() : int;

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedBy($this->getSide($this->getSupportingFace()))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function asItem() : Item{
		return VanillaItems::OMINOUS_BANNER();
	}
}
