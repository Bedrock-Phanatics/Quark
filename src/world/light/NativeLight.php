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

namespace quark\world\light;

use pocketmine\world\format\LightEngine;
use quark\block\RuntimeBlockStateRegistry;
use quark\world\format\Chunk;
use function count;

final class NativeLight{
	private static ?LightEngine $engine = null;
	private static int $engineStates = -1;

	private function __construct(){
		//NOOP
	}

	/**
	 * Returns this thread's engine. It holds no chunks between uses, so callers clear it when done.
	 */
	public static function getEngine() : LightEngine{
		$registry = RuntimeBlockStateRegistry::getInstance();
		//states can be registered after the engine was made, but never change once registered
		$states = count($registry->lightFilter);
		if(self::$engine === null || self::$engineStates !== $states){
			self::$engine = new LightEngine($registry->lightFilter, $registry->light, $registry->blocksDirectSkyLight);
			self::$engineStates = $states;
		}
		return self::$engine;
	}

	/**
	 * Lends the chunk's first block layers and light arrays to the engine, which edits the light arrays in place.
	 */
	public static function setChunk(LightEngine $engine, int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$emptyBlockIds = [];
		$blocks = [];
		$blockLight = [];
		$skyLight = [];
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$emptyBlockIds[$y] = $subChunk->getEmptyBlockId();
			$blocks[$y] = $subChunk->getBlockLayers()[0] ?? null;
			$blockLight[$y] = $subChunk->getBlockLightArray();
			$skyLight[$y] = $subChunk->getBlockSkyLightArray();
		}
		$engine->setChunk($chunkX, $chunkZ, $emptyBlockIds, $blocks, $blockLight, $skyLight, $chunk->getHeightMapArray());
	}
}
