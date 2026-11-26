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

namespace quark\world\generator;

use quark\scheduler\AsyncTask;
use quark\utils\AssumptionFailedError;
use quark\world\format\Chunk;
use quark\world\format\io\FastChunkSerializer;
use quark\world\generator\executor\ThreadLocalGeneratorContext;
use function array_map;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * @internal
 *
 * Some plugins instantiate this task to regenerate chunks, so its namespace is retained.
 *
 * @phpstan-type OnCompletion \Closure(Chunk $result, array<int, Chunk> $adjacentChunks) : void
 */
class PopulationTask extends AsyncTask{
	private ?string $populationError = null;
	private const TLS_KEY_ON_COMPLETION = "onCompletion";
	private const TLS_KEY_ON_FAILURE = "onFailure";

	private ?string $chunk;

	private string $adjacentChunks;

	/**
	 * @param Chunk[]|null[] $adjacentChunks
	 * @phpstan-param array<int, Chunk|null> $adjacentChunks
	 * @phpstan-param OnCompletion $onCompletion
	 * @param (\Closure(\Throwable) : void)|null $onFailure
	 */
	public function __construct(
		private int $worldId,
		private int $chunkX,
		private int $chunkZ,
		?Chunk $chunk,
		array $adjacentChunks,
		\Closure $onCompletion,
		?\Closure $onFailure = null
	){
		$this->chunk = $chunk !== null ? FastChunkSerializer::serializeTerrain($chunk) : null;

		$this->adjacentChunks = igbinary_serialize(array_map(
			fn(?Chunk $c) => $c !== null ? FastChunkSerializer::serializeTerrain($c) : null,
			$adjacentChunks
		)) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");

		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
		$this->storeLocal(self::TLS_KEY_ON_FAILURE, $onFailure);
	}

	public function onRun() : void{
		$context = ThreadLocalGeneratorContext::fetch($this->worldId);
		if($context === null){
			throw new AssumptionFailedError("Generator context should have been initialized before any PopulationTask execution");
		}

		$chunk = $this->chunk !== null ? FastChunkSerializer::deserializeTerrain($this->chunk) : null;

		/**
		 * @var string[] $serialChunks
		 * @phpstan-var array<int, string|null> $serialChunks
		 */
		$serialChunks = igbinary_unserialize($this->adjacentChunks);
		$chunks = array_map(
			function(?string $serialized) : ?Chunk{
				if($serialized === null){
					return null;
				}
				$chunk = FastChunkSerializer::deserializeTerrain($serialized);
				$chunk->clearTerrainDirtyFlags(); //this allows us to avoid sending existing chunks back to the main thread if they haven't changed during generation
				return $chunk;
			},
			$serialChunks
		);

		try{
			[$chunk, $chunks] = PopulationUtils::populateChunkWithAdjacents(
				$context->getWorldMinY(),
				$context->getWorldMaxY(),
				$context->getGenerator(),
				$this->chunkX,
				$this->chunkZ,
				$chunk,
				$chunks
			);
		}catch(\Throwable $error){
			$this->populationError = $error::class . ": " . $error->getMessage();
			return;
		}

		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);

		$serialChunks = [];
		foreach($chunks as $relativeChunkHash => $c){
			$serialChunks[$relativeChunkHash] = $c->isTerrainDirty() ? FastChunkSerializer::serializeTerrain($c) : null;
		}
		$this->adjacentChunks = igbinary_serialize($serialChunks) ?? throw new AssumptionFailedError("igbinary_serialize() returned null");
	}

	public function onCompletion() : void{
		/**
		 * @var \Closure $onCompletion
		 * @phpstan-var OnCompletion $onCompletion
		 */
		$onCompletion = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		if($this->populationError !== null){
			/** @var (\Closure(\Throwable) : void)|null $onFailure */
			$onFailure = $this->fetchLocal(self::TLS_KEY_ON_FAILURE);
			$error = new \RuntimeException($this->populationError);
			if($onFailure === null){
				throw $error;
			}
			$onFailure($error);
			return;
		}

		$chunk = $this->chunk !== null ?
			FastChunkSerializer::deserializeTerrain($this->chunk) :
			throw new AssumptionFailedError("Center chunk should never be null");

		/**
		 * @var string[]|null[] $serialAdjacentChunks
		 * @phpstan-var array<int, string|null> $serialAdjacentChunks
		 */
		$serialAdjacentChunks = igbinary_unserialize($this->adjacentChunks);
		$adjacentChunks = [];
		foreach($serialAdjacentChunks as $relativeChunkHash => $c){
			if($c !== null){
				$adjacentChunks[$relativeChunkHash] = FastChunkSerializer::deserializeTerrain($c);
			}
		}

		$onCompletion($chunk, $adjacentChunks);
	}
}
