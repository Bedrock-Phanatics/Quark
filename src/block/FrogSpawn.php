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
use quark\math\Facing;

/**
 * TODO: Implement hatching into tadpoles and breaking when entities land on it.
 */
class FrogSpawn extends Flowable{
	use StaticSupportTrait;

	public function canBeFlowedInto() : bool{
		return false;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$down = $block->getSide(Facing::DOWN);
		return $down instanceof Water && $down->isSource();
	}

	public function getDrops(Item $item) : array{
		return [];
	}
}
