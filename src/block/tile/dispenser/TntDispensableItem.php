<?php

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
