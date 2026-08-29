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

use quark\block\Block;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\player\Player;

class PlayerRespawnAnchorUseEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public const ACTION_EXPLODE = 0;
	public const ACTION_SET_SPAWN = 1;

	public function __construct(
		Player $player,
		protected Block $block,
		private int $action = self::ACTION_EXPLODE
	){
		$this->player = $player;
	}

	public function getBlock() : Block{
		return $this->block;
	}

	public function getAction() : int{
		return $this->action;
	}

	public function setAction(int $action) : void{
		$this->action = $action;
	}
}
