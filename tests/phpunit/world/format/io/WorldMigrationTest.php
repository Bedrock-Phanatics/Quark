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

namespace quark\world\format\io;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use quark\block\VanillaBlocks;
use quark\FilesystemPaths;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\Server;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Filesystem;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\io\zigrite\ZigriteDB;
use quark\world\WorldCreationOptions;
use quark\world\WorldManager;
use Symfony\Component\Filesystem\Path;
use function array_filter;
use function array_map;
use function array_shift;
use function array_unique;
use function array_values;
use function basename;
use function bin2hex;
use function count;
use function dirname;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fseek;
use function fwrite;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function pack;
use function phpversion;
use function random_bytes;
use function rename;
use function scandir;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function version_compare;
use const LEVELDB_ZLIB_RAW_COMPRESSION;
use const SCANDIR_SORT_ASCENDING;

final class WorldMigrationTest extends TestCase{
	private string $root;
	private string $worlds;
	private string $backups;
	private string $world;
	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $records;

	public function setUp() : void{
		$version = phpversion("zigritedb");
		if(!extension_loaded("leveldb") || $version === false || version_compare($version, "0.3.0", "<") || !\Zigrite\World::isSupported()){
			self::markTestSkipped("ext-zigritedb 0.3.0 with ZigriteDB storage, and ext-leveldb, are needed");
		}
		$this->root = Path::join(sys_get_temp_dir(), "quark-migration-" . bin2hex(random_bytes(6)));
		$this->worlds = Path::join($this->root, "worlds");
		$this->backups = Path::join((new FilesystemPaths($this->root, Path::join($this->root, "plugins")))->backups(), "worlds");
		$this->world = Path::join($this->worlds, "world");
		mkdir($this->worlds, 0777, true);
		$this->records = self::buildWorld($this->world);
	}

	public function tearDown() : void{
		if(isset($this->root)){
			Filesystem::recursiveUnlink($this->root);
		}
	}

	private static function logger() : \Logger{
		return new \PrefixedLogger(\GlobalLogger::get(), "WorldMigrationTest");
	}

