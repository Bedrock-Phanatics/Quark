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
use pocketmine\snooze\SleeperHandler;
use quark\scheduler\AsyncPool;
use quark\thread\ThreadSafeClassLoader;
use quark\utils\Filesystem;
use quark\utils\MainLogger;
use Symfony\Component\Filesystem\Path;
use Vow\Promise;
use function bin2hex;
use function define;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function microtime;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;
use function usleep;
use function zlib_decode;
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
		self::assertTrue($provider->saveData("Steve", self::data())->isFulfilled());
		self::assertTrue($provider->hasData("STEVE")->value());
		$loaded = $provider->loadData("steve")->value();
		self::assertNotNull($loaded);
		self::assertTrue(self::data()->equals($loaded));
	}

	public function testFilesWrittenByPocketMineStillLoad() : void{
		$contents = zlib_encode((new BigEndianNbtSerializer())->write(new TreeRoot(self::data())), ZLIB_ENCODING_GZIP);
		self::assertIsString($contents);
		file_put_contents(Path::join($this->path, "steve.dat"), $contents);
		$loaded = (new DatFilePlayerDataProvider($this->path))->loadData("Steve")->value();
		self::assertNotNull($loaded);
		self::assertTrue(self::data()->equals($loaded));
	}

	public function testCorruptDataIsMovedAside() : void{
		$contents = zlib_encode("\x0a\x00\x00\x08\x00\x01a\x00\x05", ZLIB_ENCODING_GZIP);
		self::assertIsString($contents);
		file_put_contents(Path::join($this->path, "steve.dat"), $contents);
		$result = (new DatFilePlayerDataProvider($this->path))->loadData("Steve");
		self::assertInstanceOf(CorruptPlayerDataException::class, $result->reason());
		$result->observe(null, static fn() => null);
		self::assertTrue(file_exists(Path::join($this->path, "steve.dat.bak")));
		self::assertFalse(file_exists(Path::join($this->path, "steve.dat")));
	}

	public function testSecondCorruptionKeepsTheFirstBackup() : void{
		$provider = new DatFilePlayerDataProvider($this->path);
		$file = Path::join($this->path, "steve.dat");
		foreach(["first", "second"] as $content){
			file_put_contents($file, zlib_encode($content, ZLIB_ENCODING_GZIP));
			$provider->loadData("Steve")->observe(null, static fn() => null);
		}
		self::assertSame("first", zlib_decode((string) file_get_contents($file . ".bak")));
		self::assertSame("second", zlib_decode((string) file_get_contents($file . ".bak.1")));
	}

	public function testUnreadableDataIsLeftInPlace() : void{
		$file = Path::join($this->path, "steve.dat");
		mkdir($file);
		$result = (new DatFilePlayerDataProvider($this->path))->loadData("Steve");
		$result->observe(null, static fn() => null);
		self::assertTrue($result->isRejected());
		self::assertNotInstanceOf(CorruptPlayerDataException::class, $result->reason());
		self::assertInstanceOf(PlayerDataLoadException::class, $result->reason());
		self::assertTrue(is_dir($file));
	}

	/**
	 * @phpstan-param \Closure(AsyncPool) : void $test
	 */
	private function withPool(\Closure $test) : void{
		@define('quark\\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__, 3) . '/vendor/autoload.php');
		$logger = new MainLogger(null, false, "Main", new \DateTimeZone('UTC'));
		$pool = new AsyncPool(2, 1024, new ThreadSafeClassLoader(), $logger, new SleeperHandler());
		try{
			$test($pool);
		}finally{
			$pool->shutdown();
			$logger->shutdownLogWriterThread();
		}
	}

	/**
	 * @phpstan-param Promise<null> $promise
	 */
	private static function settle(AsyncPool $pool, Promise $promise) : void{
		$deadline = microtime(true) + 30;
		while($promise->isPending() && microtime(true) < $deadline){
			$pool->collectTasks();
			usleep(1000);
		}
		self::assertFalse($promise->isPending(), "the save never settled");
	}

	public function testPooledSaveWritesOnAWorker() : void{
		$this->withPool(function(AsyncPool $pool) : void{
			$provider = new DatFilePlayerDataProvider($this->path, $pool);
			$saved = $provider->saveData("Steve", self::data());
			self::assertTrue($saved->isPending(), "the file is written off the main thread");
			self::settle($pool, $saved);
			self::assertTrue($saved->isFulfilled());

			$loaded = $provider->loadData("steve")->value();
			self::assertNotNull($loaded);
			self::assertTrue(self::data()->equals($loaded));
		});
	}

	public function testPooledSaveFailureRejectsThePromise() : void{
		$this->withPool(function(AsyncPool $pool) : void{
			$provider = new DatFilePlayerDataProvider(Path::join($this->path, "missing"), $pool);
			$saved = $provider->saveData("Steve", self::data());
			self::settle($pool, $saved);
			self::assertTrue($saved->isRejected());
			$saved->observe(null, static fn() => null);
			self::assertInstanceOf(PlayerDataSaveException::class, $saved->reason());
		});
	}
}
