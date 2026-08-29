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

use quark\block\tile\EnderChest;
use quark\inventory\DelegateInventory;
use quark\inventory\Inventory;
use quark\inventory\PlayerEnderInventory;
use pocketmine\network\mcpe\protocol\BlockEventPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use quark\player\Player;
use quark\world\Position;
use quark\world\sound\EnderChestCloseSound;
use quark\world\sound\EnderChestOpenSound;
use quark\world\sound\Sound;

/**
 * EnderChestInventory is not a real inventory; it's just a gateway to the player's ender inventory.
 */
class EnderChestInventory extends DelegateInventory implements BlockInventory{
	use AnimatedBlockInventoryTrait {
		onClose as animatedBlockInventoryTrait_onClose;
	}

	public function __construct(
		Position $holder,
		private PlayerEnderInventory $inventory
	){
		parent::__construct($inventory);
		$this->holder = $holder;
	}

	public function getEnderInventory() : PlayerEnderInventory{
		return $this->inventory;
	}

	public function getViewerCount() : int{
		$enderChest = $this->getHolder()->getWorld()->getTile($this->getHolder());
		if(!$enderChest instanceof EnderChest){
			return 0;
		}
		return $enderChest->getViewerCount();
	}

	protected function getOpenSound() : Sound{
		return new EnderChestOpenSound();
	}

	protected function getCloseSound() : Sound{
		return new EnderChestCloseSound();
	}

	protected function animateBlock(bool $isOpen) : void{
		$holder = $this->getHolder();

		//event ID is always 1 for a chest
		$holder->getWorld()->broadcastPacketToViewers($holder, BlockEventPacket::create(BlockPosition::fromVector3($holder), 1, $isOpen ? 1 : 0));
	}

	public function onClose(Player $who) : void{
		$this->animatedBlockInventoryTrait_onClose($who);
		$enderChest = $this->getHolder()->getWorld()->getTile($this->getHolder());
		if($enderChest instanceof EnderChest){
			$enderChest->setViewerCount($enderChest->getViewerCount() - 1);
		}
	}
}
