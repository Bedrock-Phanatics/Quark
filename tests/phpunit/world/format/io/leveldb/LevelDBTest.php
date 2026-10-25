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

namespace quark\world\format\io\leveldb;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use quark\block\utils\DyeColor;
use quark\block\VanillaBlocks;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\exception\CorruptedChunkException;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function chr;
use function count;
use function random_bytes;
use function substr;
use function sys_get_temp_dir;

final class LevelDBTest extends TestCase{
	private string $path;

	public function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), "quark-leveldb-" . bin2hex(random_bytes(6)));
		LevelDB::generate($this->path, "test", WorldCreationOptions::create());
	}

	public function tearDown() : void{
		Filesystem::recursiveUnlink($this->path);
	}

	private function open() : LevelDB{
		return new LevelDB($this->path, new \PrefixedLogger(\GlobalLogger::get(), "LevelDBTest"));
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	private static function states() : array{
		$states = [VanillaBlocks::STONE()->getStateId(), VanillaBlocks::DIRT()->getStateId()];
		foreach(DyeColor::cases() as $color){
			$states[] = VanillaBlocks::WOOL()->setColor($color)->getStateId();
		}
		return $states;
	}

	private static function chunk() : ChunkData{
		$chunk = new Chunk([], true);
		$states = self::states();
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, ($x * 16 + $z) % 64, $z, $states[($x + $z) % count($states)]);
			}
		}
		$tile = CompoundTag::create()
			->setString("id", "Sign")
			->setInt("x", 1)->setInt("y", 2)->setInt("z", 3)
			->setTag("", new ListTag([new StringTag("unnamed")]));
		$entity = CompoundTag::create()->setString("identifier", "minecraft:pig")->setTag("Pos", new ListTag([]));
		return new ChunkData($chunk->getSubChunks(), true, [$entity], [$tile]);
	}

	private static function assertSameChunk(ChunkData $expected, ChunkData $actual) : void{
		foreach($expected->getSubChunks() as $y => $subChunk){
			for($x = 0; $x < 16; ++$x){
				for($z = 0; $z < 16; ++$z){
					for($by = 0; $by < 16; ++$by){
						self::assertSame($subChunk->getBlockStateId($x, $by, $z), $actual->getSubChunks()[$y]->getBlockStateId($x, $by, $z));
					}
				}
			}
		}
		self::assertCount(1, $actual->getTileNBT());
		self::assertTrue($expected->getTileNBT()[0]->equals($actual->getTileNBT()[0]));
		self::assertCount(1, $actual->getEntityNBT());
		self::assertTrue($expected->getEntityNBT()[0]->equals($actual->getEntityNBT()[0]));
	}

	public function testChunksRoundTripThroughThePaletteCaches() : void{
		$expected = self::chunk();
		$provider = $this->open();
		$provider->saveChunk(0, 0, $expected, Chunk::DIRTY_FLAGS_ALL);
		$provider->saveChunk(1, 0, $expected, Chunk::DIRTY_FLAGS_ALL);

		foreach([[$provider, 0], [$provider, 1], [$provider, 0]] as [$reader, $chunkX]){
			$loaded = $reader->loadChunk($chunkX, 0);
			self::assertNotNull($loaded);
			self::assertSameChunk($expected, $loaded->getData());
		}
		$provider->close();

		$fresh = $this->open();
		$loaded = $fresh->loadChunk(1, 0);
		self::assertNotNull($loaded);
		self::assertSameChunk($expected, $loaded->getData());
		$fresh->close();
	}

	public function testCorruptPaletteNbtIsReported() : void{
		$provider = $this->open();
		$provider->saveChunk(0, 0, self::chunk(), Chunk::DIRTY_FLAGS_ALL);
		$key = LevelDB::chunkIndex(0, 0) . ChunkDataKey::SUBCHUNK . chr(0);
		$database = $provider->getDatabase();
		$data = $database->get($key);
		self::assertIsString($data);
		$database->put($key, substr($data, 0, -3));

		$this->expectException(CorruptedChunkException::class);
		try{
			$provider->loadChunk(0, 0);
		}finally{
			$provider->close();
		}
	}
}
