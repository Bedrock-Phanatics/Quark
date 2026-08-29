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

namespace quark\event\inventory;

use quark\block\tile\Furnace;
use quark\event\block\BlockEvent;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\item\Item;

class FurnaceSmeltEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private Furnace $furnace,
		private Item $source,
		private Item $result
	){
		parent::__construct($furnace->getBlock());
		$this->source = clone $source;
		$this->source->setCount(1);
	}

	public function getFurnace() : Furnace{
		return $this->furnace;
	}

	public function getSource() : Item{
		return $this->source;
	}

	public function getResult() : Item{
		return $this->result;
	}

	public function setResult(Item $result) : void{
		$this->result = $result;
	}
}
