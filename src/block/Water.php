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

namespace quark\block;

use quark\entity\Entity;
use quark\event\entity\EntityExtinguishEvent;
use quark\world\sound\BucketEmptyWaterSound;
use quark\world\sound\BucketFillWaterSound;
use quark\world\sound\Sound;

class Water extends Liquid{

	public function getLightFilter() : int{
		return 2;
	}

	public function getBucketFillSound() : Sound{
		return new BucketFillWaterSound();
	}

	public function getBucketEmptySound() : Sound{
		return new BucketEmptyWaterSound();
	}

	public function tickRate() : int{
		return 5;
	}

	public function getMinAdjacentSourcesToFormSource() : ?int{
		return 2;
	}

	public function onEntityInside(Entity $entity) : bool{
		$entity->resetFallDistance();
		if($entity->isOnFire()){
			$entity->extinguish(EntityExtinguishEvent::CAUSE_WATER);
		}
		return true;
	}
}
