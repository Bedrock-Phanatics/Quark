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

use quark\block\utils\MultiAnyFacing;
use quark\block\utils\MultiAnySupportTrait;
use quark\block\utils\SupportType;
use quark\item\Item;
use function count;
use function max;

/**
 * TODO: Implement spreading from sculk catalyst charges.
 */
class SculkVein extends Transparent implements MultiAnyFacing{
	use MultiAnySupportTrait;

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	protected function getInitialPlaceFaces(Block $blockReplace) : array{
		return $blockReplace instanceof SculkVein ? $blockReplace->faces : [];
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	public function getSilkTouchDrops(Item $item) : array{
		return [$this->asItem()->setCount(max(1, count($this->faces)))];
	}
}
