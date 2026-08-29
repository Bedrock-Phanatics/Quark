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
use quark\entity\Living;

final class Slime extends Transparent{

	public function getFrictionFactor() : float{
		return 0.8; //???
	}

	public function onEntityLand(Entity $entity) : ?float{
		if($entity instanceof Living && $entity->isSneaking()){
			return null;
		}
		$entity->resetFallDistance();
		return -$entity->getMotion()->y;
	}

	//TODO: slime blocks should slow entities walking on them to about 0.4x original speed
}
