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

use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use quark\block\VanillaBlocks;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\network\mcpe\compression\NativeCompressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\LevelChunkPacket;
use quark\network\mcpe\protocol\ProtocolClasses;
use quark\network\mcpe\protocol\types\CompressionAlgorithm;
use quark\network\mcpe\serializer\ChunkSerializerTest;
use quark\Server;
use quark\world\format\Chunk;
use quark\world\World;
use quark\world\WorldPulse;
use function chr;
use function ord;
use function substr;

final class ChunkCacheTest extends TestCase{
	private const X = 3;
	private const Z = -7;

	private Chunk $chunk;
	private bool $loaded = true;
	private bool $asyncCompression = false;
	/** @var list<?bool> */
	private array $syncModes = [];
	/** @var list<CompressBatchPromise> */
	private array $compressing = [];
	/** @phpstan-var (\Closure() : void)|null */
	private ?\Closure $unloadWorld = null;
	private ChunkCache $cache;

	protected function setUp() : void{
		$this->chunk = new Chunk([], false);
		$this->chunk->setBlockStateId(0, 0, 0, VanillaBlocks::STONE()->getStateId());

		$server = $this->createMock(Server::class);
		$server->method("prepareBatch")->willReturnCallback(function(string $buffer, mixed $compressor, ?bool $sync = null) : CompressBatchPromise|string{
			$this->syncModes[] = $sync;
			if($this->asyncCompression && $sync !== true){
				return $this->compressing[] = new CompressBatchPromise();
			}
			return chr(CompressionAlgorithm::NONE->value) . $buffer;
		});
		$world = $this->createMock(World::class);
		$world->method("getFolderName")->willReturn("chunk-cache-test");
		$world->method("getServer")->willReturn($server);
		$world->method("getChunk")->willReturnCallback(fn() => $this->loaded ? $this->chunk : null);
		$world->method("addOnUnloadCallback")->willReturnCallback(function(\Closure $callback) : void{
			$this->unloadWorld = $callback;
		});
		$world->pulse = new WorldPulse($world);
		$this->cache = ChunkCache::getInstance($world, new NativeCompressor(CompressionAlgorithm::ZLIB));
	}

	protected function tearDown() : void{
		if($this->unloadWorld !== null){
			($this->unloadWorld)();
		}
	}

	/**
	 * @return int[] network IDs in the palette of subchunk 0
	 */
	private static function palette(string $batch) : array{
		self::assertSame(CompressionAlgorithm::NONE->value, ord($batch[0]));
		$reader = new ByteBufferReader(substr($batch, 1));
		$packet = $reader->readByteArray(VarInt::readUnsignedInt($reader));
		self::assertSame(0, $reader->getUnreadLength(), "one packet per batch");

		ProtocolClasses::bind();
		$decoded = Protocol::decode($packet);
		self::assertInstanceOf(LevelChunkPacket::class, $decoded);
		self::assertSame([self::X, self::Z], [$decoded->chunkPosition->x, $decoded->chunkPosition->z]);
		self::assertSame(5, $decoded->subChunksCount);
		$payload = $decoded->serializedChunkData;
		[$subChunks] = ChunkSerializerTest::decode($payload, 5, 24);
		return $subChunks[4][0][1];
	}

	private static function networkId(int $stateId) : int{
		return TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($stateId);
	}

	private function setBlock(int $stateId) : void{
		$this->chunk->setBlockStateId(0, 0, 0, $stateId);
		$this->cache->onBlockChanged(new Vector3(self::X << Chunk::COORD_BIT_SIZE, 0, self::Z << Chunk::COORD_BIT_SIZE));
	}

	public function testNativeBatchIsReadyImmediatelyAndShared() : void{
		$batch = $this->cache->request(self::X, self::Z);
		self::assertIsString($batch);
		self::assertContains(self::networkId(VanillaBlocks::STONE()->getStateId()), self::palette($batch));
		self::assertSame($batch, $this->cache->request(self::X, self::Z));
		self::assertCount(1, $this->syncModes);
		self::assertSame(0.5, $this->cache->getHitPercentage());
	}

