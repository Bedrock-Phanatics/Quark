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

use quark\crafting\FurnaceType;
use quark\item\Item;

class SoulCampfire extends Campfire{

	public function getLightLevel() : int{
		return $this->lit ? 10 : 0;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaBlocks::SOUL_SOIL()->asItem()
		];
	}

	protected function getEntityCollisionDamage() : int{
		return 2;
	}

	protected function getFurnaceType() : FurnaceType{
		return FurnaceType::SOUL_CAMPFIRE;
	}
}
