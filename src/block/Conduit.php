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

use quark\block\utils\SupportType;
use quark\math\AxisAlignedBB;

/**
 * TODO: Implement activation by a prismarine frame, conduit power and attacking hostile mobs.
 */
class Conduit extends Transparent{

	public function getLightLevel() : int{
		return 15;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(5 / 16, 5 / 16, 5 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
