<?php

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\World;

class ProjectileDispensableItem extends EntityDispensableItem{

	protected function onEntityCreate(Entity $entity, Vector3 $side_pos, World $world, int $facing, Item $item, ?Player $player = null) : void{
		$entity->setMotion((new Vector3(0.0, 0.0, 0.0))->getSide($facing));
	}
}