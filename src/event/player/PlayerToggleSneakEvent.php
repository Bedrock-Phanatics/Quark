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

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\player\Player;

class PlayerToggleSneakEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		protected bool $isSneaking,
		protected bool $isSneakPressed
	){
		$this->player = $player;
	}

	public function isSneaking() : bool{
		return $this->isSneaking;
	}

	/**
	 * Returns whether the player is pressing the sneak key.
	 * The player may still be sneaking even if this is false due to gameplay mechanics (e.g. releasing sneak while in a 1.5 block high space).
	 */
	public function isSneakPressed() : bool{
		return $this->isSneakPressed;
	}
}
