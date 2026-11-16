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

use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use function abs;

final class PlacementFacing{

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns the face pointing back at a player placing a block at the given position, like a dispenser's front.
	 * Up or down is only chosen when the player stands right next to the block.
	 */
	public static function towardsPlayer(Player $player, Vector3 $pos) : int{
		$playerPos = $player->getPosition();
		if(abs($playerPos->x - $pos->x) < 2 && abs($playerPos->z - $pos->z) < 2){
			$y = $player->getEyePos()->y;
			if($y - $pos->y > 2){
				return Facing::UP;
			}
			if($pos->y - $y > 0){
				return Facing::DOWN;
			}
		}
		return Facing::opposite($player->getHorizontalFacing());
	}
}
