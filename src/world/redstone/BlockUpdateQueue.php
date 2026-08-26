<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\redstone;

use pocketmine\world\World;
use function count;

final class BlockUpdateQueue{

	/** @var array<int, int> Scheduled tick for each queued block position. */
	private array $scheduledTick = [];

	/** @var array<int, array<int, true>> Block positions grouped by the tick they should run on. */
	private array $scheduledBlocks = [];

	public function push(int $x, int $y, int $z, int $tick, bool $override, int $maximumSize) : bool{
		$hash = World::blockHash($x, $y, $z);
		if(isset($this->scheduledTick[$hash])){
			if(!$override){
				return true;
			}
			$oldTick = $this->scheduledTick[$hash];
			unset($this->scheduledBlocks[$oldTick][$hash]);
			if(($this->scheduledBlocks[$oldTick] ?? []) === []){
				unset($this->scheduledBlocks[$oldTick]);
			}
		}elseif(count($this->scheduledTick) >= $maximumSize){
			return false;
		}
		$this->scheduledTick[$hash] = $tick;
		$this->scheduledBlocks[$tick][$hash] = true;
		return true;
	}

	public function getSize() : int{ return count($this->scheduledTick); }

	public function getScheduledCountAt(int $tick) : int{ return count($this->scheduledBlocks[$tick] ?? []); }

	/** @return list<array{int, int, int}> */
	public function pop(int $tick, int $limit) : array{
		$hashes = $this->scheduledBlocks[$tick] ?? [];
		unset($this->scheduledBlocks[$tick]);
		$result = [];
		foreach($hashes as $hash => $_){
			if(count($result) >= $limit){
				$this->scheduledTick[$hash] = $tick + 1;
				$this->scheduledBlocks[$tick + 1][$hash] = true;
				continue;
			}
			if(($this->scheduledTick[$hash] ?? null) !== $tick){
				continue;
			}
			unset($this->scheduledTick[$hash]);
			World::getBlockXYZ($hash, $x, $y, $z);
			$result[] = [$x, $y, $z];
		}
		return $result;
	}
}
