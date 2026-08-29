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
use quark\inventory\transaction\EnchantingTransaction;
use quark\item\enchantment\EnchantingOption;
use quark\item\Item;
use quark\player\Player;

/**
 * Called when a player enchants an item using an enchanting table.
 */
class PlayerItemEnchantEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private readonly EnchantingTransaction $transaction,
		private readonly EnchantingOption $option,
		private readonly Item $inputItem,
		private readonly Item $outputItem,
		private readonly int $cost
	){
		$this->player = $player;
	}

	/**
	 * Returns the inventory transaction involved in this enchant event.
	 */
	public function getTransaction() : EnchantingTransaction{
		return $this->transaction;
	}

	/**
	 * Returns the enchantment option used.
	 */
	public function getOption() : EnchantingOption{
		return $this->option;
	}

	/**
	 * Returns the item to be enchanted.
	 */
	public function getInputItem() : Item{
		return clone $this->inputItem;
	}

	/**
	 * Returns the enchanted item.
	 */
	public function getOutputItem() : Item{
		return clone $this->outputItem;
	}

	/**
	 * Returns the number of XP levels and lapis that will be subtracted after enchanting
	 * if the player is not in creative mode.
	 */
	public function getCost() : int{
		return $this->cost;
	}
}
