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

namespace quark\world\format\io\zigrite;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use quark\block\utils\DyeColor;
use quark\block\VanillaBlocks;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\exception\WorldInUseException;
use quark\world\format\io\FastChunkSerializer;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\io\LoadedChunkData;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function bin2hex;
use function count;
use function extension_loaded;
use function implode;
use function iterator_to_array;
use function random_bytes;
use function sys_get_temp_dir;

final class ZigriteDBTest extends TestCase{
	private string $path;

	public function setUp() : void{
		if(!extension_loaded("zigritedb") || !\Zigrite\World::isSupported()){
			self::markTestSkipped("ext-zigritedb is not available");
		}
		$this->path = Path::join(sys_get_temp_dir(), "quark-zigritedb-" . bin2hex(random_bytes(6)));
		ZigriteDB::generate($this->path, "test", WorldCreationOptions::create());
	}

	public function tearDown() : void{
		if(isset($this->path)){
			Filesystem::recursiveUnlink($this->path);
		}
	}

	private function open() : ZigriteDB{
		return new ZigriteDB($this->path, new \PrefixedLogger(\GlobalLogger::get(), "ZigriteDBTest"));
	}

	private static function chunk(int $seed) : ChunkData{
		$chunk = new Chunk([], true);
		$states = [VanillaBlocks::STONE()->getStateId(), VanillaBlocks::DIRT()->getStateId()];
		foreach(DyeColor::cases() as $color){
			$states[] = VanillaBlocks::WOOL()->setColor($color)->getStateId();
		}
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, ($x * 16 + $z + $seed) % 128 - 64, $z, $states[($x + $z + $seed) % count($states)]);
			}
		}
		$chunk->setBiomeId(3, 70, 3, 21);
		$tile = CompoundTag::create()
			->setString("id", "Sign")
			->setInt("x", 1)->setInt("y", 2)->setInt("z", 3)
			->setTag("", new ListTag([new StringTag("seed $seed")]));
		$entity = CompoundTag::create()->setString("identifier", "minecraft:pig")->setTag("Pos", new ListTag([]));
		return new ChunkData($chunk->getSubChunks(), true, [$entity], [$tile]);
	}

	/**
	 * Everything a provider hands back, so two loads can be compared exactly.
	 */
	private static function fingerprint(?LoadedChunkData $loaded) : string{
		self::assertNotNull($loaded);
		$data = $loaded->getData();
		return FastChunkSerializer::serializeTerrain(new Chunk($data->getSubChunks(), $data->isPopulated())) . "|" .
			implode(",", array_map(fn(CompoundTag $tag) => $tag->toString(), $data->getEntityNBT())) . "|" .
			implode(",", array_map(fn(CompoundTag $tag) => $tag->toString(), $data->getTileNBT())) . "|" .
			($loaded->isUpgraded() ? "upgraded" : "current");
	}

	public function testChunksRoundTripAndPersist() : void{
		$provider = $this->open();
		$provider->saveChunk(0, 0, self::chunk(1), Chunk::DIRTY_FLAGS_ALL);
		$provider->saveChunk(-1, -1, self::chunk(2), Chunk::DIRTY_FLAGS_ALL);
		$first = self::fingerprint($provider->loadChunk(0, 0));
		self::assertNotSame($first, self::fingerprint($provider->loadChunk(-1, -1)));
		self::assertNull($provider->loadChunk(5, 5));
		self::assertSame(2, $provider->calculateChunkCount());
		$provider->close();

		$provider = $this->open();
		self::assertSame($first, self::fingerprint($provider->loadChunk(0, 0)));
		$loaded = $provider->loadChunk(0, 0);
		self::assertNotNull($loaded);
		self::assertCount(1, $loaded->getData()->getTileNBT(), "tiles are stored under the numeric \"1\" suffix");
		self::assertCount(1, $loaded->getData()->getEntityNBT(), "entities are stored under the numeric \"2\" suffix");
		self::assertCount(2, iterator_to_array($provider->getAllChunks(), false));
		$provider->close();
	}

	public function testPartialSavesKeepUntouchedRecords() : void{
		$provider = $this->open();
		$full = self::chunk(3);
		$provider->saveChunk(0, 0, $full, Chunk::DIRTY_FLAGS_ALL);
		$before = $provider->loadChunk(0, 0);
		self::assertNotNull($before);

		//only entities changed: terrain is left alone and the emptied entity list is deleted
		$provider->saveChunk(0, 0, new ChunkData($full->getSubChunks(), true, [], $full->getTileNBT()), Chunk::DIRTY_FLAGS_NONE);
		$after = $provider->loadChunk(0, 0);
		self::assertNotNull($after);
		self::assertSame([], $after->getData()->getEntityNBT());
		self::assertSame(
			FastChunkSerializer::serializeTerrain(new Chunk($before->getData()->getSubChunks(), true)),
			FastChunkSerializer::serializeTerrain(new Chunk($after->getData()->getSubChunks(), true))
		);
		$provider->close();
	}

	public function testLoadsMatchLevelDB() : void{
		$levelDbPath = Path::join(sys_get_temp_dir(), "quark-zigritedb-leveldb-" . bin2hex(random_bytes(6)));
		LevelDB::generate($levelDbPath, "test", WorldCreationOptions::create());
		try{
			$levelDb = new LevelDB($levelDbPath, new \PrefixedLogger(\GlobalLogger::get(), "ZigriteDBTest"));
			$zigrite = $this->open();
			foreach([[0, 0, 4], [3, -9, 5], [-40, 17, 6]] as [$x, $z, $seed]){
				$data = self::chunk($seed);
				$levelDb->saveChunk($x, $z, $data, Chunk::DIRTY_FLAGS_ALL);
				$zigrite->saveChunk($x, $z, $data, Chunk::DIRTY_FLAGS_ALL);
				self::assertSame(self::fingerprint($levelDb->loadChunk($x, $z)), self::fingerprint($zigrite->loadChunk($x, $z)));
			}
			$levelDb->close();
			$zigrite->close();
		}finally{
			Filesystem::recursiveUnlink($levelDbPath);
		}
	}

	public function testGeneratedWorldIsRecognised() : void{
		self::assertTrue(ZigriteDB::isValid($this->path));
		self::assertFalse(LevelDB::isValid($this->path));
		self::assertSame("test", $this->open()->getWorldData()->getName());
	}

	public function testWorldOpenElsewhereIsReportedAsInUse() : void{
		$first = $this->open();
		try{
			$this->expectException(WorldInUseException::class);
			$this->open();
		}finally{
			$first->close();
		}
	}
}
