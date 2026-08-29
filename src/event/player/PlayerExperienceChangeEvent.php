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

use quark\entity\Human;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\event\entity\EntityEvent;

/**
 * Called when a player gains or loses XP levels and/or progress.
 * @phpstan-extends EntityEvent<Human>
 */
class PlayerExperienceChangeEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Human $player,
		private int $oldLevel,
		private float $oldProgress,
		private ?int $newLevel,
		private ?float $newProgress
	){
		$this->entity = $player;
	}

	public function getOldLevel() : int{
		return $this->oldLevel;
	}

	public function getOldProgress() : float{
		return $this->oldProgress;
	}

	/**
	 * @return int|null null indicates no change
	 */
	public function getNewLevel() : ?int{
		return $this->newLevel;
	}

	/**
	 * @return float|null null indicates no change
	 */
	public function getNewProgress() : ?float{
		return $this->newProgress;
	}

	public function setNewLevel(?int $newLevel) : void{
		$this->newLevel = $newLevel;
	}

	public function setNewProgress(?float $newProgress) : void{
		if($newProgress < 0.0 || $newProgress > 1.0){
			throw new \InvalidArgumentException("XP progress must be in range 0-1");
		}
		$this->newProgress = $newProgress;
	}
}
