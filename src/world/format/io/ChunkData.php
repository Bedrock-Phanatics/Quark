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

namespace quark\world\format\io;

use pocketmine\nbt\tag\CompoundTag;
use quark\world\format\SubChunk;

final class ChunkData{

	/**
	 * @param SubChunk[]    $subChunks
	 * @param CompoundTag[] $entityNBT
	 * @param CompoundTag[] $tileNBT
	 *
	 * @phpstan-param array<int, SubChunk> $subChunks
	 * @phpstan-param list<CompoundTag> $entityNBT
	 * @phpstan-param list<CompoundTag> $tileNBT
	 */
	public function __construct(
		private array $subChunks,
		private bool $populated,
		private array $entityNBT,
		private array $tileNBT
	){}

	/**
	 * @return SubChunk[]
	 * @phpstan-return array<int, SubChunk>
	 */
	public function getSubChunks() : array{ return $this->subChunks; }

	public function isPopulated() : bool{ return $this->populated; }

	/**
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function getEntityNBT() : array{ return $this->entityNBT; }

	/**
	 * @return CompoundTag[]
	 * @phpstan-return list<CompoundTag>
	 */
	public function getTileNBT() : array{ return $this->tileNBT; }
}
