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

namespace quark\block\utils\redstone;

use quark\world\redstone\BlockData;

final class RedstoneTorchBlockData implements BlockData{
	private const int HISTORY_SIZE = 9;

	/** @var array<int, int> Recent activation ticks used to detect torch burnout. */
	private array $ticks = [];
	private int $head = 0;
	private int $size = 0;

	public function count(int $tick) : void{
		if($this->size < self::HISTORY_SIZE){
			$this->ticks[($this->head + $this->size) % self::HISTORY_SIZE] = $tick;
			++$this->size;
		}else{
			$this->ticks[$this->head] = $tick;
			$this->head = ($this->head + 1) % self::HISTORY_SIZE;
		}
	}

	public function isBurntOut(int $tick) : bool{
		return $this->size >= 8 && $tick - $this->ticks[$this->head] <= 60;
	}
}
