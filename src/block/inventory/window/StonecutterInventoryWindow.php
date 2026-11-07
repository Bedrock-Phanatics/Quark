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

namespace quark\block\inventory\window;

use quark\inventory\SimpleInventory;
use quark\player\Player;
use quark\player\TemporaryInventoryWindow;
use quark\world\Position;

final class StonecutterInventoryWindow extends BlockInventoryWindow implements TemporaryInventoryWindow{
	public const SLOT_INPUT = 0;

	public function __construct(Player $viewer, Position $holder){
		parent::__construct($viewer, new SimpleInventory(1), $holder);
	}
}
