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

namespace quark\block\utils;

use quark\block\Block;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

trait PillarRotationTrait{
	protected int $axis = Axis::Y;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->axis($this->axis);
	}

	/** @see Axis */
	public function getAxis() : int{ return $this->axis; }

	/** @return $this */
	public function setAxis(int $axis) : self{
		$this->axis = $axis;
		return $this;
	}

	/**
	 * @see Block::place()
	 */
	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->axis = Facing::axis($face);
		/** @see Block::place() */
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}
}
