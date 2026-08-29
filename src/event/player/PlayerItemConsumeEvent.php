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
use quark\utils\Utils;

/**
 * Called when a player eats something
 */
class PlayerItemConsumeEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param Item[] $residue
	 */
	public function __construct(
		Player $player,
		private Item $item,
		private array $residue = []
	){
		$this->player = $player;
	}

	public function getItem() : Item{
		return clone $this->item;
	}

	/**
	 * Returns the leftover items returned to the player after consuming the item.
	 * For example, glass bottles for potions, bowls for beetroot soup, etc.
	 *
	 * @return Item[]
	 */
	public function getResidue() : array{
		return Utils::cloneObjectArray($this->residue);
	}

	/**
	 * Sets the items returned to the player after consuming the item.
	 *
	 * @param Item[] $items
	 */
	public function setResidue(array $items) : void{
		Utils::validateArrayValueType($items, function(Item $_) : void{});

		$this->residue = Utils::cloneObjectArray($items);
	}
}