	private static function chunk(int $seed) : ChunkData{
		$chunk = new Chunk([], true);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$chunk->setBlockStateId($x, ($x * 16 + $z + $seed) % 200 - 64, $z, ($x + $seed) % 2 === 0 ? VanillaBlocks::STONE()->getStateId() : VanillaBlocks::DIRT()->getStateId());
			}
		}
		$tiles = [];
		$entities = [];
		for($i = 0; $i < 40; ++$i){
			$tiles[] = CompoundTag::create()->setString("id", "Sign")->setInt("x", $i)->setInt("y", 64)->setInt("z", $seed)->setTag("", new ListTag([new StringTag("line $i")]));
			$entities[] = CompoundTag::create()->setString("identifier", "minecraft:pig")->setTag("Pos", new ListTag([]))->setString("Tag", "$seed-$i");
		}
		return new ChunkData($chunk->getSubChunks(), true, $entities, $tiles);
	}

	/**
	 * A Quark-saved world plus the records vanilla Bedrock keeps beside chunks, in every dimension.
	 *
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	private static function buildWorld(string $path) : array{
		LevelDB::generate($path, "Migration Test", WorldCreationOptions::create());
		file_put_contents(Path::join($path, "levelname.txt"), "Migration Test");
		mkdir(Path::join($path, "behavior_packs", "pack"), 0777, true);
		file_put_contents(Path::join($path, "behavior_packs", "pack", "manifest.json"), "{}");

		$provider = new LevelDB($path, self::logger());
		foreach([[0, 0], [-1, -1], [-300, 451], [2_000_000, -2_000_000]] as $i => [$x, $z]){
			$provider->saveChunk($x, $z, self::chunk($i), Chunk::DIRTY_FLAGS_ALL);
		}
		$db = $provider->getDatabase();
		$batch = new \LevelDBWriteBatch();
		foreach([1, 2] as $dimension){
			$index = pack("VVV", -5 & 0xffffffff, 7, $dimension);
			$batch->put($index . "\x2c", "\x28");
			$batch->put($index . "\x2f\xfc", str_repeat("s$dimension", 3000));
			$batch->put($index . "\x32", str_repeat("e", 500));
			$batch->put("digp" . $index, pack("P", $dimension));
		}
		$batch->put("digp" . pack("VV", 0, 0), pack("PP", 1, 2));
		for($i = 0; $i < 2000; ++$i){
			$batch->put("actorprefix" . pack("J", $i), str_repeat("actor", 40) . $i);
		}
		$batch->put("~local_player", str_repeat("p", 3000));
		$batch->put("player_server_8f3b2f44-0000-4000-8000-000000000001", "inventory");
		$batch->put("map_-4294967295", str_repeat("m", 16384));
		$batch->put("scoreboard", "objectives");
		$batch->put("BiomeData", "biomes");
		$batch->put("FutureKey\x00\xff", "unknown to everyone");
		$batch->put("big_record", random_bytes(3 * 1024 * 1024));
		$db->write($batch);
		unset($db);
		$provider->close();
		return self::records($path);
	}

	/**
	 * @return string[]
	 * @phpstan-return array<string, string>
	 */
	private static function records(string $path) : array{
		$db = new \LevelDB(Path::join($path, "db"), ["compression" => LEVELDB_ZLIB_RAW_COMPRESSION]);
		$records = [];
		foreach($db->getIterator() as $key => $value){
			$records[(string) $key] = $value;
		}
		unset($db);
		return $records;
	}

	private static function fingerprint(?LoadedChunkData $loaded) : string{
		self::assertNotNull($loaded);
		$data = $loaded->getData();
		return FastChunkSerializer::serializeTerrain(new Chunk($data->getSubChunks(), $data->isPopulated())) . "|" .
			implode(",", array_map(fn(CompoundTag $tag) => $tag->toString(), $data->getEntityNBT())) . "|" .
			implode(",", array_map(fn(CompoundTag $tag) => $tag->toString(), $data->getTileNBT()));
	}

	/**
	 * @return string[]
	 */
	private function hiddenLeftovers() : array{
		return array_values(array_filter(Utils::assumeNotFalse(scandir($this->worlds, SCANDIR_SORT_ASCENDING)), fn(string $entry) => $entry[0] === "." && $entry !== "." && $entry !== ".."));
	}

	public function testRoundTripKeepsEveryRecordAndFile() : void{
		$levelDat = file_get_contents(Path::join($this->world, "level.dat"));
		$result = WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
		self::assertSame(WorldMigration::LEVELDB, $result["from"]);
		self::assertSame(count($this->records), $result["chunk_records"] + $result["other_records"]);
		self::assertSame(WorldMigration::ZIGRITEDB, WorldMigration::detectFormat($this->world));
		self::assertTrue(ZigriteDB::isValid($this->world));
		self::assertFalse(LevelDB::isValid($this->world));
		self::assertSame([], $this->hiddenLeftovers());
		self::assertSame($this->records, self::records($result["backup"]), "the backup is the untouched original");
		self::assertGreaterThan(0, WorldMigration::verify($this->world)["regions"]);

		$back = WorldMigration::convert($this->world, WorldMigration::LEVELDB, $this->backups);
		self::assertSame(WorldMigration::ZIGRITEDB, $back["from"]);
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		self::assertSame($this->records, self::records($this->world));
		self::assertSame($levelDat, file_get_contents(Path::join($this->world, "level.dat")));
		self::assertSame("{}", file_get_contents(Path::join($this->world, "behavior_packs", "pack", "manifest.json")));
		self::assertSame("Migration Test", file_get_contents(Path::join($this->world, "levelname.txt")));
		self::assertTrue(ZigriteDB::isValid($back["backup"]));
		self::assertSame([], $this->hiddenLeftovers());
	}

	public function testConvertedWorldLoadsSavesAndReopens() : void{
		$original = new LevelDB($this->world, self::logger());
		$expected = [];
		foreach([[0, 0], [-1, -1], [-300, 451], [2_000_000, -2_000_000]] as [$x, $z]){
			$expected["$x:$z"] = self::fingerprint($original->loadChunk($x, $z));
		}
		$original->close();

		WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
		$provider = new ZigriteDB($this->world, self::logger());
		self::assertSame("Migration Test", $provider->getWorldData()->getName());
		self::assertSame(count($expected), $provider->calculateChunkCount());
		foreach($expected as $coords => $fingerprint){
			[$x, $z] = array_map("intval", explode(":", $coords, 2));
			self::assertSame($fingerprint, self::fingerprint($provider->loadChunk($x, $z)), $coords);
		}
		$provider->saveChunk(-1, -1, self::chunk(9), Chunk::DIRTY_FLAGS_ALL);
		$provider->saveChunk(40, 40, self::chunk(10), Chunk::DIRTY_FLAGS_ALL);
		$saved = self::fingerprint($provider->loadChunk(-1, -1));
		$added = self::fingerprint($provider->loadChunk(40, 40));
		self::assertNotSame($expected["-1:-1"], $saved);
		$provider->getWorldData()->setName("Renamed");
		$provider->getWorldData()->save();
		$provider->close();

		$provider = new ZigriteDB($this->world, self::logger());
		self::assertSame("Renamed", $provider->getWorldData()->getName());
		self::assertSame($saved, self::fingerprint($provider->loadChunk(-1, -1)));
		self::assertSame($added, self::fingerprint($provider->loadChunk(40, 40)));
		self::assertSame($expected["0:0"], self::fingerprint($provider->loadChunk(0, 0)));
		$provider->close();

		//saved chunks survive the way back to LevelDB too
		WorldMigration::convert($this->world, WorldMigration::LEVELDB, $this->backups);
		$provider = new LevelDB($this->world, self::logger());
		self::assertSame($added, self::fingerprint($provider->loadChunk(40, 40)));
		self::assertSame($saved, self::fingerprint($provider->loadChunk(-1, -1)));
		self::assertSame("Renamed", $provider->getWorldData()->getName());
		$provider->close();
	}

	public function testDamagedLevelDBLeavesTheWorldAlone() : void{
		//reopening the database in buildWorld() already flushed its log into tables
		$tables = Utils::assumeNotFalse(glob(Path::join($this->world, "db", "*.ldb")));
		self::assertNotEmpty($tables);
		$handle = fopen($tables[0], "r+");
		self::assertNotFalse($handle);
		fseek($handle, 100);
		fwrite($handle, "corrupted bytes in the middle of a block");

		try{
			WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
			self::fail("A damaged world was converted");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("the world was not changed", $e->getMessage());
			self::assertStringContainsString("damaged", $e->getMessage());
		}
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		self::assertSame([], $this->hiddenLeftovers());
		self::assertFalse(is_dir($this->backups));
	}

	public function testWorldOpenElsewhereIsRefused() : void{
		$provider = new LevelDB($this->world, self::logger());
		try{
			WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
			self::fail("A world in use was converted");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("open in another program", $e->getMessage());
		}finally{
			$provider->close();
		}
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		self::assertSame([], $this->hiddenLeftovers());
	}

	public function testRefusesWhatItCannotConvertSafely() : void{
		symlink($this->world, $link = Path::join($this->worlds, "link"));
		foreach([
			[$link, WorldMigration::ZIGRITEDB, "symlink"],
			[$this->world, WorldMigration::LEVELDB, "already"],
			[$this->worlds, WorldMigration::ZIGRITEDB, "not a LevelDB or ZigriteDB world"],
		] as [$path, $format, $message]){
			try{
				WorldMigration::convert($path, $format, $this->backups);
				self::fail("$path was converted to $format");
			}catch(\RuntimeException $e){
				self::assertStringContainsString($message, $e->getMessage());
			}
		}
		$this->expectException(\RuntimeException::class);
		WorldMigration::verify($this->world);
	}

	public function testWorldsInsideTheWorldAreRefused() : void{
		LevelDB::generate($inner = Path::join($this->world, "maps", "arena"), "Arena", WorldCreationOptions::create());
		try{
			WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
			self::fail("A world holding another world was converted");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("$inner is a world inside", $e->getMessage());
		}
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		self::assertSame([], $this->hiddenLeftovers());
	}

	public function testOriginalStaysBesideTheWorldWhenBackupsAreUnusable() : void{
		file_put_contents($blocked = Path::join($this->root, "not-a-folder"), "");
		$backup = WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $blocked)["backup"];
		self::assertSame($this->worlds, dirname($backup));
		self::assertMatchesRegularExpression('/^\.world\.leveldb-.+-backup$/', basename($backup));
		self::assertSame($this->records, self::records($backup));
		//it's a finished backup, not an unfinished conversion, so deleting the world doesn't bring it back
		Filesystem::recursiveUnlink($this->world);
		self::assertSame([], WorldMigration::recover($this->worlds, $blocked));
		self::assertFalse(is_dir($this->world));
	}

	public function testRepeatedConversionsKeepEveryBackup() : void{
		$backups = [];
		foreach([WorldMigration::ZIGRITEDB, WorldMigration::LEVELDB, WorldMigration::ZIGRITEDB, WorldMigration::LEVELDB] as $format){
			$backups[] = WorldMigration::convert($this->world, $format, $this->backups)["backup"];
			self::assertSame($format, WorldMigration::detectFormat($this->world));
		}
		self::assertCount(4, array_unique($backups));
		self::assertSame($this->records, self::records($this->world));
		self::assertSame($this->records, self::records($backups[2]));
	}

	public function testEmptyWorldConverts() : void{
		$empty = Path::join($this->worlds, "empty");
		LevelDB::generate($empty, "Empty", WorldCreationOptions::create());
		(new LevelDB($empty, self::logger()))->close();
		$result = WorldMigration::convert($empty, WorldMigration::ZIGRITEDB, $this->backups);
		self::assertSame(0, $result["chunk_records"]);
		$provider = new ZigriteDB($empty, self::logger());
		self::assertSame(0, $provider->calculateChunkCount());
		self::assertSame("Empty", $provider->getWorldData()->getName());
		$provider->close();
		WorldMigration::convert($empty, WorldMigration::LEVELDB, $this->backups);
		self::assertSame([], self::records($empty));
	}

	public function testInterruptedConversionsAreRecovered() : void{
		//moved aside, but the converted world never made it into place
		WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
		$converted = Path::join($this->worlds, ".world.converting-0badc0de");
		rename($this->world, $converted);
		$aside = glob(Path::join($this->backups, "world-leveldb-*"))[0] ?? self::fail("no backup");
		rename($aside, $original = Path::join($this->worlds, ".world.original-12345678"));
		mkdir(Path::join($this->worlds, ".other.converting-89abcdef.zigrite-partial"));

		self::assertSame(2, $this->manager()->removeInterruptedWorldFiles(), "the restored world is not a leftover");
		$this->runTasks();
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		self::assertSame($this->records, self::records($this->world));
		self::assertSame([], $this->hiddenLeftovers());

		//the converted world is in place, only the original wasn't moved to the backups yet
		WorldMigration::convert($this->world, WorldMigration::ZIGRITEDB, $this->backups);
		$aside = glob(Path::join($this->backups, "world-leveldb-*"))[0] ?? self::fail("no backup");
		rename($aside, $original);
		self::assertSame(["Finished converting world " . $this->world . ", its original is at " . $aside], WorldMigration::recover($this->worlds, $this->backups));
		self::assertSame(WorldMigration::ZIGRITEDB, WorldMigration::detectFormat($this->world));
		self::assertSame($this->records, self::records($aside));
		self::assertSame([], $this->hiddenLeftovers());
	}

	public function testManagerReservesTheWorldWhileConverting() : void{
		$manager = $this->manager();
		$done = null;
		$manager->convertWorld("world", "ZigriteDB")->observe(function(array $result) use (&$done) : void{ $done = $result; }, fn(\Throwable $e) => self::fail($e->getMessage()));
		self::assertFalse($manager->loadWorld("world"));
		self::assertFalse($manager->generateWorld("world/inner", WorldCreationOptions::create()));
		try{
			$manager->deleteWorld("world");
			self::fail("A world being converted was deleted");
		}catch(\InvalidArgumentException){
		}
		$this->runTasks();
		self::assertIsArray($done);
		self::assertSame(WorldMigration::ZIGRITEDB, WorldMigration::detectFormat($this->world));

		$failed = null;
		$manager->verifyWorld("world")->observe(fn(array $result) => self::assertSame(0, $result["problems"]), fn(\Throwable $e) => self::fail($e->getMessage()));
		try{
			$manager->convertWorld("world", "leveldb");
			self::fail("Two requests ran on one world at once");
		}catch(\InvalidArgumentException){
		}
		$this->runTasks();
		$manager->convertWorld("world", "zigritedb")->observe(fn() => self::fail("converted twice"), function(\Throwable $e) use (&$failed) : void{ $failed = $e->getMessage(); });
		$this->runTasks();
		self::assertIsString($failed);
		self::assertStringContainsString("already a zigritedb world", $failed);

		$manager->setMigrationMode(WorldMigration::MODE_OFF);
		$this->expectException(\InvalidArgumentException::class);
		$manager->convertWorld("world", "leveldb");
	}

	public function testStartupMigrationOnlyRunsWhenAutomatic() : void{
		$manager = $this->manager();
		$manager->migrateOnStartup("world");
		self::assertSame(WorldMigration::LEVELDB, WorldMigration::detectFormat($this->world));
		$manager->setMigrationMode(WorldMigration::MODE_AUTOMATIC);
		$manager->migrateOnStartup("world");
		$manager->migrateOnStartup("missing");
		self::assertSame(WorldMigration::ZIGRITEDB, WorldMigration::detectFormat($this->world));
		$manager->migrateOnStartup("world");
		self::assertCount(1, Utils::assumeNotFalse(glob(Path::join($this->backups, "world-*"))));
	}

	/** @var AsyncTask[] */
	private array $tasks = [];

	private function manager() : WorldManager{
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("submitTask")->willReturnCallback(function(AsyncTask $task) : int{
			$this->tasks[] = $task;
			return 0;
		});
		$server = $this->createMock(Server::class);
		$server->method("getAsyncPool")->willReturn($pool);
		$server->method("getFilesystemPaths")->willReturn(new FilesystemPaths($this->root, Path::join($this->root, "plugins")));
		$server->method("getLogger")->willReturn(new class extends AttachableThreadSafeLogger{
			public function emergency($message){}
			public function alert($message){}
			public function critical($message){}
			public function error($message){}
			public function warning($message){}
			public function notice($message){}
			public function info($message){}
			public function debug($message){}
			public function log($level, $message){}
			public function logException(\Throwable $e, $trace = null){}
		});
		return new WorldManager($server, $this->worlds, new WorldProviderManager());
	}

	private function runTasks() : void{
		while(($task = array_shift($this->tasks)) !== null){
			$task->onRun();
			$task->onCompletion();
		}
	}
}
