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

namespace quark\network\mcpe\cache;

use Bedwire\PacketId;
use Bedwire\Protocol;
use pocketmine\math\Vector3;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\types\DimensionIds;
use quark\network\mcpe\serializer\ChunkSerializer;
use quark\utils\AssumptionFailedError;
use quark\world\ChunkListener;
use quark\world\ChunkListenerNoOpTrait;
use quark\world\format\Chunk;
use quark\world\World;
use function array_keys;
use function is_string;
use function spl_object_id;
use function strlen;

/**
 * This class is used by the current MCPE protocol system to store cached chunk packets for fast resending.
 */
class ChunkCache implements ChunkListener{
	/** @var self[][] */
	private static array $instances = [];

	/**
	 * Fetches the ChunkCache instance for the given world. This lazily creates cache systems as needed.
	 */
	public static function getInstance(World $world, Compressor $compressor) : self{
		$worldId = spl_object_id($world);
		$compressorId = spl_object_id($compressor);
		if(!isset(self::$instances[$worldId])){
			self::$instances[$worldId] = [];
			$world->addOnUnloadCallback(static function() use ($worldId) : void{
				foreach(self::$instances[$worldId] as $cache){
					foreach(array_keys($cache->caches) as $chunkHash){
						$cache->drop($chunkHash);
					}
				}
				unset(self::$instances[$worldId]);
				\GlobalLogger::get()->debug("Destroyed chunk packet caches for world#$worldId");
			});
		}
		if(!isset(self::$instances[$worldId][$compressorId])){
			\GlobalLogger::get()->debug("Created new chunk packet cache (world#$worldId, compressor#$compressorId)");
			self::$instances[$worldId][$compressorId] = new self($world, $compressor);
		}
		return self::$instances[$worldId][$compressorId];
	}

	public static function pruneCaches() : void{
		foreach(self::$instances as $compressorMap){
			foreach($compressorMap as $chunkCache){
				foreach($chunkCache->caches as $chunkHash => $promise){
					if(is_string($promise)){
						//Do not clear promises that are not yet fulfilled; they will have requesters waiting on them
						unset($chunkCache->caches[$chunkHash]);
					}
				}
			}
		}
	}

	/**
	 * @var CompressBatchPromise[]|string[]
	 * @phpstan-var array<int, CompressBatchPromise|string>
	 */
	private array $caches = [];
	/**
	 * Chunks changed while their batch was still being compressed
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $stale = [];

	private int $hits = 0;
	private int $misses = 0;

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	private function __construct(
		private World $world,
		private Compressor $compressor,
		private int $dimensionId = DimensionIds::OVERWORLD
	){}

	/**
	 * Encoding here costs the main thread less than snapshotting the chunk for a worker, and the batch can't go stale
	 * because later changes are broadcast after it.
	 */
	private function prepare(int $chunkX, int $chunkZ, ?bool $sync) : CompressBatchPromise|string{
		$chunk = $this->world->getChunk($chunkX, $chunkZ) ?? throw new \InvalidArgumentException("Cannot request an unloaded chunk");

		$syncChunkSendPrepareScope = $this->world->pulse->syncChunkSendPrepare->start();
		try{
			$subChunkCount = ChunkSerializer::getSubChunkCount($chunk, $this->dimensionId);
			$batch = Protocol::encodeBatch([[PacketId::LEVEL_CHUNK, [
				"chunk_position" => ["x" => $chunkX, "z" => $chunkZ],
				"dimension_id" => $this->dimensionId,
				"sub_chunks_count" => $subChunkCount,
				"client_request_sub_chunk_limit" => null,
				"cache_enabled" => false,
				"cache_metadata" => [],
				"serialized_chunk_data" => ChunkSerializer::serializeFullChunk($chunk, $this->dimensionId, TypeConverter::getInstance()->getBlockTranslator(), null, $subChunkCount),
			]]]);
		}finally{
			$this->world->pulse->syncChunkSendPrepare->stop($syncChunkSendPrepareScope);
		}
		return $this->world->getServer()->prepareBatch($batch, $this->compressor, $sync);
	}

	/**
	 * Requests preparation of the chunk at the given coordinates.
	 *
	 * @return CompressBatchPromise|string Compressed chunk packet, or a promise for one to be resolved asynchronously.
	 */
	public function request(int $chunkX, int $chunkZ) : CompressBatchPromise|string{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(isset($this->caches[$chunkHash])){
			++$this->hits;
			return $this->caches[$chunkHash];
		}
		++$this->misses;

		$this->world->registerChunkListener($this, $chunkX, $chunkZ);
		$batch = $this->prepare($chunkX, $chunkZ, null);
		if(is_string($batch)){
			return $this->caches[$chunkHash] = $batch;
		}

		$promise = new CompressBatchPromise();
		$this->caches[$chunkHash] = $promise;
		$batch->onResolve(function(CompressBatchPromise $batch) use ($chunkX, $chunkZ, $chunkHash, $promise) : void{
			if($promise->isCancelled()){
				return;
			}
			$result = $batch->getResult();
			if(isset($this->stale[$chunkHash])){
				//an async retry could be outrun by changes forever, so the fresh batch is compressed right away
				unset($this->stale[$chunkHash]);
				$result = $this->prepare($chunkX, $chunkZ, true);
				if(!is_string($result)){
					throw new AssumptionFailedError("Synchronous batch preparation should never return a promise");
				}
			}
			$this->caches[$chunkHash] = $result;
			$promise->resolve($result);
		});
		return $promise;
	}

	private function invalidate(int $chunkX, int $chunkZ) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$cache = $this->caches[$chunkHash] ?? null;
		if(is_string($cache)){
			unset($this->caches[$chunkHash]);
		}elseif($cache !== null){
			$this->stale[$chunkHash] = true;
		}
	}

	private function drop(int $chunkHash) : void{
		$cache = $this->caches[$chunkHash] ?? null;
		if($cache instanceof CompressBatchPromise){
			//nobody can use the old chunk anymore, and a reloaded one gets a new request
			$cache->cancel();
		}
		unset($this->caches[$chunkHash], $this->stale[$chunkHash]);
	}

	use ChunkListenerNoOpTrait {
		//force overriding of these
		onChunkChanged as private;
		onBlockChanged as private;
		onChunkUnloaded as private;
	}

	/**
	 * @see ChunkListener::onChunkChanged()
	 */
	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->invalidate($chunkX, $chunkZ);
	}

	/**
	 * @see ChunkListener::onBlockChanged()
	 */
	public function onBlockChanged(Vector3 $block) : void{
		$this->invalidate($block->getFloorX() >> Chunk::COORD_BIT_SIZE, $block->getFloorZ() >> Chunk::COORD_BIT_SIZE);
	}

	/**
	 * @see ChunkListener::onChunkUnloaded()
	 */
	public function onChunkUnloaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->drop(World::chunkHash($chunkX, $chunkZ));
		$this->world->unregisterChunkListener($this, $chunkX, $chunkZ);
	}

	/**
	 * Returns the number of bytes occupied by the cache data in this cache. This does not include the size of any
	 * promises referenced by the cache.
	 */
	public function calculateCacheSize() : int{
		$result = 0;
		foreach($this->caches as $cache){
			if(is_string($cache)){
				$result += strlen($cache);
			}
		}
		return $result;
	}

	/**
	 * Returns the percentage of requests to the cache which resulted in a cache hit.
	 */
	public function getHitPercentage() : float{
		$total = $this->hits + $this->misses;
		return $total > 0 ? $this->hits / $total : 0.0;
	}
}
