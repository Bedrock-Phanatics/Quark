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
use quark\Server;
use quark\utils\Filesystem;
use quark\world\format\io\WorldProviderManager;
use function is_dir;
use function mkdir;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function uniqid;
use const PHP_OS_FAMILY;

class WorldManagerCollisionTest extends TestCase{

	private string $testPath;

	protected function setUp() : void{
		$this->testPath = sys_get_temp_dir() . "/quark_test_wm_" . uniqid();
		mkdir($this->testPath, 0777, true);
	}

	protected function tearDown() : void{
		Filesystem::recursiveUnlink($this->testPath);
	}

	public function testIncompleteDirectoryDetectedAsCollision() : void{
		mkdir($this->testPath . "/Aventura/db", 0777, true);

		$server = self::createStub(Server::class);
		$providerManager = new WorldProviderManager();
		$worldManager = new WorldManager($server, $this->testPath, $providerManager);

		self::assertTrue($worldManager->isWorldNameCollision("Aventura"));
		self::assertTrue($worldManager->isWorldNameCollision("aventura"));
		self::assertTrue($worldManager->isWorldNameCollision("AVENTURA"));
		self::assertFalse($worldManager->isWorldNameCollision("OtherWorld"));
	}

	public function testGenerationDoesNotReuseAnIncompleteDirectory() : void{
		mkdir($this->testPath . "/Arena");
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, new WorldProviderManager());
		self::assertFalse($manager->generateWorld("arena", WorldCreationOptions::create(), false));
		self::assertFileDoesNotExist($this->testPath . "/Arena/level.dat");
	}

	public function testExactNamesWinAndAmbiguousShorthandIsRejected() : void{
		mkdir($this->testPath . "/Arena");
		if(is_dir($this->testPath . "/arena")){
			self::markTestSkipped("requires a case-sensitive filesystem");
		}
		mkdir($this->testPath . "/arena");
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, new WorldProviderManager());
		self::assertSame("Arena", $manager->resolveWorldName("Arena"));
		self::assertSame("arena", $manager->resolveWorldName("arena"));
		$this->expectException(\InvalidArgumentException::class);
		$manager->resolveWorldName("ARENA");
	}

	public function testDiscoveryDoesNotInvokeProviderChecks() : void{
		mkdir($this->testPath . "/123");
		touch($this->testPath . "/123/level.dat");
		$providers = new WorldProviderManager();
		$providers->addProvider(new format\io\ReadOnlyWorldProviderManagerEntry(
			static function(string $path) : bool{
				self::fail("listing must not invoke provider checks");
			},
			static function(string $path, \Logger $logger) : format\io\WorldProvider{
				self::fail("listing must not open a provider");
			}
		), "probe");
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, $providers);
		self::assertSame(["123"], $manager->getGeneratedWorlds());
	}

	public function testDiscoveryListsMetadataCandidatesWithoutValidatingFormats() : void{
		mkdir($this->testPath . "/CorruptWorld", 0777, true);
		touch($this->testPath . "/CorruptWorld/level.dat");

		mkdir($this->testPath . "/ValidWorld/db", 0777, true);
		touch($this->testPath . "/ValidWorld/level.dat");

		$server = self::createStub(Server::class);
		$providerManager = new WorldProviderManager();
		$worldManager = new WorldManager($server, $this->testPath, $providerManager);

		$generated = $worldManager->getGeneratedWorlds();
		self::assertContains("CorruptWorld", $generated);
		self::assertContains("ValidWorld", $generated, "Valid world directory must be listed as generated");
	}

	/** @return \Generator<string, array{bool, bool, bool, bool}> */
	public static function unloadCases() : \Generator{
		yield "cancelled evacuation" => [false, false, false, false];
		yield "unavailable safe spawn" => [false, true, false, false];
		yield "cancelled unload event" => [false, false, true, false];
		yield "forced cancelled evacuation" => [true, false, false, true];
		yield "forced unavailable spawn" => [true, true, false, true];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("unloadCases")]
	public function testUnloadPreservesPlayersAndRespectsCancellation(bool $force, bool $spawnFails, bool $cancelEvent, bool $expected) : void{
		$world = self::createMock(World::class);
		(new \ReflectionProperty(World::class, "worldId"))->setValue($world, 10);
		$world->method("getDisplayName")->willReturn("arena");
		$world->expects(self::exactly($expected ? 1 : 0))->method("onUnload");
		$destination = self::createStub(World::class);
		$destination->method("isLoaded")->willReturn(true);
		$destination->method("getFolderName")->willReturn("lobby");
		if($spawnFails){
			$destination->method("getSafeSpawn")->willThrowException(new WorldException("no terrain"));
		}else{
			$destination->method("getSafeSpawn")->willReturn(new Position(0, 64, 0, $destination));
		}
		$player = self::createMock(\quark\player\Player::class);
		(new \ReflectionProperty(\quark\entity\Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(\quark\player\Player::class, "logger"))->setValue($player, self::createStub(\AttachableLogger::class));
		$player->method("getWorld")->willReturn($world);
		$player->method("teleport")->willReturn(false);
		$player->expects(self::exactly($force ? 1 : 0))->method("disconnect");
		$world->method("getPlayers")->willReturn([$player]);
		$server = self::createStub(Server::class);
		$server->method("getLogger")->willReturn(new \quark\utils\MainLogger(null, false, "test", new \DateTimeZone("UTC")));
		$server->method("getLanguage")->willReturn(new \quark\lang\Language("eng"));
		$manager = new WorldManager($server, $this->testPath, new WorldProviderManager());
		(new \ReflectionProperty(WorldManager::class, "worlds"))->setValue($manager, [10 => $world, 11 => $destination]);
		$manager->setDefaultWorld($destination);
		$list = \quark\event\HandlerListManager::global()->getListFor(\quark\event\world\WorldUnloadEvent::class);
		$listener = new \quark\event\RegisteredListener(
			static function(\quark\event\world\WorldUnloadEvent $event) use ($cancelEvent) : void{
				if($cancelEvent){ $event->cancel(); }
			},
			\quark\event\EventPriority::NORMAL,
			self::createStub(\quark\plugin\Plugin::class),
			false,
			\quark\pulse\Pulse::zone("test.world.unload")
		);
		$list->register($listener);
		try{
			self::assertSame($expected, $manager->unloadWorld($world, $force));
			self::assertSame($expected ? null : $world, $manager->getWorld(10));
		}finally{
			$list->unregister($listener);
		}
	}

	public function testExistingNamesArePreservedAndLookupRemainsExact() : void{
		mkdir($this->testPath . "/Mundo ÃƒÂ±");
		$world = self::createStub(World::class);
		$world->method("getFolderName")->willReturn("Arena");
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, new WorldProviderManager());
		(new \ReflectionProperty(WorldManager::class, "worlds"))->setValue($manager, [1 => $world]);
		self::assertSame("Mundo ÃƒÂ±", $manager->resolveWorldName("Mundo ÃƒÂ±"));
		self::assertSame("Arena", $manager->resolveWorldName("ARENA"));
		self::assertSame($world, $manager->getWorldByName("Arena"));
		self::assertNull($manager->getWorldByName("arena"));
	}

	public function testFilesAndDanglingSymlinksBlockCreation() : void{
		touch($this->testPath . "/Occupied");
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, new WorldProviderManager());
		self::assertTrue($manager->isWorldNameCollision("occupied"));
		self::assertFalse($manager->generateWorld("Occupied", WorldCreationOptions::create(), false));
		if(PHP_OS_FAMILY !== "Windows"){
			symlink($this->testPath . "/missing", $this->testPath . "/Link");
			self::assertTrue($manager->isWorldNameCollision("link"));
			self::assertFalse($manager->generateWorld("Link", WorldCreationOptions::create(), false));
		}
	}

	/** @return \Generator<string, array{string}> */
	public static function invalidWorldPaths() : \Generator{
		yield "traversal" => ["../outside"];
		yield "absolute" => ["/outside"];
		yield "drive relative" => ["C:escape"];
		yield "parent" => [".."];
		yield "windows separator" => ["arena\\child"];
		yield "nul byte" => ["nul\0byte"];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("invalidWorldPaths")]
	public function testResolutionRejectsPathsAndDrivePrefixes(string $name) : void{
		$manager = new WorldManager(self::createStub(Server::class), $this->testPath, new WorldProviderManager());
		$this->expectException(\InvalidArgumentException::class);
		$manager->resolveWorldName($name);
	}

}
