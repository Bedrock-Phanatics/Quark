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

use quark\block\inventory\EnchantInventory;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\event\Event;
use quark\item\enchantment\EnchantingOption;
use quark\player\Player;
use quark\utils\Utils;
use function count;

/**
 * Called when a player inserts an item into an enchanting table's input slot.
 * The options provided by the event will be shown on the enchanting table menu.
 */
class PlayerEnchantingOptionsRequestEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param EnchantingOption[] $options
	 */
	public function __construct(
		Player $player,
		private readonly EnchantInventory $inventory,
		private array $options
	){
		$this->player = $player;
	}

	public function getInventory() : EnchantInventory{
		return $this->inventory;
	}

	/**
	 * @return EnchantingOption[]
	 */
	public function getOptions() : array{
		return $this->options;
	}

	/**
	 * @param EnchantingOption[] $options
	 */
	public function setOptions(array $options) : void{
		Utils::validateArrayValueType($options, function(EnchantingOption $_) : void{ });
		if(($optionCount = count($options)) > 3){
			throw new \LogicException("The maximum number of options for an enchanting table is 3, but $optionCount have been passed");
		}

		$this->options = $options;
	}
}
