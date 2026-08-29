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

use quark\entity\Location;
use quark\entity\object\PrimedTNT;
use quark\inventory\Inventory;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\Position;
use quark\world\tnt\TntLimiter;

final class TntDispensableItem extends EntityDispensableItem{
	public function __construct(){
		parent::__construct(static fn(Location $location, \quark\item\Item $_item, ?Player $_player) : PrimedTNT => new PrimedTNT($location));
	}

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$spawn = Position::fromObject($side_pos->add(0.5, 0.5, 0.5), $pos->getWorld());
		if(!TntLimiter::tryIgnite($spawn, $pos)){
			return false;
		}
		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}
