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
use quark\block\VanillaBlocks;
use quark\entity\Location;
use quark\entity\object\FallingBlock;
use quark\utils\AssumptionFailedError;
use quark\world\Position;
use quark\world\sound\Sound;

/**
 * This trait handles falling behaviour for blocks that need them.
 * TODO: convert this into a dynamic component
 * @see Fallable
 */
trait FallableTrait{

	abstract protected function getPosition() : Position;

	public function onNearbyBlockChange() : void{
		$pos = $this->getPosition();
		$world = $pos->getWorld();
		$x = $pos->getFloorX();
		$y = $pos->getFloorY();
		$z = $pos->getFloorZ();
		if($world->getBlockAt($x, $y - 1, $z)->canBeReplaced()){
			$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());

			$block = $this;
			if(!($block instanceof Block)) throw new AssumptionFailedError(__TRAIT__ . " should only be used by Blocks");

			$startY = $world->startFallingBlock($x, $y, $z, FallingBlock::canFallFast($block));
			$fall = new FallingBlock(new Location($x + 0.5, $startY, $z + 0.5, $world, 0, 0), $block);
			$fall->fallDistance = $y - $startY;
			$fall->spawnToAll();
		}
	}

	public function tickFalling() : ?Block{
		return null;
	}

	public function onHitGround(FallingBlock $blockEntity) : bool{
		return true;
	}

	public function getFallDamagePerBlock() : float{
		return 0.0;
	}

	public function getMaxFallDamage() : float{
		return 0.0;
	}

	public function getLandSound() : ?Sound{
		return null;
	}
}
