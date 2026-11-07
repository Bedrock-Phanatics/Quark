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

use pocketmine\math\Vector3;
use quark\item\Item;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;

/**
 * Implements MenuAccessor for blocks whose menu has no stored contents, like crafting tables and anvils.
 */
trait MenuAccessorTrait{

	/**
	 * @see Block::onInteract()
	 * @param Item[] &$returnedItems
	 */
	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player && !$this->isOpeningObstructed()){
			$this->openToUnchecked($player);
		}
		return true;
	}

	abstract protected function newMenu(Player $player, Position $position) : InventoryWindow;

	abstract public function getPosition() : Position;

	public function isOpeningObstructed() : bool{
		return false;
	}

	public function openToUnchecked(Player $player) : bool{
		return $player->setCurrentWindow($this->newMenu($player, $this->getPosition()));
	}
}
