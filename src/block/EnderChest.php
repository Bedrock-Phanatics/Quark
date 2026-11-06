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

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\tile\EnderChest as TileEnderChest;
use quark\block\utils\AnimatedContainerLike;
use quark\block\utils\AnimatedContainerLikeTrait;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\MenuAccessorTrait;
use quark\block\utils\SupportType;
use quark\item\Item;
use quark\network\mcpe\protocol\packet\BlockEventPacket;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;
use quark\world\sound\EnderChestCloseSound;
use quark\world\sound\EnderChestOpenSound;
use quark\world\sound\Sound;
use function max;

class EnderChest extends Transparent implements AnimatedContainerLike, HorizontalFacing{
	use AnimatedContainerLikeTrait {
		onViewerAdded as private animatedOnViewerAdded;
		onViewerRemoved as private animatedOnViewerRemoved;
	}
	use MenuAccessorTrait;
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

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::OBSIDIAN()->asItem()->setCount(8)
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function isOpeningObstructed() : bool{
		return !$this->getSide(Facing::UP)->isTransparent();
	}

	protected function newMenu(Player $player, Position $position) : InventoryWindow{
		return new BlockInventoryWindow($player, $player->getEnderInventory(), $position);
	}

	//every player sees their own contents, so viewers are counted on the tile instead of the inventory
	protected function getViewerCount() : int{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileEnderChest ? $tile->getViewerCount() : 0;
	}

	private function updateViewerCount(int $delta) : void{
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileEnderChest){
			//the tile may have been replaced while someone had it open, so never go below zero
			$tile->setViewerCount(max(0, $tile->getViewerCount() + $delta));
		}
	}

	public function onViewerAdded() : void{
		$this->updateViewerCount(1);
		$this->animatedOnViewerAdded();
	}

	public function onViewerRemoved() : void{
		$this->animatedOnViewerRemoved();
		$this->updateViewerCount(-1);
	}

	protected function getOpenSound() : Sound{
		return new EnderChestOpenSound();
	}

	protected function getCloseSound() : Sound{
		return new EnderChestCloseSound();
	}

	protected function playAnimationVisual(Position $position, bool $isOpen) : void{
		//event ID is always 1 for a chest
		$position->getWorld()->broadcastPacketToViewers($position, new BlockEventPacket(blockPosition: $position->floor(), eventType: 1, eventValue: $isOpen ? 1 : 0));
	}
}
