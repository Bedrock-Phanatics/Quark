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

namespace quark\player;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\TreeRoot;
use quark\utils\Filesystem;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function file_exists;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function zlib_encode;
use const ZLIB_ENCODING_GZIP;

final class DatFilePlayerDataProviderTest extends TestCase{
	private string $path;

	public function setUp() : void{
		$this->path = Path::join(sys_get_temp_dir(), "quark-players-" . bin2hex(random_bytes(6)));
		mkdir($this->path);
	}

	public function tearDown() : void{
		Filesystem::recursiveUnlink($this->path);
	}

	private static function data() : CompoundTag{
		return CompoundTag::create()
			->setString("NameTag", "Steve")
			->setLong("lastPlayed", 1700000000000)
			->setTag("Inventory", new ListTag([CompoundTag::create()->setString("Name", "minecraft:stone")->setByte("Count", 64)->setByte("Slot", 0)]))
			->setTag("", new ListTag([new StringTag("unnamed")]));
	}

	public function testDataRoundTrips() : void{
		$provider = new DatFilePlayerDataProvider($this->path);
		$provider->saveData("Steve", self::data());
		$loaded = $provider->loadData("steve");
		self::assertNotNull($loaded);
		self::assertTrue(self::data()->equals($loaded));
	}

	public function testFilesWrittenByPocketMineStillLoad() : void{
		$contents = zlib_encode((new BigEndianNbtSerializer())->write(new TreeRoot(self::data())), ZLIB_ENCODING_GZIP);
		self::assertIsString($contents);
		file_put_contents(Path::join($this->path, "steve.dat"), $contents);
		$loaded = (new DatFilePlayerDataProvider($this->path))->loadData("Steve");
		self::assertNotNull($loaded);
		self::assertTrue(self::data()->equals($loaded));
	}

	public function testCorruptDataIsMovedAside() : void{
		$contents = zlib_encode("\x0a\x00\x00\x08\x00\x01a\x00\x05", ZLIB_ENCODING_GZIP);
		self::assertIsString($contents);
		file_put_contents(Path::join($this->path, "steve.dat"), $contents);
		try{
			(new DatFilePlayerDataProvider($this->path))->loadData("Steve");
			self::fail("Loaded corrupt player data");
		}catch(PlayerDataLoadException){
			self::assertTrue(file_exists(Path::join($this->path, "steve.dat.bak")));
		}
	}
}
