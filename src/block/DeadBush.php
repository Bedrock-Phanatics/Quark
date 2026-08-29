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

use quark\block\utils\StaticSupportTrait;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\Facing;
use function mt_rand;

class DeadBush extends Flowable{
	use StaticSupportTrait;

	public function getDropsForIncompatibleTool(Item $item) : array{
		return [
			VanillaItems::STICK()->setCount(mt_rand(0, 2))
		];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 100;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return
			$supportBlock->hasTypeTag(BlockTypeTags::SAND) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			match($supportBlock->getTypeId()){
				//can't use DIRT tag here because it includes farmland
				BlockTypeIds::PODZOL,
				BlockTypeIds::MYCELIUM,
				BlockTypeIds::DIRT,
				BlockTypeIds::GRASS,
				BlockTypeIds::HARDENED_CLAY,
				BlockTypeIds::STAINED_CLAY => true,
				//TODO: moss block
				default => false,
			};
	}
}
