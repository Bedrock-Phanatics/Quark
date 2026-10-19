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

namespace quark\command;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use quark\command\defaults\WorldCommand;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\KnownTranslationKeys;
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\promise\PromiseResolver;
use quark\Server;
use quark\world\Position;
use quark\world\World;
use quark\world\WorldManager;
use function count;
use function gc_collect_cycles;
use function implode;
use function in_array;

class WorldCommandTest extends TestCase{

	protected function setUp() : void{
		DefaultPermissions::registerCorePermissions();
	}

	public function testCommandMetadata() : void{
		$command = new WorldCommand();
		self::assertSame("world", $command->getName());
		self::assertContains("worlds", $command->getAliases());
		self::assertSame([
			DefaultPermissionNames::COMMAND_WORLD,
			DefaultPermissionNames::COMMAND_WORLD_LIST,
			DefaultPermissionNames::COMMAND_WORLD_INFO,
			DefaultPermissionNames::COMMAND_WORLD_CREATE,
			DefaultPermissionNames::COMMAND_WORLD_LOAD,
			DefaultPermissionNames::COMMAND_WORLD_UNLOAD,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER
		], $command->getPermissions());
	}

	public function testPermissionsRegisteredAndAssignedToOperator() : void{
		$manager = PermissionManager::getInstance();
		$operator = $manager->getPermission(DefaultPermissions::ROOT_OPERATOR);
		self::assertNotNull($operator);
		$operatorChildren = $operator->getChildren();

		$permissions = [
			DefaultPermissionNames::COMMAND_WORLD,
			DefaultPermissionNames::COMMAND_WORLD_LIST,
			DefaultPermissionNames::COMMAND_WORLD_INFO,
			DefaultPermissionNames::COMMAND_WORLD_CREATE,
			DefaultPermissionNames::COMMAND_WORLD_LOAD,
			DefaultPermissionNames::COMMAND_WORLD_UNLOAD,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER
		];

		foreach($permissions as $permName){
			self::assertNotNull($manager->getPermission($permName), "Permission $permName not registered in PermissionManager");
			self::assertArrayHasKey($permName, $operatorChildren, "Permission $permName not assigned to operator root");
			self::assertTrue($operatorChildren[$permName]);
		}
	}

	/**
	 * @param string[]                      $grantedPermissions
	 * @param ArrayObject<int, string>|null $messages
	 */
	private function createMockSender(?Server $server = null, array $grantedPermissions = [], ?ArrayObject $messages = null) : CommandSender{
		$sender = self::createMock(CommandSender::class);
		$sender->method("getServer")->willReturn($server ?? self::createStub(Server::class));
		$sender->method("getName")->willReturn("CONSOLE");
		$sender->method("hasPermission")->willReturnCallback(function(Permission|string $permission) use ($grantedPermissions) : bool{
			$name = $permission instanceof Permission ? $permission->getName() : $permission;
			return in_array($name, $grantedPermissions, true);
		});
		if($messages !== null){
			$sender->method("sendMessage")->willReturnCallback(function(Translatable|string $m) use ($messages) : void{
				$messages[] = $m instanceof Translatable ? $m->getText() : $m;
			});
		}
		return $sender;
	}

	/**
	 * @return Player&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function createMockPlayer(int $id, string $name, bool $connected = true, bool $closed = false) : Player{
		/** @var Player&\PHPUnit\Framework\MockObject\MockObject $player */
		$player = $this->createMock(Player::class);
		$player->method("getId")->willReturn($id);
		$player->method("getName")->willReturn($name);
		$player->method("isConnected")->willReturn($connected);
		$player->method("isClosed")->willReturn($closed);

		$refClosed = new \ReflectionProperty(\quark\entity\Entity::class, "closed");
		$refClosed->setValue($player, true);

		$refLogger = new \ReflectionProperty(Player::class, "logger");
		$refLogger->setValue($player, self::createStub(\AttachableLogger::class));

