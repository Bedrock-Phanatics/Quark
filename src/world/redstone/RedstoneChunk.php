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

namespace quark\world\redstone;

use quark\world\World;

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
