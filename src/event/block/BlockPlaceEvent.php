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

namespace quark\event\block;

use quark\block\Block;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\event\Event;
use quark\item\Item;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Called when a player initiates a block placement action.
 * More than one block may be changed by a single placement action, for example when placing a door.
 */
class BlockPlaceEvent extends Event implements Cancellable{
	use CancellableTrait;

	public function __construct(
		protected Player $player,
		protected BlockTransaction $transaction,
		protected Block $blockAgainst,
		protected Item $item
	){
		$world = $this->blockAgainst->getPosition()->getWorld();
		foreach($this->transaction->getBlocks() as [$x, $y, $z, $block]){
			$block->position($world, $x, $y, $z);
		}
	}

	/**
	 * Returns the player who is placing the block.
	 */
	public function getPlayer() : Player{
		return $this->player;
	}

	/**
	 * Gets the item in hand
	 */
	public function getItem() : Item{
		return clone $this->item;
	}

	/**
	 * Returns a BlockTransaction object containing all the block positions that will be changed by this event, and the
	 * states they will be changed to.
	 *
	 * This will usually contain only one block, but may contain more if the block being placed is a multi-block
	 * structure such as a door or bed.
	 */
	public function getTransaction() : BlockTransaction{
		return $this->transaction;
	}

	public function getBlockAgainst() : Block{
		return $this->blockAgainst;
	}
}
