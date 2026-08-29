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
use quark\block\Liquid;
use quark\block\RuntimeBlockStateRegistry;
use quark\utils\Random;
use quark\world\biome\BiomeRegistry;
use quark\world\ChunkManager;
use quark\world\format\Chunk;
use function count;
use function min;

class GroundCover implements Populator{

	public function populate(ChunkManager $world, int $chunkX, int $chunkZ, Random $random) : void{
		$chunk = $world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Chunk $chunkX $chunkZ does not yet exist");
		$factory = RuntimeBlockStateRegistry::getInstance();
		$biomeRegistry = BiomeRegistry::getInstance();
		for($x = 0; $x < Chunk::EDGE_LENGTH; ++$x){
			for($z = 0; $z < Chunk::EDGE_LENGTH; ++$z){
				$biome = $biomeRegistry->getBiome($chunk->getBiomeId($x, 0, $z));
				$cover = $biome->getGroundCover();
				if(count($cover) > 0){
					$diffY = 0;
					if(!$cover[0]->isSolid()){
						$diffY = 1;
					}

					$startY = $chunk->getHighestBlockAt($x, $z);
					if($startY === null){
						//ground cover is supposed to replace preexisting blocks, nothing to do if there are no blocks
						continue;
					}
					for(; $startY > 0; --$startY){
						if(!$factory->fromStateId($chunk->getBlockStateId($x, $startY, $z))->isTransparent()){
							break;
						}
					}
					$startY = min(127, $startY + $diffY);
					$endY = $startY - count($cover);
					for($y = $startY; $y > $endY && $y >= 0; --$y){
						$b = $cover[$startY - $y];
						$id = $factory->fromStateId($chunk->getBlockStateId($x, $y, $z));
						if($id->getTypeId() === BlockTypeIds::AIR && $b->isSolid()){
							break;
						}
						if($b->canBeFlowedInto() && $id instanceof Liquid){
							continue;
						}

						$chunk->setBlockStateId($x, $y, $z, $b->getStateId());
					}
				}
			}
		}
	}
}
