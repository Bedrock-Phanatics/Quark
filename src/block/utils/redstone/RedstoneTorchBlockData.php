<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\world\redstone\BlockData;

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
