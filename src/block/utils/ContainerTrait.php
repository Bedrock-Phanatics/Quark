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

namespace quark\block\utils;

use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\tile\ContainerTile;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\math\Vector3;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;

/**
 * Implements Container for blocks whose contents are stored in a ContainerTile.
 */
trait ContainerTrait{

	/**
	 * @see Block::onInteract()
	 * @param Item[] &$returnedItems
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player && !$this->isOpeningObstructed() && $this->canOpenWith($item->getCustomName())){
			$this->openToUnchecked($player);
		}
		return true;
	}

	protected function newMenu(Player $player, Inventory $inventory, Position $position) : InventoryWindow{
		return new BlockInventoryWindow($player, $inventory, $position);
	}

	public function isOpeningObstructed() : bool{
		return false;
	}

	abstract public function getPosition() : Position;

	protected function getContainerTile() : ?ContainerTile{
		$position = $this->getPosition();
		$tile = $position->getWorld()->getTile($position);
		return $tile instanceof ContainerTile ? $tile : null;
	}

	public function canOpenWith(string $key) : bool{
		return $this->getContainerTile()?->canOpenWith($key) ?? false;
	}

	public function openToUnchecked(Player $player) : bool{
		$inventory = $this->getInventory();
		return $inventory !== null && $player->setCurrentWindow($this->newMenu($player, $inventory, $this->getPosition()));
	}

	public function getInventory() : ?Inventory{
		return $this->getContainerTile()?->getInventory();
	}
}
