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
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;
use quark\Server;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Filesystem;
use quark\world\format\io\WorldProviderManager;
use Symfony\Component\Filesystem\Path;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use const SCANDIR_SORT_ASCENDING;

final class WorldManagerFilesTest extends TestCase{
	private string $root;
	private WorldManager $manager;
	/** @var AsyncTask[] */
	private array $tasks = [];

	protected function setUp() : void{
		$this->root = Path::join(sys_get_temp_dir(), "quark-worlds-" . uniqid());
		mkdir($this->root);
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("submitTask")->willReturnCallback(function(AsyncTask $task) : int{
			$this->tasks[] = $task;
			return 0;
		});
		$server = $this->createMock(Server::class);
		$server->method("getAsyncPool")->willReturn($pool);
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
		$this->manager->copyWorld("template", "arena")->onCompletion(function() use (&$resolved) : void{ $resolved = true; }, fn() => self::fail("copy failed"));

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
		$this->manager->deleteWorld("doomed")->onCompletion(fn() => null, function() use (&$failed) : void{ $failed = true; });
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
		$this->manager->copyWorld("src", "dst")->onCompletion(fn() => self::fail("copy succeeded"), function() use (&$rejected) : void{ $rejected = true; });
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
