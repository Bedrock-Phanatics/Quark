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
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\Server;
use quark\world\World;
use quark\world\WorldManager;
use function count;
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
	 * @param string[] $grantedPermissions
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

	public function testSubcommandsMissingRequiredArgsThrowSyntaxException() : void{
		$command = new WorldCommand();
		$allPerms = [
			DefaultPermissionNames::COMMAND_WORLD,
			DefaultPermissionNames::COMMAND_WORLD_CREATE,
			DefaultPermissionNames::COMMAND_WORLD_LOAD,
			DefaultPermissionNames::COMMAND_WORLD_UNLOAD,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER,
			DefaultPermissionNames::COMMAND_WORLD_INFO,
		];
		$sender = $this->createMockSender(null, $allPerms);

		$caught = 0;
		$subcommands = ["create", "load", "unload", "tp", "info"];
		foreach($subcommands as $sub){
			try{
				$command->execute($sender, "world", [$sub]);
				self::fail("Expected InvalidCommandSyntaxException for sub: $sub");
			}catch(InvalidCommandSyntaxException){
				$caught++;
			}
		}
		self::assertSame(count($subcommands), $caught);
	}

	public function testListSubcommandOutputsWorlds() : void{
		$command = new WorldCommand();

		/** @var ArrayObject<int, string> $messages */
		$messages = new ArrayObject();
		$worldManager = self::createMock(WorldManager::class);
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
		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
		$worldManager->method("isWorldLoaded")->with("lobby")->willReturn(true);

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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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

		$worldManager = self::createMock(WorldManager::class);
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
}
