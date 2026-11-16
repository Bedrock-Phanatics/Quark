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

use quark\block\utils\LeavesType;
use quark\block\utils\StaticSupportTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\math\Facing;

/**
 * Players can only place standing propagules; hanging ones come from mangrove leaves.
 * TODO: Implement growing into a mangrove tree, hanging propagule growth stages and bone meal on mangrove leaves.
 */
class MangrovePropagule extends Flowable{
	use StaticSupportTrait;

	public const MAX_STAGE = 4;

	protected bool $hanging = false;
	protected int $stage = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->hanging);
		$w->boundedIntAuto(0, self::MAX_STAGE, $this->stage);
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	public function getStage() : int{ return $this->stage; }

	/** @return $this */
	public function setStage(int $stage) : self{
		if($stage < 0 || $stage > self::MAX_STAGE){
			throw new \InvalidArgumentException("Stage must be in range 0 ... " . self::MAX_STAGE);
		}
		$this->stage = $stage;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		if($this->hanging){
			$up = $block->getSide(Facing::UP);
			return $up instanceof Leaves && $up->getLeavesType() === LeavesType::MANGROVE;
		}
		$down = $block->getSide(Facing::DOWN);
		return $down->hasTypeTag(BlockTypeTags::DIRT) || $down->hasTypeTag(BlockTypeTags::MUD) || $down->getTypeId() === BlockTypeIds::CLAY;
	}

	public function getFuelTime() : int{
		return 100;
	}
}
