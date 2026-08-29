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

use quark\utils\Random;
use quark\world\ChunkManager;
use quark\world\format\Chunk;
use quark\world\generator\object\Ore as ObjectOre;
use quark\world\generator\object\OreType;

class Ore implements Populator{
	/** @var OreType[] */
	private array $oreTypes = [];

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ, Random $random) : void{
		foreach($this->oreTypes as $type){
			$ore = new ObjectOre($random, $type);
			for($i = 0; $i < $ore->type->clusterCount; ++$i){
				$x = $random->nextRange($chunkX << Chunk::COORD_BIT_SIZE, ($chunkX << Chunk::COORD_BIT_SIZE) + Chunk::EDGE_LENGTH - 1);
				$y = $random->nextRange($ore->type->minHeight, $ore->type->maxHeight);
				$z = $random->nextRange($chunkZ << Chunk::COORD_BIT_SIZE, ($chunkZ << Chunk::COORD_BIT_SIZE) + Chunk::EDGE_LENGTH - 1);
				if($ore->canPlaceObject($world, $x, $y, $z)){
					$ore->placeObject($world, $x, $y, $z);
				}
			}
		}
	}

	/**
	 * @param OreType[] $types
	 */
	public function setOreTypes(array $types) : void{
		$this->oreTypes = $types;
	}
}
