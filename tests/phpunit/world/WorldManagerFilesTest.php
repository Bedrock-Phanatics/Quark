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
use quark\block\Button;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\entity\EntitySizeInfo;
use quark\entity\Location;
use quark\FilesystemPaths;
use quark\lang\Language;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\Vector3;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\Server;
use quark\ServerConfigGroup;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\WorldProviderManager;
use Symfony\Component\Filesystem\Path;
use function file_put_contents;
use function gc_collect_cycles;
use function is_dir;
use function is_file;
use function mkdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function uniqid;
use const SCANDIR_SORT_ASCENDING;

final class WorldManagerFilesTest extends TestCase{
	private string $root;
	private WorldManager $manager;
	/** @var AsyncTask[] */
	private array $tasks = [];
	/** @var (\Closure() : void)|null */
	private ?\Closure $onTaskSubmitted = null;

	protected function setUp() : void{
		PulseZones::init();
		$this->root = Path::join(sys_get_temp_dir(), "quark-worlds-" . uniqid());
		mkdir($this->root);
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("submitTask")->willReturnCallback(function(AsyncTask $task) : int{
			$this->tasks[] = $task;
			$this->onTaskSubmitted?->__invoke();
			return 0;
		});
		$server = $this->createMock(Server::class);
		$server->method("getAsyncPool")->willReturn($pool);
		$server->method("getLanguage")->willReturn(new Language("eng"));
		$server->method("getViewDistance")->willReturn(8);
		$configPath = tempnam(sys_get_temp_dir(), "quark-world-config-");
		self::assertNotFalse($configPath);
		file_put_contents($configPath, <<<'TOML'
			[chunks]
			tick-radius = 4
			random-ticks-per-subchunk = 5
			disabled-random-tick-blocks = ['sapling']

			[worlds."lobby.v6"]
			tick-radius = 0
			random-ticks-per-subchunk = 0

			[worlds.factions]
			tick-radius = 12

			[worlds.event]
			random-ticks-per-subchunk = 2
			TOML);
		try{
			$server->method("getConfigGroup")->willReturn(ServerConfigGroup::load($configPath));
		}finally{
			unlink($configPath);
		}
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
		$this->manager = new WorldManager($server, $this->root, new WorldProviderManager());
	}

	protected function tearDown() : void{
		Filesystem::recursiveUnlink($this->root);
	}

	public function testTickSettingsApplyToGeneratedLoadedAndReloadedWorlds() : void{
		foreach(["lobby.v6" => [0, 0], "factions" => [8, 5], "event" => [4, 2], "unlisted" => [4, 5]] as $name => [$radius, $randomTicks]){
			self::assertTrue($this->manager->generateWorld($name, WorldCreationOptions::create(), false));
			$world = $this->manager->getWorldByName($name);
			self::assertNotNull($world);
			self::assertSame($radius, $world->getChunkTickRadius());
			self::assertSame($randomTicks, $world->getRandomTicksPerSubchunk());
			if($randomTicks === 0){
				self::assertNull((new \ReflectionProperty(World::class, "randomTickBlocks"))->getValue($world));
			}
			self::assertArrayNotHasKey(VanillaBlocks::OAK_SAPLING()->getStateId(), $world->getRandomTickedBlocks());

			$world->setChunkTickRadius(1);
			$world->setRandomTicksPerSubchunk(1);
			self::assertTrue($this->manager->unloadWorld($world));
			self::assertTrue($this->manager->loadWorld($name));
			$reloaded = $this->manager->getWorldByName($name);
			self::assertNotNull($reloaded);
			self::assertNotSame($world, $reloaded);
			self::assertSame($radius, $reloaded->getChunkTickRadius());
			self::assertSame($randomTicks, $reloaded->getRandomTicksPerSubchunk());
			self::assertTrue($this->manager->unloadWorld($reloaded));
		}
	}

