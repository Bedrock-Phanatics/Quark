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

namespace quark\event\world;

use quark\world\World;

/**
 * Called when a world's difficulty is changed.
 */
final class WorldDifficultyChangeEvent extends WorldEvent{

	public function __construct(
		World $world,
		private int $oldDifficulty,
		private int $newDifficulty
	){
		parent::__construct($world);
	}

	public function getOldDifficulty() : int{ return $this->oldDifficulty; }

	public function getNewDifficulty() : int{ return $this->newDifficulty; }
}
