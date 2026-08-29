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

use quark\inventory\transaction\TransactionValidationException;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\Player;

/**
 * This action is used by creative players to balance transactions involving the creative inventory menu.
 * The source item is the item being created ("taken" from the creative menu).
 */
class CreateItemAction extends InventoryAction{

	public function __construct(Item $sourceItem){
		parent::__construct($sourceItem, VanillaItems::AIR());
	}

	public function validate(Player $source) : void{
		if($source->hasFiniteResources()){
			throw new TransactionValidationException("Player has finite resources, cannot create items");
		}
		if(!$source->getCreativeInventory()->contains($this->sourceItem)){
			throw new TransactionValidationException("Creative inventory does not contain requested item");
		}
	}

	public function execute(Player $source) : void{
		//NOOP
	}
}