	public function testBlockChangeInvalidatesBatch() : void{
		$this->cache->request(self::X, self::Z);
		$this->setBlock(VanillaBlocks::DIAMOND()->getStateId());

		$batch = $this->cache->request(self::X, self::Z);
		self::assertIsString($batch);
		self::assertContains(self::networkId(VanillaBlocks::DIAMOND()->getStateId()), self::palette($batch));
	}

	public function testChangesDuringCompressionAreNotSentStale() : void{
		$this->asyncCompression = true;
		$promise = $this->cache->request(self::X, self::Z);
		self::assertInstanceOf(CompressBatchPromise::class, $promise);
		self::assertSame($promise, $this->cache->request(self::X, self::Z), "concurrent requests share one batch");

		$received = [];
		$promise->onResolve(function(CompressBatchPromise $promise) use (&$received) : void{
			$received[] = $promise->getResult();
		});
		for($i = 0; $i < 100; ++$i){
			$this->setBlock(VanillaBlocks::DIAMOND()->getStateId());
		}
		self::assertCount(1, $this->compressing, "changes don't start more work while compression is running");

		$this->compressing[0]->resolve(chr(CompressionAlgorithm::ZLIB->value) . "stale");
		self::assertSame([null, true], $this->syncModes);
		self::assertCount(1, $received);
		self::assertContains(self::networkId(VanillaBlocks::DIAMOND()->getStateId()), self::palette($received[0]));
		self::assertSame($received[0], $this->cache->request(self::X, self::Z));
	}

	public function testUntouchedAsyncBatchIsCached() : void{
		$this->asyncCompression = true;
		$promise = $this->cache->request(self::X, self::Z);
		self::assertInstanceOf(CompressBatchPromise::class, $promise);
		$this->compressing[0]->resolve("compressed");
		self::assertSame("compressed", $promise->getResult());
		self::assertSame("compressed", $this->cache->request(self::X, self::Z));
		self::assertSame([null], $this->syncModes);
	}

	public function testUnloadCancelsPendingRequests() : void{
		$this->asyncCompression = true;
		$promise = $this->cache->request(self::X, self::Z);
		self::assertInstanceOf(CompressBatchPromise::class, $promise);
		$called = false;
		$promise->onResolve(function() use (&$called) : void{
			$called = true;
		});

		$this->cache->onChunkUnloaded(self::X, self::Z, $this->chunk);
		self::assertTrue($promise->isCancelled());

		$this->asyncCompression = false;
		$fresh = $this->cache->request(self::X, self::Z);
		self::assertIsString($fresh);
		$this->compressing[0]->resolve("old");
		self::assertFalse($called);
		self::assertSame($fresh, $this->cache->request(self::X, self::Z), "an old batch can't replace a newer one");
	}

	public function testWorldUnloadCancelsPendingRequests() : void{
		$this->asyncCompression = true;
		$promise = $this->cache->request(self::X, self::Z);
		self::assertInstanceOf(CompressBatchPromise::class, $promise);
		self::assertNotNull($this->unloadWorld);
		($this->unloadWorld)();
		$this->unloadWorld = null;
		self::assertTrue($promise->isCancelled());
		$this->compressing[0]->resolve("old");
		self::assertSame(0, $this->cache->calculateCacheSize());
	}

	public function testPruneKeepsPendingRequests() : void{
		$this->cache->request(self::X, self::Z);
		$this->asyncCompression = true;
		$pending = $this->cache->request(self::X + 1, self::Z);
		ChunkCache::pruneCaches();
		self::assertSame(0, $this->cache->calculateCacheSize());
		self::assertSame($pending, $this->cache->request(self::X + 1, self::Z));
		self::assertCount(2, $this->syncModes);
	}

	public function testRequestingAnUnloadedChunkFails() : void{
		$this->loaded = false;
		$this->expectException(\InvalidArgumentException::class);
		$this->cache->request(self::X, self::Z);
	}
}
