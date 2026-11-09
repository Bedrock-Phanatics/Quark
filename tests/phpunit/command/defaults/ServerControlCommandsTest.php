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

namespace quark\command\defaults;

use quark\FilesystemPaths;
use quark\MemoryManager;
use quark\permission\DefaultPermissionNames;
use quark\world\World;
use quark\world\WorldManager;
use Vow\Deferred;
use function explode;
use function str_contains;
use function str_starts_with;

final class ServerControlCommandsTest extends DefaultCommandTestCase{
	private int $shutdowns = 0;
	/** @phpstan-var list<bool> */
	private array $autoSave = [];
	/** @phpstan-var list<string> */
	private array $dumps = [];
	private int $collections = 0;
	/** @phpstan-var list<string> */
	private array $worldCalls = [];
	/** @phpstan-var list<Deferred<null>> */
	private array $playerSaves = [];

	protected function setUp() : void{
		parent::setUp();
		$this->server->method("shutdown")->willReturnCallback(function() : void{
			++$this->shutdowns;
		});

		$world = self::createStub(World::class);
		$world->method("save")->willReturnCallback(function(bool $force = false) : bool{
			$this->worldCalls[] = "save:" . ($force ? "force" : "normal");
			return true;
		});
		$world->method("doChunkGarbageCollection")->willReturnCallback(function() : void{
			$this->worldCalls[] = "gc";
		});
		$world->method("unloadChunks")->willReturnCallback(function() : void{
			$this->worldCalls[] = "unload";
		});
		$world->method("clearCache")->willReturnCallback(function() : void{
			$this->worldCalls[] = "cache";
		});
		$worlds = self::createStub(WorldManager::class);
		$worlds->method("getWorlds")->willReturn([$world]);
		$worlds->method("setAutoSave")->willReturnCallback(function(bool $value) : void{
			$this->autoSave[] = $value;
		});
		$this->server->method("getWorldManager")->willReturn($worlds);

		$memory = self::createStub(MemoryManager::class);
		$memory->method("triggerGarbageCollector")->willReturnCallback(function() : int{
			return ++$this->collections * 10;
		});
		$memory->method("dumpServerMemory")->willReturnCallback(function(string $folder) : void{
			$this->dumps[] = $folder;
		});
		$this->server->method("getMemoryManager")->willReturn($memory);
	}

	public function testStopShutsDownOnce() : void{
		self::assertSame(["Stopping the server"], $this->dispatch("stop", $this->console()));
		self::assertSame(1, $this->shutdowns);
	}

	public function testSaveAllReportsAfterEveryPlayerSaved() : void{
		foreach(["Steve", "Alex"] as $name){
			$player = $this->player($name);
			$deferred = new Deferred();
			$this->playerSaves[] = $deferred;
			$player->method("save")->willReturn($deferred->promise);
		}
		$console = $this->console();

		self::assertSame(["Saving server data..."], $this->dispatch("save-all", $console));
		self::assertSame(["save:force"], $this->worldCalls);

		$this->playerSaves[0]->resolve(null);
		self::assertSame(["Saving server data..."], $console->messages);
		$this->playerSaves[1]->resolve(null);
		self::assertCount(2, $console->messages);
		self::assertMatchesRegularExpression('/^Save completed in [0-9.e-]+ seconds$/', $console->messages[1]);
	}

	public function testSaveAllWithoutPlayersCompletesImmediately() : void{
		$messages = $this->dispatch("save-all", $this->console());
		self::assertCount(2, $messages);
		self::assertSame("Saving server data...", $messages[0]);
	}

	public function testAutoSaveToggles() : void{
		$console = $this->console();
		self::assertSame(["Turned off world auto-saving"], $this->dispatch("save-off", $console));
		self::assertSame(["Turned on world auto-saving"], $this->dispatch("save-on", $console));
		self::assertSame([false, true], $this->autoSave);
	}

	public function testGarbageCollectionReportsAndCleansEveryWorld() : void{
		$messages = $this->dispatch("gc", $this->console());
		self::assertSame(["gc", "unload", "cache"], $this->worldCalls);
		self::assertSame(1, $this->collections);
		self::assertCount(5, $messages);
		self::assertStringContainsString("Garbage collection result", $messages[0]);
		self::assertSame("Cycles: 10", $messages[3]);
	}

	public function testDumpMemoryUsesGivenPathOrADefaultFolder() : void{
		$console = $this->console();
		$this->server->method("getFilesystemPaths")->willReturn(new FilesystemPaths("/srv/quark", "/srv/quark/plugins"));

		self::assertSame([], $this->dispatch("dumpmemory /tmp/dump", $console));
		self::assertSame([], $this->dispatch("dumpmemory", $console));
		self::assertSame("/tmp/dump", $this->dumps[0]);
		self::assertTrue(str_starts_with($this->dumps[1], "/srv/quark/") && str_contains($this->dumps[1], "memory_dumps"), $this->dumps[1]);
	}

	public function testDumpMemoryRejectsExtraArguments() : void{
		self::assertSame(["Usage: /dumpmemory [path]"], $this->dispatch("dumpmemory a b", $this->console()));
		self::assertSame([], $this->dumps);
	}

	public function testNoArgumentCommandsRejectTrailingInputWithoutRunning() : void{
		$console = $this->console();
		foreach(["stop now", "save-all now", "save-on x", "save-off x", "gc all"] as $line){
			[$name] = explode(" ", $line);
			self::assertSame(["Usage: /$name"], $this->dispatch($line, $console), $line);
		}
		self::assertSame(0, $this->shutdowns);
		self::assertSame([], $this->autoSave);
		self::assertSame([], $this->worldCalls);
		self::assertSame(0, $this->collections);
	}

	public function testCommandsRequirePermission() : void{
		$denied = $this->player("Denied", []);
		foreach(["stop", "save-all", "save-on", "save-off", "gc", "dumpmemory"] as $line){
			self::assertStringContainsString("permission", $this->dispatch($line, $denied)[0], $line);
		}
		self::assertSame(0, $this->shutdowns);
		self::assertSame([], $this->autoSave);
		self::assertSame([], $this->dumps);

		$admin = $this->player("Admin", [DefaultPermissionNames::COMMAND_STOP]);
		$this->dispatch("stop", $admin);
		self::assertSame(1, $this->shutdowns);
	}
}
