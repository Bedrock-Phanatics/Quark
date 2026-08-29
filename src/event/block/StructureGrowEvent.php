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
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Called when structures such as Saplings or Bamboo grow.
 * These types of plants tend to change multiple blocks at once upon growing.
 */
class StructureGrowEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Block $block,
		private BlockTransaction $transaction,
		private ?Player $player
	){
		parent::__construct($block);
	}

	public function getTransaction() : BlockTransaction{
		return $this->transaction;
	}

	/**
	 * It returns the player which grows the structure.
	 * It returns null when the structure grows by itself.
	 */
	public function getPlayer() : ?Player{
		return $this->player;
	}
}
