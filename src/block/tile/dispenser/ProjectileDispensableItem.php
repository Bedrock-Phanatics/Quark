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

use quark\entity\Entity;
use quark\item\Item;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\World;

class ProjectileDispensableItem extends EntityDispensableItem{

	protected function onEntityCreate(Entity $entity, Vector3 $side_pos, World $world, int $facing, Item $item, ?Player $player = null) : void{
		$entity->setMotion((new Vector3(0.0, 0.0, 0.0))->getSide($facing));
	}
}
