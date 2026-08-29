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

namespace quark\event\player;

use quark\player\Player;

/**
 * Called after a player is sent a chunk as part of their view radius.
 */
final class PlayerPostChunkSendEvent extends PlayerEvent{

	public function __construct(
		Player $player,
		private int $chunkX,
		private int $chunkZ
	){
		$this->player = $player;
	}

	public function getChunkX() : int{ return $this->chunkX; }

	public function getChunkZ() : int{ return $this->chunkZ; }
}
