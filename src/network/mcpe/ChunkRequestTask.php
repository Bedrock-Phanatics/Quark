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

namespace quark\network\mcpe;

use Bedwire\PacketId;
use Bedwire\Protocol;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\serializer\ChunkSerializer;
use quark\scheduler\AsyncTask;
use quark\thread\NonThreadSafeValue;
use quark\world\format\Chunk;
use quark\world\format\io\FastChunkSerializer;
use function chr;

class ChunkRequestTask extends AsyncTask{
	private const TLS_KEY_PROMISE = "promise";

	protected string $chunk;
	protected int $chunkX;
	protected int $chunkZ;
	/** @phpstan-var DimensionIds::* */
	private int $dimensionId;
	/** @phpstan-var NonThreadSafeValue<Compressor> */
	protected NonThreadSafeValue $compressor;
	private string $tiles;

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public function __construct(int $chunkX, int $chunkZ, int $dimensionId, Chunk $chunk, CompressBatchPromise $promise, Compressor $compressor){
		$this->compressor = new NonThreadSafeValue($compressor);

		$this->chunk = FastChunkSerializer::serializeTerrain($chunk);
		$this->chunkX = $chunkX;
		$this->chunkZ = $chunkZ;
		$this->dimensionId = $dimensionId;
		$this->tiles = ChunkSerializer::serializeTiles($chunk);

		$this->storeLocal(self::TLS_KEY_PROMISE, $promise);
	}

	public function onRun() : void{
		$chunk = FastChunkSerializer::deserializeTerrain($this->chunk);
		$dimensionId = $this->dimensionId;

		$subCount = ChunkSerializer::getSubChunkCount($chunk, $dimensionId);
		$converter = TypeConverter::getInstance();
		$payload = ChunkSerializer::serializeFullChunk($chunk, $dimensionId, $converter->getBlockTranslator(), $this->tiles);

		$batch = Protocol::encodeBatch([[PacketId::LEVEL_CHUNK, [
			"chunk_position" => ["x" => $this->chunkX, "z" => $this->chunkZ],
			"dimension_id" => $dimensionId,
			"sub_chunks_count" => $subCount,
			"client_request_sub_chunk_limit" => null,
			"cache_enabled" => false,
			"cache_metadata" => [],
			"serialized_chunk_data" => $payload,
		]]]);

		$compressor = $this->compressor->deserialize();
		//a compressor without a threshold leaves compression to the transport, as prepareBatch() does
		if($compressor->getCompressionThreshold() === null){
			$this->setResult(chr(CompressionAlgorithm::NONE->value) . $batch);
		}else{
			$this->setResult(chr($compressor->getNetworkType()->value) . $compressor->compress($batch));
		}
	}

	public function onCompletion() : void{
		/** @var CompressBatchPromise $promise */
		$promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($this->getResult());
	}
}
