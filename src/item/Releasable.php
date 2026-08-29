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

use quark\player\Player;

/**
 * Implemented by items which can be used by pressing and holding the "use item" button in-game.
 * The player's arm will appear to be raised and the "using item" flag will be set.
 * Examples of this type of behaviour include bows, food and spyglasses.
 *
 * @see Player::isUsingItem()
 * @see Player::getItemUseDuration()
 */
interface Releasable{

	/**
	 * Returns whether the player can currently trigger the press-and-hold behaviour of the item.
	 * For example, bows return whether the player has an arrow that can be fired.
	 */
	public function canStartUsingItem(Player $player) : bool;

}
