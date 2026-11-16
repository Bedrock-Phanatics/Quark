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
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * The facing is the observed side; the redstone output comes out of the opposite side.
 * TODO: Implement detecting state changes on the observed side and emitting a redstone pulse.
 */
class Observer extends Opaque implements AnyFacing{
	use AnyFacingTrait {
		describeBlockOnlyState as describeFacing;
	}

	public const int DEFAULT_FACING = Facing::DOWN;

	protected bool $powered = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->bool($this->powered);
	}

	public function isPowered() : bool{ return $this->powered; }

	/** @return $this */
	public function setPowered(bool $powered) : self{
		$this->powered = $powered;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite(PlacementFacing::towardsPlayer($player, $this->position));
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}
}
