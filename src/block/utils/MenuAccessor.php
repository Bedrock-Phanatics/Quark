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

use quark\player\Player;

/**
 * Blocks which open a menu when interacted with, either a container like a chest or a workstation like a crafting
 * table.
 */
interface MenuAccessor{

	/**
	 * Returns whether something is stopping the menu from opening, e.g. a solid block on top of a chest.
	 */
	public function isOpeningObstructed() : bool;

	/**
	 * Opens the menu to the player, skipping obstruction and lock checks. Use this to open menus remotely.
	 *
	 * Returns false if the menu couldn't be opened, e.g. the open event was cancelled or the container's tile is missing.
	 */
	public function openToUnchecked(Player $player) : bool;
}
