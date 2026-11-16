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

use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;

/**
 * TODO: Implement blooming and spreading sculk when mobs die nearby.
 */
class SculkCatalyst extends Opaque{
	protected bool $bloom = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->bloom);
	}

	public function isBloom() : bool{ return $this->bloom; }

	/** @return $this */
	public function setBloom(bool $bloom) : self{
		$this->bloom = $bloom;
		return $this;
	}

	public function getLightLevel() : int{
		return 6;
	}

	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}

	protected function getXpDropAmount() : int{
		return 5;
	}
}