	public function testRandomBlockTicksAreIndependentOfEntityAndScheduledBlockUpdates() : void{
		self::assertTrue($this->manager->generateWorld("factions", WorldCreationOptions::create(), false));
		$world = $this->manager->getWorldByName("factions");
		self::assertNotNull($world);
		$world->setRandomTicksPerSubchunk(0);
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$chunk = new Chunk([], true);
				$chunk->setLightPopulated(true);
				$world->setChunk($x, $z, $chunk);
			}
		}
		$world->registerTickingChunk(new ChunkTicker(), 0, 0);
		$world->setBlockAt(0, 64, 0, VanillaBlocks::STONE(), false);
		$world->setBlockAt(0, 65, 0, VanillaBlocks::STONE_BUTTON()->setFacing(Facing::UP)->setPressed(true), false);
		$world->scheduleDelayedBlockUpdate(new Vector3(0, 65, 0), 1);

		$player = $this->createMock(Player::class);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new \SimpleLogger());
		(new \ReflectionProperty(Entity::class, "boundingBox"))->setValue($player, new AxisAlignedBB(8, 65, 8, 9, 67, 9));
		$player->method("getWorld")->willReturn($world);
		$player->method("getPosition")->willReturn(new Position(1, 65, 1, $world));
		$player->expects(self::once())->method("onRandomUpdate");
		$world->addEntity($player);
		try{
			$world->doTick(1);
		}finally{
			$world->removeEntity($player);
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}

		$button = $world->getBlockAt(0, 65, 0);
		self::assertInstanceOf(Button::class, $button);
		self::assertFalse($button->isPressed());
		self::assertTrue($this->manager->unloadWorld($world));
	}

	public function testPendingLightingRespectsRadiusChangesAndChunkReplacement() : void{
		self::assertTrue($this->manager->generateWorld("factions", WorldCreationOptions::create(), false));
		$world = $this->manager->getWorldByName("factions");
		self::assertNotNull($world);
		for($x = -1; $x <= 2; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$chunk = new Chunk([], true);
				$chunk->setLightPopulated($x !== -1 || $z !== -1);
				$world->setChunk($x, $z, $chunk);
			}
		}
		$ticker = new ChunkTicker();
		$world->registerTickingChunk($ticker, 0, 0);
		$world->registerTickingChunk($ticker, 1, 0);
		$this->onTaskSubmitted = static function() use ($world) : void{ $world->setChunkTickRadius(0); };
		$world->doTick(1);
		self::assertSame([], $world->getTickingChunks());
		self::assertCount(1, $this->tasks);

		$this->onTaskSubmitted = null;
		$replacement = new Chunk([], true);
		$world->setChunk(-1, -1, $replacement);
		$world->setChunkTickRadius(1);
		$world->doTick(2);
		self::assertCount(2, $this->tasks);
		[$oldTask, $replacementTask] = $this->tasks;
		$oldTask->onRun();
		$oldTask->onCompletion();
		self::assertNull($replacement->isLightPopulated());

		$replacementTask->onRun();
		$replacementTask->onCompletion();
		$world->doTick(3);
		self::assertTrue($replacement->isLightPopulated());
		self::assertSame([World::chunkHash(1, 0), World::chunkHash(0, 0)], $world->getTickingChunks());
		$worldRef = \WeakReference::create($world);
		self::assertTrue($this->manager->unloadWorld($world));
		unset($world);
		gc_collect_cycles();
		self::assertNull($worldRef->get());
	}

	public function testRadiusChangesReorderOnlyPlayersInTheCurrentWorld() : void{
		foreach(["lobby.v6", "factions"] as $name){
			self::assertTrue($this->manager->generateWorld($name, WorldCreationOptions::create(), false));
		}
		$lobby = $this->manager->getWorldByName("lobby.v6");
		$factions = $this->manager->getWorldByName("factions");
		self::assertNotNull($lobby);
		self::assertNotNull($factions);

		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()
			->onlyMethods(["despawnFromAll", "getId", "getNetworkSession", "isConnected", "requestChunkReorder"])->getMock();
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new \SimpleLogger());
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location(0, 65, 0, $factions, 0, 0));
		$player->size = new EntitySizeInfo(1.8, 0.6);
		$player->method("getId")->willReturn(1);
		$player->method("isConnected")->willReturn(true);
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("onEnterWorld");
		$player->method("getNetworkSession")->willReturn($session);
		$reorders = [];
		$player->method("requestChunkReorder")->willReturnCallback(static function() use ($player, &$reorders) : void{
			$reorders[] = $player->getWorld()->getFolderName();
		});

		$factions->addEntity($player);
		try{
			$factions->setChunkTickRadius(2);
			self::assertTrue((new \ReflectionMethod(Player::class, "setPosition"))->invoke($player, new Position(0, 65, 0, $lobby)));
			$factions->setChunkTickRadius(3);
			$lobby->setChunkTickRadius(1);
			$lobby->setChunkTickRadius(1);
		}finally{
			$player->getWorld()->removeEntity($player);
			(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		}

		self::assertSame(["factions", "lobby.v6"], $reorders);
		self::assertTrue($this->manager->unloadWorld($factions));
		self::assertTrue($this->manager->unloadWorld($lobby));
	}

	private function makeWorld(string $name) : void{
		(new WorldProviderManager())->getDefault()->generate(Path::join($this->root, $name) . "/", $name, WorldCreationOptions::create());
	}

	private function runTasks() : void{
		foreach($this->tasks as $task){
			$task->onRun();
			$task->onCompletion();
		}
		$this->tasks = [];
	}

	public function testWorldNames() : void{
		foreach(["world", "maps/arena", "Lobby 2"] as $name){
			self::assertTrue($this->manager->isValidWorldName($name), $name);
		}
		foreach(["", " ", ".", "..", "../outside", "maps/../../outside", "maps/..", "a\0b", "./world", "world/", "maps//arena", "/world", "C:/world", "maps\\arena", " world", "world.", ".hidden"] as $name){
			self::assertFalse($this->manager->isValidWorldName($name), $name);
			self::assertFalse($this->manager->isWorldGenerated($name), $name);
			self::assertFalse($this->manager->generateWorld($name, WorldCreationOptions::create()), $name);
		}
		$this->expectException(\InvalidArgumentException::class);
		$this->manager->loadWorld("../outside");
	}

	public function testListsOnlyWorlds() : void{
		$this->makeWorld("beta");
		$this->makeWorld("alpha");
		mkdir(Path::join($this->root, "empty"));
		file_put_contents(Path::join($this->root, "notes.txt"), "x");
		self::assertSame(["alpha", "beta"], $this->manager->getWorldNames());
	}

	public function testCopyReservesNamesUntilDone() : void{
		$this->makeWorld("template");
		$resolved = false;
		$this->manager->copyWorld("template", "arena")->observe(function() use (&$resolved) : void{ $resolved = true; }, fn() => self::fail("copy failed"));

		self::assertSame(["template"], $this->manager->getWorldNames());
		self::assertFalse($this->manager->loadWorld("template"));
		foreach(["arena", "ARENA", "arena/inner", "template/inner"] as $name){
			self::assertFalse($this->manager->generateWorld($name, WorldCreationOptions::create()), $name);
		}
		try{
			$this->manager->deleteWorld("template");
			self::fail("A busy world was deleted");
		}catch(\InvalidArgumentException){
		}

		$this->runTasks();
		self::assertTrue($resolved);
		self::assertSame(["arena", "template"], $this->manager->getWorldNames());
		self::assertTrue(is_file(Path::join($this->root, "arena", "level.dat")));
		$provider = (new WorldProviderManager())->getDefault()->fromPath(Path::join($this->root, "arena") . "/", new \PrefixedLogger(\GlobalLogger::get(), "test"));
		self::assertSame("arena", $provider->getWorldData()->getName());
		$provider->close();
	}

	public function testCopyRefusesExistingOrMissingWorlds() : void{
		$this->makeWorld("a");
		$this->makeWorld("b");
		foreach([["a", "b"], ["missing", "c"], ["a", "../c"], ["a", "a"], ["a", "a/inside"]] as [$from, $to]){
			try{
				$this->manager->copyWorld($from, $to);
				self::fail("Copied $from to $to");
			}catch(\InvalidArgumentException){
			}
		}
		self::assertSame([], $this->tasks);
	}

	public function testDeleteLeavesLinkedFilesAlone() : void{
		$this->makeWorld("doomed");
		$outside = Path::join($this->root, "..", "quark-outside-" . uniqid());
		mkdir($outside);
		file_put_contents(Path::join($outside, "keep.txt"), "keep");
		$linked = @symlink($outside, Path::join($this->root, "doomed", "link"));

		$failed = false;
		$this->manager->deleteWorld("doomed")->observe(fn() => null, function() use (&$failed) : void{ $failed = true; });
		$this->runTasks();

		self::assertFalse($failed);
		self::assertFalse(is_dir(Path::join($this->root, "doomed")));
		self::assertSame([".", ".."], scandir($this->root, SCANDIR_SORT_ASCENDING));
		self::assertSame([], $this->manager->getWorldNames());
		if($linked){
			self::assertTrue(is_file(Path::join($outside, "keep.txt")));
		}
		Filesystem::recursiveUnlink($outside);
	}

	public function testRemovesInterruptedWorldFiles() : void{
		$this->makeWorld("keep");
		$this->makeWorld("maps/arena");
		$leftovers = [
			Path::join($this->root, ".copy.copying-0123abcd"),
			Path::join($this->root, ".old.deleting-89abcdef"),
			Path::join($this->root, "maps", ".pvp.copying-deadbeef"),
		];
		foreach($leftovers as $path){
			mkdir(Path::join($path, "db"), 0777, true);
			file_put_contents(Path::join($path, "level.dat"), "x");
		}
		$untouched = [Path::join($this->root, ".mine"), Path::join($this->root, "keep", "db", ".x.copying-0123abcd")];
		foreach($untouched as $path){
			mkdir($path, 0777, true);
		}

		self::assertSame(3, $this->manager->removeInterruptedWorldFiles());
		$this->runTasks();
		foreach($leftovers as $path){
			self::assertFalse(is_dir($path), $path);
		}
		foreach($untouched as $path){
			self::assertTrue(is_dir($path), $path);
		}
		self::assertSame(["keep"], $this->manager->getWorldNames());
		self::assertSame([".", "..", "arena"], scandir(Path::join($this->root, "maps"), SCANDIR_SORT_ASCENDING));
		self::assertSame(0, $this->manager->removeInterruptedWorldFiles());
	}

	public function testFailedCopyCleansUpAndRejects() : void{
		$this->makeWorld("src");
		$rejected = false;
		$this->manager->copyWorld("src", "dst")->observe(fn() => self::fail("copy succeeded"), function() use (&$rejected) : void{ $rejected = true; });
		//pulling the source away makes the copy fail on the worker
		Filesystem::recursiveUnlink(Path::join($this->root, "src"));
		$this->runTasks();

		self::assertTrue($rejected);
		self::assertFalse(is_dir(Path::join($this->root, "dst")));
		self::assertSame([".", ".."], scandir($this->root, SCANDIR_SORT_ASCENDING));
		self::assertFalse($this->manager->isWorldGenerated("dst"));
		$this->makeWorld("src");
		$this->manager->copyWorld("src", "dst");
		self::assertCount(1, $this->tasks);
	}
}