		return $player;
	}

	/** @return WorldManager&\PHPUnit\Framework\MockObject\MockObject */
	private function createWorldManagerMock() : WorldManager{
		return $this->createPartialMock(WorldManager::class, [
			"getDataPath", "getWorlds", "getWorldByName", "getDefaultWorld", "getGeneratedWorlds",
			"isWorldLoaded", "isWorldGenerated", "isWorldNameCollision", "loadWorld", "unloadWorld"
		]);
	}

	public function testEmptyArgsThrowsSyntaxException() : void{
		$command = new WorldCommand();
		$sender = $this->createMockSender(null, [DefaultPermissionNames::COMMAND_WORLD]);

		$this->expectException(InvalidCommandSyntaxException::class);
		$command->execute($sender, "world", []);
	}

	public function testUnknownSubcommandThrowsSyntaxException() : void{
		$command = new WorldCommand();
		$sender = $this->createMockSender(null, [DefaultPermissionNames::COMMAND_WORLD]);

		$this->expectException(InvalidCommandSyntaxException::class);
		$command->execute($sender, "world", ["unknown_sub"]);
	}

	public function testSubcommandsMissingRequiredArgsSendsUsageMessage() : void{
		$command = new WorldCommand();

		$expectedUsages = [
			"create" => KnownTranslationKeys::QUARK_COMMAND_WORLD_CREATE_USAGE,
			"load" => KnownTranslationKeys::QUARK_COMMAND_WORLD_LOAD_USAGE,
			"unload" => KnownTranslationKeys::QUARK_COMMAND_WORLD_UNLOAD_USAGE,
			"tp" => KnownTranslationKeys::QUARK_COMMAND_WORLD_TP_USAGE,
			"info" => KnownTranslationKeys::QUARK_COMMAND_WORLD_INFO_USAGE,
		];

		foreach($expectedUsages as $sub => $expectedKey){
			/** @var ArrayObject<int, Translatable|string> $messages */
			$messages = new ArrayObject();
			$sender = self::createMock(CommandSender::class);
			$sender->method("getServer")->willReturn(self::createStub(Server::class));
			$sender->method("getName")->willReturn("CONSOLE");
			$sender->method("hasPermission")->willReturn(true);
			$sender->method("sendMessage")->willReturnCallback(function(Translatable|string $m) use ($messages) : void{
				$messages[] = $m;
			});

			$result = $command->execute($sender, "world", [$sub]);
			self::assertTrue($result);
			self::assertCount(1, $messages);
			$msg = $messages[0];
			self::assertInstanceOf(Translatable::class, $msg);
			self::assertSame(KnownTranslationKeys::COMMANDS_GENERIC_USAGE, $msg->getText());
			$params = $msg->getParameters();
			self::assertCount(1, $params);
			$subUsage = $params[0];
			self::assertInstanceOf(Translatable::class, $subUsage);
			self::assertSame($expectedKey, $subUsage->getText());
		}
	}

	public function testListSubcommandOutputsWorlds() : void{
		$command = new WorldCommand();

		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$worldManager = $this->createWorldManagerMock();
		$mockWorld = self::createStub(World::class);
		$mockWorld->method("getFolderName")->willReturn("world");
		$mockWorld->method("getPlayers")->willReturn([]);
		$mockWorld->method("getLoadedChunks")->willReturn([]);

		$worldManager->method("getWorlds")->willReturn(["world" => $mockWorld]);
		$worldManager->method("getGeneratedWorlds")->willReturn(["world", "custom_world"]);
		$worldManager->method("isWorldLoaded")->willReturnCallback(fn(string $name) => $name === "world");

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = self::createMock(CommandSender::class);
		$sender->method("getServer")->willReturn($server);
		$sender->method("getName")->willReturn("CONSOLE");
		$sender->method("hasPermission")->willReturn(true);
		$sender->method("getScreenLineHeight")->willReturn(10);
		$sender->method("sendMessage")->willReturnCallback(function(Translatable|string $m) use ($messages) : void{
			$messages[] = $m instanceof Translatable ? $m->getText() : $m;
		});

		$result = $command->execute($sender, "world", ["list"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("custom_world", implode("\n", (array) $messages));
	}

	public function testCreateWithInvalidNameSendsError() : void{
		$command = new WorldCommand();

		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$worldManager = $this->createWorldManagerMock();
		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_CREATE], $messages);

		$result = $command->execute($sender, "world", ["create", "../invalid_name"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("Invalid world name", $messages[0] ?? "");
	}

	public function testUnloadDefaultWorldBlocked() : void{
		$command = new WorldCommand();

		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$mockDefaultWorld = self::createStub(World::class);
		$mockDefaultWorld->method("getFolderName")->willReturn("default_world");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getDefaultWorld")->willReturn($mockDefaultWorld);
		$worldManager->method("getWorldByName")->willReturn($mockDefaultWorld);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_UNLOAD], $messages);

		$result = $command->execute($sender, "world", ["unload", "default_world"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("The default world cannot be unloaded", $messages[0] ?? "");
	}

	public function testLoadAlreadyLoadedWorld() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$mockWorld = self::createStub(World::class);
		$mockWorld->method("getFolderName")->willReturn("lobby");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("lobby")->willReturn($mockWorld);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_LOAD], $messages);

		$result = $command->execute($sender, "world", ["load", "lobby"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("already loaded", $messages[0] ?? "");
	}

	public function testLoadNonexistentWorld() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("isWorldLoaded")->with("ghost")->willReturn(false);
		$worldManager->method("isWorldGenerated")->with("ghost")->willReturn(false);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_LOAD], $messages);

		$result = $command->execute($sender, "world", ["load", "ghost"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("does not exist", $messages[0] ?? "");
	}

	public function testLoadSuccess() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("isWorldLoaded")->with("minigame")->willReturn(false);
		$worldManager->method("isWorldGenerated")->with("minigame")->willReturn(true);
		$worldManager->expects(self::once())->method("loadWorld")->with("minigame")->willReturn(true);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_LOAD], $messages);

		$result = $command->execute($sender, "world", ["load", "minigame"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("loaded successfully", $messages[0] ?? "");
	}

	public function testCreateCollision() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("isWorldNameCollision")->with("existing_world")->willReturn(true);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_CREATE], $messages);

		$result = $command->execute($sender, "world", ["create", "existing_world"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("already exists", $messages[0] ?? "");
	}

	public function testCreateUnknownGenerator() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("isWorldNameCollision")->with("new_world")->willReturn(false);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_CREATE], $messages);

		$result = $command->execute($sender, "world", ["create", "new_world", "nonexistent_generator_type"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("Unknown generator", $messages[0] ?? "");
	}

	public function testUnloadUnloadedWorld() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("unloaded_world")->willReturn(null);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_UNLOAD], $messages);

		$result = $command->execute($sender, "world", ["unload", "unloaded_world"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("is not loaded", $messages[0] ?? "");
	}

	public function testUnloadSuccess() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$mockWorld = self::createStub(World::class);
		$mockWorld->method("getFolderName")->willReturn("secondary_world");

		$mockDefaultWorld = self::createStub(World::class);
		$mockDefaultWorld->method("getFolderName")->willReturn("main_world");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getDefaultWorld")->willReturn($mockDefaultWorld);
		$worldManager->method("getWorldByName")->with("secondary_world")->willReturn($mockWorld);
		$worldManager->expects(self::once())->method("unloadWorld")->with($mockWorld, false)->willReturn(true);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_UNLOAD], $messages);

		$result = $command->execute($sender, "world", ["unload", "secondary_world"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("unloaded successfully", $messages[0] ?? "");
	}

	public function testTeleportPopulatedChunkImmediateTeleport() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$mockWorld = self::createMock(World::class);
		$mockWorld->method("isLoaded")->willReturn(true);
		$mockWorld->method("getFolderName")->willReturn("minigame");
		$mockWorld->method("getDisplayName")->willReturn("minigame");
		$mockWorld->method("getSpawnLocation")->willReturn(new Position(100, 64, 100, $mockWorld));
		$mockWorld->method("getSafeSpawn")->willReturn(new Position(100, 64, 100, $mockWorld));
		$mockWorld->method("isChunkPopulated")->willReturn(true);
		$mockWorld->expects(self::never())->method("requestChunkPopulation");

		$player = $this->createMockPlayer(1, "Steve");
		$player->expects(self::once())->method("teleport")->willReturn(true);

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("minigame")->willReturn($mockWorld);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);
		$server->method("getPlayerByPrefix")->willReturn($player);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER], $messages);

		$result = $command->execute($sender, "world", ["tp", "minigame", "Steve"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("Teleported Steve to world 'minigame'", $messages[0] ?? "");
	}

	public function testSafeSpawnFailureNeverFallsBackToRawSpawn() : void{
		$world = self::createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getFolderName")->willReturn("arena");
		$world->method("getDisplayName")->willReturn("arena");
		$world->method("getSpawnLocation")->willReturn(new Position(0, 64, 0, $world));
		$world->method("isChunkPopulated")->willReturn(true);
		$world->method("getSafeSpawn")->willThrowException(new \quark\world\WorldException("terrain unavailable"));
		$player = $this->createMockPlayer(50, "Alex");
		$player->expects(self::never())->method("teleport");
		$manager = $this->createWorldManagerMock();
		$manager->method("getWorldByName")->willReturn($world);
		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($manager);
		$server->method("getPlayerByPrefix")->willReturn($player);
		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER]);
		(new WorldCommand())->execute($sender, "world", ["tp", "arena", "Alex"]);
	}

	public function testTeleportUnpopulatedChunkOrdersChunkPopulation() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		/** @var PromiseResolver<void> $resolver */
		$resolver = new PromiseResolver();

		$mockWorld = self::createMock(World::class);
		$mockWorld->method("isLoaded")->willReturn(true);
		$mockWorld->method("getFolderName")->willReturn("minigame");
		$mockWorld->method("getDisplayName")->willReturn("minigame");
		$mockWorld->method("getSpawnLocation")->willReturn(new Position(100, 64, 100, $mockWorld));
		$mockWorld->method("isChunkPopulated")->willReturn(false);
		$mockWorld->expects(self::once())->method("requestChunkPopulation")->willReturn($resolver->getPromise());

		$player = $this->createMockPlayer(2, "Alex");
		$player->expects(self::never())->method("teleport");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("minigame")->willReturn($mockWorld);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);
		$server->method("getPlayerByPrefix")->willReturn($player);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER], $messages);

		$result = $command->execute($sender, "world", ["tp", "minigame", "Alex"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("Generating spawn terrain", $messages[0] ?? "");
	}

	public function testTeleportDuplicateRequestBlockedWhilePending() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		/** @var PromiseResolver<void> $resolver */
		$resolver = new PromiseResolver();

		$mockWorld = self::createMock(World::class);
		$mockWorld->method("isLoaded")->willReturn(true);
		$mockWorld->method("getFolderName")->willReturn("minigame");
		$mockWorld->method("getDisplayName")->willReturn("minigame");
		$mockWorld->method("getSpawnLocation")->willReturn(new Position(100, 64, 100, $mockWorld));
		$mockWorld->method("isChunkPopulated")->willReturn(false);
		$mockWorld->expects(self::once())->method("requestChunkPopulation")->willReturn($resolver->getPromise());

		$player = $this->createMockPlayer(3, "Alex");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("minigame")->willReturn($mockWorld);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);
		$server->method("getPlayerByPrefix")->willReturn($player);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER], $messages);

		$command->execute($sender, "world", ["tp", "minigame", "Alex"]);
		self::assertStringContainsString("Generating spawn terrain", $messages[0] ?? "");

		$messages->exchangeArray([]);
		$command->execute($sender, "world", ["tp", "minigame", "Alex"]);
		self::assertStringContainsString("already in progress", $messages[0] ?? "");
	}

	public function testLoadCaseInsensitiveLoadedWorld() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$mockWorld = self::createStub(World::class);
		$mockWorld->method("getFolderName")->willReturn("MyWorld");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getWorldByName")->with("MyWorld")->willReturn($mockWorld);
		$worldManager->method("getWorlds")->willReturn(["MyWorld" => $mockWorld]);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_LOAD], $messages);

		$result = $command->execute($sender, "world", ["load", "myworld"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("already loaded", $messages[0] ?? "");
	}

	public function testUnloadCaseInsensitiveLoadedWorld() : void{
		$command = new WorldCommand();
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();

		$mockWorld = self::createStub(World::class);
		$mockWorld->method("getFolderName")->willReturn("MyWorld");

		$mockDefaultWorld = self::createStub(World::class);
		$mockDefaultWorld->method("getFolderName")->willReturn("main_world");

		$worldManager = $this->createWorldManagerMock();
		$worldManager->method("getDefaultWorld")->willReturn($mockDefaultWorld);
		$worldManager->method("getWorldByName")->with("MyWorld")->willReturn($mockWorld);
		$worldManager->method("getWorlds")->willReturn(["MyWorld" => $mockWorld]);
		$worldManager->expects(self::once())->method("unloadWorld")->with($mockWorld, false)->willReturn(true);

		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($worldManager);

		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD_UNLOAD], $messages);

		$result = $command->execute($sender, "world", ["unload", "MYWORLD"]);
		self::assertTrue($result);
		self::assertNotEmpty($messages);
		self::assertStringContainsString("unloaded successfully", $messages[0] ?? "");
	}
	/** @return \Generator<string, array{string, bool, bool, bool, bool, bool, int}> */
	public static function teleportCompletionCases() : \Generator{
		yield "success" => ["resolve", true, false, true, true, false, 1];
		yield "cancelled" => ["cancel", true, false, true, true, false, 1];
		yield "safe spawn failure" => ["resolve", true, false, true, true, true, 0];
		yield "disconnected" => ["resolve", false, false, true, true, false, 0];
		yield "closed" => ["resolve", true, true, true, true, false, 0];
		yield "world unloaded" => ["resolve", true, false, false, true, false, 0];
		yield "unpopulated result" => ["resolve", true, false, true, false, false, 0];
		yield "generation rejected" => ["reject", true, false, true, true, false, 0];
		yield "synchronous exception" => ["throw", true, false, true, true, false, 0];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("teleportCompletionCases")]
	public function testTeleportCompletionAndPendingCleanup(string $completion, bool $connected, bool $closed, bool $loaded, bool $populated, bool $safeSpawnFails, int $teleports) : void{
		/** @var PromiseResolver<void> $resolver */
		$resolver = new PromiseResolver();
		$world = self::createMock(World::class);
		$world->method("isLoaded")->willReturnOnConsecutiveCalls(true, $loaded);
		$world->method("getFolderName")->willReturn("arena");
		$world->method("getDisplayName")->willReturn("arena");
		$spawn = new Position(12, 64, 12, $world);
		$world->expects(self::once())->method("getSpawnLocation")->willReturn($spawn);
		$world->method("isChunkPopulated")->willReturnOnConsecutiveCalls(false, $populated);
		if($safeSpawnFails){
			$world->method("getSafeSpawn")->willThrowException(new \quark\world\WorldException("no safe spawn"));
		}else{
			$world->method("getSafeSpawn")->with(self::callback(static fn(\pocketmine\math\Vector3 $position) => $position->getFloorX() === 12 && $position->getFloorZ() === 12))->willReturn($spawn);
		}
		if($completion === "throw"){
			$world->method("requestChunkPopulation")->willThrowException(new \RuntimeException("generation unavailable"));
		}else{
			$world->expects(self::once())->method("requestChunkPopulation")->willReturn($resolver->getPromise());
		}
		$player = $this->createMockPlayer(100, "Alex", $connected, $closed);
		$player->expects(self::exactly($teleports))->method("teleport")->willReturn($completion !== "cancel");
		$manager = self::createMock(WorldManager::class);
		$manager->method("getWorldByName")->willReturn($world);
		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($manager);
		$server->method("getPlayerByPrefix")->willReturn($player);
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$sender = $this->createMockSender($server, [DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER], $messages);
		$command = new WorldCommand();
		$command->execute($sender, "world", ["tp", "arena", "Alex"]);
		if($completion === "reject"){
			$resolver->reject();
		}elseif($completion !== "throw"){
			$resolver->resolve(null);
		}
		if($completion === "cancel"){
			self::assertStringContainsString("cancelled", implode("\n", (array) $messages));
		}
		self::assertSame([], (new \ReflectionProperty(WorldCommand::class, "pendingTeleports"))->getValue($command));
	}

	public function testAmbiguousShorthandCannotOperateOnEitherLoadedWorld() : void{
		$upper = self::createStub(World::class);
		$upper->method("getFolderName")->willReturn("Arena");
		$lower = self::createStub(World::class);
		$lower->method("getFolderName")->willReturn("arena");
		$manager = $this->createWorldManagerMock();
		$manager->method("getWorlds")->willReturn([$upper, $lower]);
		$manager->expects(self::never())->method("getWorldByName");
		$manager->expects(self::never())->method("loadWorld");
		$manager->expects(self::never())->method("unloadWorld");
		$player = $this->createMockPlayer(101, "Alex");
		$player->expects(self::never())->method("teleport");
		$server = self::createStub(Server::class);
		$server->method("getWorldManager")->willReturn($manager);
		$server->method("getPlayerByPrefix")->willReturn($player);
		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$sender = $this->createMockSender($server, [
			DefaultPermissionNames::COMMAND_WORLD_INFO, DefaultPermissionNames::COMMAND_WORLD_LOAD,
			DefaultPermissionNames::COMMAND_WORLD_UNLOAD, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER
		], $messages);
		$command = new WorldCommand();
		foreach(["info", "load", "unload", "tp"] as $subcommand){
			$messages->exchangeArray([]);
			$command->execute($sender, "world", [$subcommand, "ARENA", "Alex"]);
			self::assertStringContainsString("Ambiguous world name", $messages[0] ?? "");
		}
	}

	public function testSubcommandsDoNotAccessWorldsWithoutPermission() : void{
		$server = self::createMock(Server::class);
		$server->expects(self::never())->method("getWorldManager");
		$player = $this->createMockPlayer(102, "Alex");
		$server->method("getPlayerByPrefix")->willReturn($player);
		$sender = $this->createMockSender($server);
		$command = new WorldCommand();
		foreach(["list", "info", "create", "load", "unload", "tp"] as $subcommand){
			self::assertTrue($command->execute($sender, "world", [$subcommand, "arena", "Alex"]));
		}
	}

	public function testPendingPopulationDoesNotRetainPlayersOrWorlds() : void{
		[$resolver, $playerRef, $worldRef] = (static function() : array{
			/** @var PromiseResolver<void> $resolver */
			$resolver = new PromiseResolver();
			$world = self::createStub(World::class);
			$world->method("isLoaded")->willReturn(true);
			$world->method("getFolderName")->willReturn("arena");
			$world->method("getDisplayName")->willReturn("arena");
			$world->method("getSpawnLocation")->willReturn(new Position(0, 64, 0, $world));
			$world->method("isChunkPopulated")->willReturn(false);
			$world->method("requestChunkPopulation")->willReturn($resolver->getPromise());
			$world->method("orderChunkPopulation")->willReturn($resolver->getPromise());
			$player = self::createStub(Player::class);
			$player->method("getId")->willReturn(103);
			$player->method("getName")->willReturn("Alex");
			(new \ReflectionProperty(\quark\entity\Entity::class, "closed"))->setValue($player, true);
			(new \ReflectionProperty(Player::class, "logger"))->setValue($player, self::createStub(\AttachableLogger::class));
			$manager = self::createStub(WorldManager::class);
			$manager->method("getWorldByName")->willReturn($world);
			$server = self::createStub(Server::class);
			$server->method("getWorldManager")->willReturn($manager);
			$server->method("getPlayerByPrefix")->willReturn($player);
			$sender = self::createStub(CommandSender::class);
			$sender->method("hasPermission")->willReturn(true);
			$sender->method("getServer")->willReturn($server);
			(new WorldCommand())->execute($sender, "world", ["tp", "arena", "Alex"]);
			return [$resolver, \WeakReference::create($player), \WeakReference::create($world)];
		})();
		gc_collect_cycles();
		self::assertNull($playerRef->get());
		self::assertNull($worldRef->get());
		$resolver->reject();
	}

}
