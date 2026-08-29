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

namespace quark\event\entity;

use quark\entity\Entity;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\inventory\Inventory;
use quark\item\Item;

/**
 * Called when an entity picks up an item, arrow, etc.
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityItemPickupEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Entity $collector,
		private Entity $origin,
		private Item $item,
		private ?Inventory $inventory
	){
		$this->entity = $collector;
	}

	public function getOrigin() : Entity{
		return $this->origin;
	}

	/**
	 * Items to be received
	 */
	public function getItem() : Item{
		return clone $this->item;
	}

	/**
	 * Change the items to receive.
	 */
	public function setItem(Item $item) : void{
		$this->item = clone $item;
	}

	/**
	 * Inventory to which received items will be added.
	 */
	public function getInventory() : ?Inventory{
		return $this->inventory;
	}

	/**
	 * Change the inventory to which received items are added.
	 */
	public function setInventory(?Inventory $inventory) : void{
		$this->inventory = $inventory;
	}

}
