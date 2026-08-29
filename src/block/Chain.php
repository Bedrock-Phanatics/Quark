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
use quark\block\utils\SupportType;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;

class Chain extends Transparent implements PillarRotation{
	use PillarRotationTrait;

	public function getSupportType(int $facing) : SupportType{
		return $this->axis === Axis::Y && Facing::axis($facing) === Axis::Y ? SupportType::CENTER : SupportType::NONE;
	}

	protected function recalculateCollisionBoxes() : array{
		$bb = AxisAlignedBB::one();
		foreach([Axis::Y, Axis::Z, Axis::X] as $axis){
			if($axis !== $this->axis){
				$bb->squash($axis, 13 / 32);
			}
		}
		return [$bb];
	}
}
