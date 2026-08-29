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

namespace quark\block\inventory;

use quark\block\BlockTypeIds;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\item\ItemTypeIds;
use pocketmine\network\mcpe\protocol\BlockEventPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use quark\world\Position;
use quark\world\sound\ShulkerBoxCloseSound;
use quark\world\sound\ShulkerBoxOpenSound;
use quark\world\sound\Sound;

class ShulkerBoxInventory extends SimpleInventory implements BlockInventory{
	use AnimatedBlockInventoryTrait;

	public function __construct(Position $holder){
		$this->holder = $holder;
		parent::__construct(27);
	}

	protected function getOpenSound() : Sound{
		return new ShulkerBoxOpenSound();
	}

	protected function getCloseSound() : Sound{
		return new ShulkerBoxCloseSound();
	}

	public function canAddItem(Item $item) : bool{
		$blockTypeId = ItemTypeIds::toBlockTypeId($item->getTypeId());
		if($blockTypeId === BlockTypeIds::SHULKER_BOX || $blockTypeId === BlockTypeIds::DYED_SHULKER_BOX){
			return false;
		}
		return parent::canAddItem($item);
	}

	protected function animateBlock(bool $isOpen) : void{
		$holder = $this->getHolder();

		//event ID is always 1 for a chest
		$holder->getWorld()->broadcastPacketToViewers($holder, BlockEventPacket::create(BlockPosition::fromVector3($holder), 1, $isOpen ? 1 : 0));
	}
}
