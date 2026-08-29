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

use quark\block\utils\PillarRotation;
use quark\block\utils\PillarRotationTrait;
use quark\entity\Entity;

class HayBale extends Opaque implements PillarRotation{
	use PillarRotationTrait;

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 20;
	}

	public function onEntityLand(Entity $entity) : ?float{
		$entity->fallDistance *= 0.2;
		return null;
	}
}
