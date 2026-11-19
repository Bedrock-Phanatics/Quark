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

use quark\block\utils\AnyFacing;
use quark\block\utils\AnyFacingTrait;
use quark\block\utils\PlacementFacing;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Used for impulse, chain and repeating command blocks, which only differ by type.
 * TODO: Implement opening the command block UI for operators and running commands on redstone or tick triggers.
 * Placement and editing must be limited to operators before commands are ever executed.
 */
class CommandBlock extends Opaque implements AnyFacing{
	use AnyFacingTrait {
		describeBlockOnlyState as describeFacing;
	}

	protected bool $conditional = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->bool($this->conditional);
	}

	public function isConditional() : bool{ return $this->conditional; }

	/** @return $this */
	public function setConditional(bool $conditional) : self{
		$this->conditional = $conditional;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = PlacementFacing::towardsPlayer($player, $this->position);
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
