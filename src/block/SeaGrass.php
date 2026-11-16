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

use quark\block\utils\SeaGrassType;
use quark\block\utils\StaticSupportTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;

/**
 * Like kelp, seagrass needs a water source and leaves one behind when removed.
 * TODO: Implement bone meal growing tall seagrass and spreading nearby.
 */
class SeaGrass extends Transparent{
	use StaticSupportTrait {
		canBePlacedAt as supportedWhenPlacedAt;
	}

	protected SeaGrassType $seaGrassType = SeaGrassType::SINGLE;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->seaGrassType);
	}

	public function getSeaGrassType() : SeaGrassType{ return $this->seaGrassType; }

	/** @return $this */
	public function setSeaGrassType(SeaGrassType $type) : self{
		$this->seaGrassType = $type;
		return $this;
	}

	public function isSolid() : bool{
		return false;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return match($this->seaGrassType){
			SeaGrassType::DOUBLE_TOP => ($down = $block->getSide(Facing::DOWN)) instanceof SeaGrass && $down->seaGrassType === SeaGrassType::DOUBLE_BOTTOM,
			SeaGrassType::DOUBLE_BOTTOM => ($up = $block->getSide(Facing::UP)) instanceof SeaGrass && $up->seaGrassType === SeaGrassType::DOUBLE_TOP &&
				$block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL,
			SeaGrassType::SINGLE => $block->getSide(Facing::DOWN)->getSupportType(Facing::UP) === SupportType::FULL,
		};
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
