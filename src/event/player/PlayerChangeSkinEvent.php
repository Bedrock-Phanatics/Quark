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

use quark\entity\Skin;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\player\Player;

/**
 * Called when a player changes their skin in-game.
 */
class PlayerChangeSkinEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private Skin $oldSkin,
		private Skin $newSkin
	){
		$this->player = $player;
	}

	public function getOldSkin() : Skin{
		return $this->oldSkin;
	}

	public function getNewSkin() : Skin{
		return $this->newSkin;
	}

	public function setNewSkin(Skin $skin) : void{
		$this->newSkin = $skin;
	}
}
