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
use quark\entity\object\FallingBlock;
use quark\world\sound\Sound;

interface Fallable{

	/**
	 * Called every tick by FallingBlock to update the falling state of this block. Used by concrete to check when it
	 * hits water.
	 * Return null if you don't want to change the usual behaviour.
	 */
	public function tickFalling() : ?Block;

	/**
	 * Called when FallingBlock hits the ground.
	 * Returns whether the block should be placed.
	 */
	public function onHitGround(FallingBlock $blockEntity) : bool;

	/**
	 * Returns the damage caused per fallen block. This is multiplied by the fall distance (and capped according to
	 * {@link Fallable::getMaxFallDamage()}) to calculate the damage dealt to any entities who intersect with the block
	 * when it hits the ground.
	 */
	public function getFallDamagePerBlock() : float;

	/**
	 * Returns the maximum damage the block can deal to an entity when it hits the ground.
	 */
	public function getMaxFallDamage() : float;

	/**
	 * Returns the sound that will be played when FallingBlock hits the ground.
	 */
	public function getLandSound() : ?Sound;
}
