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

use quark\block\utils\Ageable;
use quark\block\utils\AgeableTrait;
use quark\block\utils\StaticSupportTrait;
use quark\block\utils\SupportType;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;

/**
 * Kelp is always underwater, so it needs a water source to be placed in and leaves one behind when removed.
 * TODO: Implement growth (vanilla also randomises the age on placement to cap the height), bone meal and shearing the tip.
 */
class Kelp extends Transparent implements Ageable{
	use AgeableTrait;
	use StaticSupportTrait {
		canBePlacedAt as supportedWhenPlacedAt;
	}

	public const MAX_AGE = 25;

	public function isSolid() : bool{
		return false;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$down = $block->getSide(Facing::DOWN);
		return $down instanceof Kelp || $down->getSupportType(Facing::UP) === SupportType::FULL;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return $blockReplace instanceof Water && $blockReplace->isSource() && $this->supportedWhenPlacedAt($blockReplace, $clickVector, $face, $isClickedBlock);
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!parent::onBreak($item, $player, $returnedItems)){
			return false;
		}
		$this->position->getWorld()->setBlock($this->position, VanillaBlocks::WATER());
		return true;
	}
}
