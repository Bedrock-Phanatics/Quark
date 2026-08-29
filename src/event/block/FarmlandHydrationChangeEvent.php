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
use quark\block\Farmland;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when farmland hydration is updated.
 */
class FarmlandHydrationChangeEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Block $block,
		private int $oldHydration,
		private int $newHydration,
	){
		parent::__construct($block);
	}

	public function getOldHydration() : int{
		return $this->oldHydration;
	}

	public function getNewHydration() : int{
		return $this->newHydration;
	}

	public function setNewHydration(int $hydration) : void{
		if($hydration < 0 || $hydration > Farmland::MAX_WETNESS){
			throw new \InvalidArgumentException("Hydration must be in range 0 ... " . Farmland::MAX_WETNESS);
		}
		$this->newHydration = $hydration;
	}
}
