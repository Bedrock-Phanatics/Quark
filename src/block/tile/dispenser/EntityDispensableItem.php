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

namespace quark\block\tile\dispenser;

use Closure;
use quark\entity\Entity;
use quark\entity\Location;
use quark\inventory\Inventory;
use quark\item\Item;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\Position;
use quark\world\World;

class EntityDispensableItem implements DispensableItem{

	/**
	 * @param Closure(Location, Item, ?Player) : Entity $entity_creator
	 */
	public function __construct(
		private Closure $entity_creator
	){}

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		$item = $inventory->getItem($slot);
		$item_removed = $item->pop();
		$inventory->setItem($slot, $item);

		$entity = ($this->entity_creator)(Location::fromObject($side_pos->add(0.5, 0.5, 0.5), $world), $item_removed, $player);
		$this->onEntityCreate($entity, $side_pos, $world, $facing, $item_removed, $player);
		$entity->spawnToAll();
		return true;
	}

	protected function onEntityCreate(Entity $entity, Vector3 $side_pos, World $world, int $facing, Item $item, ?Player $player = null) : void{
	}
}
