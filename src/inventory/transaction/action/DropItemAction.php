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

namespace quark\inventory\transaction\action;

use quark\event\player\PlayerDropItemEvent;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\Player;

/**
 * Represents an action involving dropping an item into the world.
 */
class DropItemAction extends InventoryAction{

	public function __construct(Item $targetItem){
		parent::__construct(VanillaItems::AIR(), $targetItem);
	}

	public function validate(Player $source) : void{
		if($this->targetItem->isNull()){
			throw new TransactionValidationException("Cannot drop an empty itemstack");
		}
		if($this->targetItem->getCount() > $this->targetItem->getMaxStackSize()){
			throw new TransactionValidationException("Target item exceeds item type max stack size");
		}
	}

	public function onPreExecute(Player $source) : bool{
		$ev = new PlayerDropItemEvent($source, $this->targetItem);
		if($source->isSpectator()){
			$ev->cancel();
		}
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}

		return true;
	}

	/**
	 * Drops the target item in front of the player.
	 */
	public function execute(Player $source) : void{
		$source->dropItem($this->targetItem);
	}
}
