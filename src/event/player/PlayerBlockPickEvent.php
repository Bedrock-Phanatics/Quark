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
use quark\item\Item;
use quark\player\Player;

/**
 * Called when a player middle-clicks on a block to get an item in creative mode.
 */
class PlayerBlockPickEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private Block $blockClicked,
		private Item $resultItem
	){
		$this->player = $player;
	}

	public function getBlock() : Block{
		return $this->blockClicked;
	}

	public function getResultItem() : Item{
		return $this->resultItem;
	}
}
