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

final class RedstoneChunk{

	/** @var array<int, BlockData> Redstone metadata stored by local block hash. */
	private array $extra_data = [];

	public function setBlockData(int $x, int $y, int $z, BlockData $data) : void{
		$this->extra_data[World::chunkBlockHash($x, $y, $z)] = $data;
	}

	public function getBlockData(int $x, int $y, int $z) : ?BlockData{
		return $this->extra_data[World::chunkBlockHash($x, $y, $z)] ?? null;
	}

	public function removeBlockData(int $x, int $y, int $z) : void{
		unset($this->extra_data[World::chunkBlockHash($x, $y, $z)]);
	}
}
