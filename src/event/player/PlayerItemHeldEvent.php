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

namespace quark\event\player;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\item\Item;
use quark\player\Player;

/**
 * Called when a player's held item changes.
 * This could be because they selected a different hotbar slot, or because the item in the selected hotbar slot was
 * changed.
 */
class PlayerItemHeldEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private Item $item,
		private int $hotbarSlot
	){
		$this->player = $player;
	}

	/**
	 * Returns the hotbar slot the player is attempting to hold.
	 *
	 * NOTE: This event is called BEFORE the slot is equipped server-side. Setting the player's held item during this
	 * event will result in the **old** slot being changed, not this one.
	 *
	 * To change the item in the slot that the player is attempting to hold, set the slot that this function reports.
	 */
	public function getSlot() : int{
		return $this->hotbarSlot;
	}

	/**
	 * Returns the item in the slot that the player is trying to equip.
	 */
	public function getItem() : Item{
		return clone $this->item;
	}
}
