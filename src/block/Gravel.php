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

use quark\block\utils\Fallable;
use quark\block\utils\FallableTrait;
use quark\block\utils\FortuneDropHelper;
use quark\item\Item;
use quark\item\VanillaItems;

class Gravel extends Opaque implements Fallable{
	use FallableTrait;

	public function getDropsForCompatibleTool(Item $item) : array{
		if(FortuneDropHelper::bonusChanceDivisor($item, 10, 3)){
			return [
				VanillaItems::FLINT()
			];
		}

		return parent::getDropsForCompatibleTool($item);
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}
}
