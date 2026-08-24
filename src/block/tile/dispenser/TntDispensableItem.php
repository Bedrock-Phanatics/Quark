<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\entity\Location;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\tnt\TntLimiter;

final class TntDispensableItem extends EntityDispensableItem{
	public function __construct(){
		parent::__construct(static fn(Location $location, \pocketmine\item\Item $_item, ?Player $_player) : PrimedTNT => new PrimedTNT($location));
	}

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$spawn = Position::fromObject($side_pos->add(0.5, 0.5, 0.5), $pos->getWorld());
		if(!TntLimiter::tryIgnite($spawn, $pos)){
			return false;
		}
		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}
