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
use pocketmine\math\Vector3;

/**
 * "Flowable" blocks are destroyed if water flows into the same space as the block. These blocks usually don't have any
 * collision boxes, and can't provide support for other blocks.
 */
abstract class Flowable extends Transparent{

	public function canBeFlowedInto() : bool{
		return true;
	}

	public function isSolid() : bool{
		return false;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return (!$this->canBeFlowedInto() || !$blockReplace instanceof Liquid) &&
			parent::canBePlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}
}
