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
 * TODO: Implement the jigsaw edit UI and structure pool generation.
 */
class Jigsaw extends Opaque implements AnyFacing{
	use AnyFacingTrait {
		describeBlockOnlyState as describeFacing;
	}

	public const MAX_ROTATION = 3;

	/** Rotation of the top face, only meaningful when facing up or down. */
	protected int $rotation = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$this->describeFacing($w);
		$w->boundedIntAuto(0, self::MAX_ROTATION, $this->rotation);
	}

	public function getRotation() : int{ return $this->rotation; }

	/** @return $this */
	public function setRotation(int $rotation) : self{
		if($rotation < 0 || $rotation > self::MAX_ROTATION){
			throw new \InvalidArgumentException("Rotation must be in range 0 ... " . self::MAX_ROTATION);
		}
		$this->rotation = $rotation;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			//TODO: Set the rotation from the player's direction when facing up or down (vanilla's mapping is unverified).
			$this->facing = PlacementFacing::towardsPlayer($player, $this->position);
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
