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

use quark\block\utils\FrontAndTop;
use quark\block\utils\PlacementFacing;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * TODO: Implement the crafter UI, slot toggling and crafting when a redstone signal is received.
 */
class Crafter extends Opaque{
	protected FrontAndTop $orientation = FrontAndTop::DOWN_EAST;
	protected bool $crafting = false;
	protected bool $triggered = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->orientation);
		$w->bool($this->crafting);
		$w->bool($this->triggered);
	}

	public function getOrientation() : FrontAndTop{ return $this->orientation; }

	/** @return $this */
	public function setOrientation(FrontAndTop $orientation) : self{
		$this->orientation = $orientation;
		return $this;
	}

	public function isCrafting() : bool{ return $this->crafting; }

	/** @return $this */
	public function setCrafting(bool $crafting) : self{
		$this->crafting = $crafting;
		return $this;
	}

	public function isTriggered() : bool{ return $this->triggered; }

	/** @return $this */
	public function setTriggered(bool $triggered) : self{
		$this->triggered = $triggered;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$front = PlacementFacing::towardsPlayer($player, $this->position);
			$this->orientation = FrontAndTop::fromFacing($front, match($front){
				Facing::DOWN => Facing::opposite($player->getHorizontalFacing()),
				Facing::UP => $player->getHorizontalFacing(),
				default => Facing::UP
			});
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}
}
