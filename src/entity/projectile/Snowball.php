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

namespace quark\entity\projectile;

use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use quark\event\entity\ProjectileHitEvent;
use quark\world\particle\SnowballPoofParticle;

class Snowball extends Throwable{
	public static function getNetworkTypeId() : string{ return EntityIds::SNOWBALL; }

	protected function onHit(ProjectileHitEvent $event) : void{
		$world = $this->getWorld();
		for($i = 0; $i < 6; ++$i){
			$world->addParticle($this->location, new SnowballPoofParticle());
		}
	}
}
