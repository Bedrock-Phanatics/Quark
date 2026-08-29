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

namespace quark\world\generator\populator;

use quark\block\BlockTypeIds;
use quark\block\BlockTypeTags;
use quark\utils\Random;
use quark\world\ChunkManager;
use quark\world\format\Chunk;
use quark\world\generator\object\TreeFactory;
use quark\world\generator\object\TreeType;

class Tree implements Populator{
	private int $randomAmount = 1;
	private int $baseAmount = 0;
	private TreeType $type;

	/**
	 * @param TreeType|null $type default oak
	 */
	public function __construct(?TreeType $type = null){
		$this->type = $type ?? TreeType::OAK;
	}

	public function setRandomAmount(int $amount) : void{
		$this->randomAmount = $amount;
	}

	public function setBaseAmount(int $amount) : void{
		$this->baseAmount = $amount;
	}

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ, Random $random) : void{
		$amount = $random->nextRange(0, $this->randomAmount) + $this->baseAmount;
		for($i = 0; $i < $amount; ++$i){
			$x = $random->nextRange($chunkX << Chunk::COORD_BIT_SIZE, ($chunkX << Chunk::COORD_BIT_SIZE) + Chunk::EDGE_LENGTH);
			$z = $random->nextRange($chunkZ << Chunk::COORD_BIT_SIZE, ($chunkZ << Chunk::COORD_BIT_SIZE) + Chunk::EDGE_LENGTH);
			$y = $this->getHighestWorkableBlock($world, $x, $z);
			if($y === -1){
				continue;
			}
			$tree = TreeFactory::get($random, $this->type);
			$transaction = $tree?->getBlockTransaction($world, $x, $y, $z, $random);
			$transaction?->apply();
		}
	}

	private function getHighestWorkableBlock(ChunkManager $world, int $x, int $z) : int{
		$highestBlock = $world->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)?->getHighestBlockAt($x & Chunk::COORD_MASK, $z & Chunk::COORD_MASK);
		if($highestBlock === null){
			return -1;
		}
		for($y = $highestBlock; $y >= 0; --$y){
			$b = $world->getBlockAt($x, $y, $z);
			if($b->hasTypeTag(BlockTypeTags::DIRT) || $b->hasTypeTag(BlockTypeTags::MUD)){
				return $y + 1;
			}elseif($b->getTypeId() !== BlockTypeIds::AIR && $b->getTypeId() !== BlockTypeIds::SNOW_LAYER){
				return -1;
			}
		}

		return -1;
	}
}
