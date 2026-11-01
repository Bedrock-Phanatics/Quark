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

use pocketmine\world\format\LightArray;
use pocketmine\world\format\LightEngine;
use quark\block\RuntimeBlockStateRegistry;
use quark\scheduler\AsyncTask;
use quark\world\format\Chunk;
use quark\world\format\io\FastChunkSerializer;
use quark\world\World;
use function array_fill;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;

class LightPopulationTask extends AsyncTask{
	private const TLS_KEY_COMPLETION_CALLBACK = "onCompletion";

	private static ?LightEngine $lightEngine = null;
	private static int $lightEngineStates = -1;

	public string $chunk;

	private string $resultHeightMap;
	private string $resultSkyLightArrays;
	private string $resultBlockLightArrays;

	/**
	 * @phpstan-param \Closure(array<int, LightArray> $blockLight, array<int, LightArray> $skyLight, non-empty-list<int> $heightMap) : void $onCompletion
	 */
	public function __construct(Chunk $chunk, \Closure $onCompletion){
		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->storeLocal(self::TLS_KEY_COMPLETION_CALLBACK, $onCompletion);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->chunk);

		$emptyBlockIds = [];
		$blocks = [];
		$skyLightArrays = [];
		$blockLightArrays = [];
		foreach($chunk->getSubChunks() as $y => $subChunk){
			$emptyBlockIds[$y] = $subChunk->getEmptyBlockId();
			$blocks[$y] = $subChunk->getBlockLayers()[0] ?? null;
			$skyLightArrays[$y] = $subChunk->getBlockSkyLightArray();
			$blockLightArrays[$y] = $subChunk->getBlockLightArray();
		}

		//same propagation as BlockLightUpdate and SkyLightUpdate, filling the light arrays in place
		$engine = self::getLightEngine();
		$engine->setChunk(0, 0, $emptyBlockIds, $blocks, $blockLightArrays, $skyLightArrays, array_fill(0, Chunk::EDGE_LENGTH ** 2, World::Y_MIN));
		try{
			$engine->recalculateBlockChunk(0, 0);
			$engine->executeBlock();
			$engine->recalculateSkyChunk(0, 0);
			$engine->executeSky();
			$heightMap = $engine->getHeightMap(0, 0);
		}finally{
			$engine->clear();
		}

		$this->resultHeightMap = igbinary_serialize($heightMap);
		$this->resultSkyLightArrays = igbinary_serialize($skyLightArrays);
		$this->resultBlockLightArrays = igbinary_serialize($blockLightArrays);
	}

	private static function getLightEngine() : LightEngine{
		$registry = RuntimeBlockStateRegistry::getInstance();
		//states can be registered after this worker's first task, but never change once registered
		$states = count($registry->lightFilter);
		if(self::$lightEngine === null || self::$lightEngineStates !== $states){
			self::$lightEngine = new LightEngine($registry->lightFilter, $registry->light, $registry->blocksDirectSkyLight);
			self::$lightEngineStates = $states;
		}
		return self::$lightEngine;
	}

	public function onCompletion() : void{
		/**
		 * @var int[] $heightMapArray
		 * @phpstan-var non-empty-list<int> $heightMapArray
		 */
		$heightMapArray = igbinary_unserialize($this->resultHeightMap);

		/** @var LightArray[] $skyLightArrays */
		$skyLightArrays = igbinary_unserialize($this->resultSkyLightArrays);
		/** @var LightArray[] $blockLightArrays */
		$blockLightArrays = igbinary_unserialize($this->resultBlockLightArrays);

		/**
		 * @var \Closure
		 * @phpstan-var \Closure(array<int, LightArray> $blockLight, array<int, LightArray> $skyLight, non-empty-list<int> $heightMap) : void
		 */
		$callback = $this->fetchLocal(self::TLS_KEY_COMPLETION_CALLBACK);
		$callback($blockLightArrays, $skyLightArrays, $heightMapArray);
	}
}
