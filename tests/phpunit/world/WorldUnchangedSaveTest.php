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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\WritableWorldProvider;

final class WorldUnchangedSaveTest extends TestCase{

	public function testOnlyChangedChunksAreSaved() : void{
		/** @var \ArrayObject<int, int> $saves */
		$saves = new \ArrayObject();
		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("saveChunk")->willReturnCallback(function(int $x, int $z, ChunkData $data, int $flags) use ($saves) : void{
			$saves[] = $flags;
		});
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(World::class, "provider"))->setValue($world, $provider);
		$state = new \ReflectionMethod(World::class, "chunkSaveState");
		$states = new \ReflectionProperty(World::class, "savedChunkStates");
		$save = new \ReflectionMethod(World::class, "saveChunk");
		$hash = World::chunkHash(0, 0);

		//as loaded: storage holds a populated chunk with no entities or tiles
		$chunk = new Chunk([], true);
		$chunk->clearTerrainDirtyFlags();
		$states->setValue($world, [$hash => $state->invoke(null, true, [], [])]);
		$save->invoke($world, 0, 0, $hash, $chunk);
		self::assertCount(0, $saves, "an unchanged chunk is not written");

		$chunk->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
		$save->invoke($world, 0, 0, $hash, $chunk);
		self::assertSame([Chunk::DIRTY_FLAG_BLOCKS], $saves->getArrayCopy(), "changed terrain is written");
		$chunk->clearTerrainDirtyFlags();
		$save->invoke($world, 0, 0, $hash, $chunk);
		self::assertCount(1, $saves, "the written state is remembered");

		$chunk->setPopulated(false);
		$chunk->clearTerrainDirtyFlags();
		$save->invoke($world, 0, 0, $hash, $chunk);
		self::assertCount(2, $saves, "a populated state change is written without dirty terrain");

		//a chunk storage knows nothing about, such as a generated or replaced one, is always written
		$states->setValue($world, []);
		$save->invoke($world, 0, 0, $hash, $chunk);
		self::assertCount(3, $saves);

		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("saveChunk")->willThrowException(new \RuntimeException("disk full"));
		(new \ReflectionProperty(World::class, "provider"))->setValue($world, $provider);
		$states->setValue($world, []);
		try{
			$save->invoke($world, 0, 0, $hash, $chunk);
			self::fail("the provider's failure should propagate");
		}catch(\RuntimeException){
		}
		self::assertSame([], $states->getValue($world), "a failed write is not remembered, so the next save retries it");
	}

	public function testAFailedChunkDoesNotStopTheOthersFromSaving() : void{
		/** @var \ArrayObject<int, int> $saved */
		$saved = new \ArrayObject();
		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("saveChunk")->willReturnCallback(function(int $x, int $z, ChunkData $data, int $flags) use ($saved) : void{
			if($x === 0){
				throw new \RuntimeException("disk full");
			}
			$saved[] = $x;
		});
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(World::class, "provider"))->setValue($world, $provider);
		(new \ReflectionProperty(World::class, "folderName"))->setValue($world, "test");
		(new \ReflectionProperty(World::class, "logger"))->setValue($world, new \PrefixedLogger(\GlobalLogger::get(), "test"));
		$world->pulse = new WorldPulse($world);

		$chunks = [];
		for($x = 0; $x < 3; ++$x){
			$chunk = new Chunk([], true);
			$chunk->setTerrainDirtyFlag(Chunk::DIRTY_FLAG_BLOCKS, true);
			$chunks[World::chunkHash($x, 0)] = $chunk;
		}
		(new \ReflectionProperty(World::class, "chunks"))->setValue($world, $chunks);

		try{
			$world->saveChunks();
			self::fail("the failure should still surface");
		}catch(\RuntimeException $e){
			self::assertSame("disk full", $e->getMessage());
		}
		self::assertSame([1, 2], $saved->getArrayCopy(), "the chunks after the failed one are saved");
		self::assertTrue($chunks[World::chunkHash(0, 0)]->isTerrainDirty(), "the failed chunk stays dirty for the next save");
		self::assertFalse($chunks[World::chunkHash(1, 0)]->isTerrainDirty());
	}

	public function testStateTracksEntityAndTileData() : void{
		$state = new \ReflectionMethod(World::class, "chunkSaveState");
		$sign = CompoundTag::create()->setString("id", "Sign")->setString("Text", "a");
		$changed = CompoundTag::create()->setString("id", "Sign")->setString("Text", "b");
		$base = $state->invoke(null, true, [], [$sign]);
		self::assertSame($base, $state->invoke(null, true, [], [clone $sign]));
		self::assertNotSame($base, $state->invoke(null, true, [], [$changed]));
		self::assertNotSame($base, $state->invoke(null, true, [$sign], []), "the same tag as an entity instead of a tile differs");
		self::assertNotSame($base, $state->invoke(null, false, [], [$sign]));
		self::assertNotSame($state->invoke(null, true, [], []), $state->invoke(null, false, [], []));
	}
}
