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

namespace quark\block;

use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;

/**
 * TODO: Implement forming above soul sand and magma, pushing entities up or down, and reverting to water.
 */
class BubbleColumn extends Transparent{
	protected bool $dragDown = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->dragDown);
	}

	public function isDragDown() : bool{ return $this->dragDown; }

	/** @return $this */
	public function setDragDown(bool $dragDown) : self{
		$this->dragDown = $dragDown;
		return $this;
	}

	public function isSolid() : bool{
		return false;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	protected function recalculateCollisionBoxes() : array{
		return [];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function getDrops(Item $item) : array{
		return [];
	}
}
