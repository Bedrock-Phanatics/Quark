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

use quark\block\utils\FortuneDropHelper;
use quark\item\Item;
use quark\item\VanillaItems;
use function mt_rand;

class Potato extends Crops{

	public function getDropsForCompatibleTool(Item $item) : array{
		$result = [
			//min/max would be 2-5 in Java
			VanillaItems::POTATO()->setCount($this->age >= self::MAX_AGE ? FortuneDropHelper::binomial($item, 1) : 1)
		];
		if($this->age >= self::MAX_AGE && mt_rand(0, 49) === 0){
			$result[] = VanillaItems::POISONOUS_POTATO();
		}
		return $result;
	}

	public function asItem() : Item{
		return VanillaItems::POTATO();
	}
}
