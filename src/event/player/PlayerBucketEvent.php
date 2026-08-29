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
 * @allowHandle
 */
abstract class PlayerBucketEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $who,
		private Block $blockClicked,
		private int $blockFace,
		private Item $bucket,
		private Item $itemInHand
	){
		$this->player = $who;
	}

	/**
	 * Returns the bucket used in this event
	 */
	public function getBucket() : Item{
		return $this->bucket;
	}

	/**
	 * Returns the item in hand after the event
	 */
	public function getItem() : Item{
		return $this->itemInHand;
	}

	public function setItem(Item $item) : void{
		$this->itemInHand = $item;
	}

	public function getBlockClicked() : Block{
		return $this->blockClicked;
	}

	public function getBlockFace() : int{
		return $this->blockFace;
	}
}
