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

use quark\block\utils\WoodMaterial;
use quark\block\utils\WoodTypeTrait;

class Planks extends Opaque implements WoodMaterial{
	use WoodTypeTrait;

	public function getFuelTime() : int{
		return $this->woodType->isFlammable() ? 300 : 0;
	}

	public function getFlameEncouragement() : int{
		return $this->woodType->isFlammable() ? 5 : 0;
	}

	public function getFlammability() : int{
		return $this->woodType->isFlammable() ? 20 : 0;
	}
}
