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

namespace quark\item;

use quark\entity\Living;
use quark\player\Player;

abstract class Food extends Item implements FoodSourceItem{
	public function requiresHunger() : bool{
		return true;
	}

	public function getResidue() : Item{
		return VanillaItems::AIR();
	}

	public function getAdditionalEffects() : array{
		return [];
	}

	public function onConsume(Living $consumer) : void{

	}

	public function canStartUsingItem(Player $player) : bool{
		return !$this->requiresHunger() || $player->canEat();
	}
}
