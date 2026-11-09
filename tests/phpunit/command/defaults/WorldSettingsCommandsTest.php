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

use quark\permission\DefaultPermissionNames;
use quark\player\GameMode;
use quark\ServerConfigGroup;
use quark\ServerProperties;
use quark\utils\Config;
use quark\world\World;
use quark\world\WorldManager;
use function bin2hex;
use function file_exists;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

final class WorldSettingsCommandsTest extends DefaultCommandTestCase{
	private string $path;
	private ServerConfigGroup $config;
	private bool $hardcore = false;
	/** @phpstan-var list<int> */
	private array $worldDifficulties = [];

	protected function setUp() : void{
		parent::setUp();
		$this->path = sys_get_temp_dir() . "/quark-settings-" . bin2hex(random_bytes(6)) . ".properties";
		$file = new Config($this->path, Config::PROPERTIES);
		$this->config = new ServerConfigGroup($file, $file);
		$this->server->method("getConfigGroup")->willReturn($this->config);
		$this->server->method("isHardcore")->willReturnCallback(fn() : bool => $this->hardcore);

		$world = self::createStub(World::class);
		$world->method("setDifficulty")->willReturnCallback(function(int $difficulty) : void{
			$this->worldDifficulties[] = $difficulty;
		});
		$worlds = self::createStub(WorldManager::class);
		$worlds->method("getWorlds")->willReturn([$world]);
		$this->server->method("getWorldManager")->willReturn($worlds);
	}

	protected function tearDown() : void{
		if(file_exists($this->path)){
			unlink($this->path);
		}
		parent::tearDown();
	}

	public function testDifficultyAcceptsNamesLettersAndNumbers() : void{
		$console = $this->console();
		$expected = [
			"peaceful" => 0, "P" => 0, "0" => 0,
			"easy" => 1, "e" => 1, "1" => 1,
			"Normal" => 2, "n" => 2, "2" => 2,
			"HARD" => 3, "h" => 3, "3" => 3
		];
		$applied = 0;
		foreach($expected as $input => $difficulty){
			$input = (string) $input;
			self::assertSame(["Set game difficulty to $difficulty"], $this->dispatch("difficulty $input", $console), $input);
			self::assertSame($difficulty, $this->config->getConfigInt(ServerProperties::DIFFICULTY, -1), $input);
			self::assertSame($difficulty, $this->worldDifficulties[$applied++], $input);
		}
		self::assertCount(12, $this->worldDifficulties);
	}

	public function testHardcoreForcesHardDifficulty() : void{
		$this->hardcore = true;
		self::assertSame(["Set game difficulty to 3"], $this->dispatch("difficulty peaceful", $this->console()));
		self::assertSame([3], $this->worldDifficulties);
	}

	public function testInvalidDifficultyChangesNothing() : void{
		$this->hardcore = true;
		$console = $this->console();
		foreach(["difficulty 4", "difficulty -1", "difficulty impossible", "difficulty 1.5", "difficulty easy hard", "difficulty easy 1"] as $line){
			$messages = $this->dispatch($line, $console);
			self::assertNotSame([], $messages, $line);
			self::assertStringNotContainsString("Set game difficulty", $messages[0], $line);
		}
		self::assertSame(["Usage: /difficulty <difficulty>"], $this->dispatch("difficulty", $console));
		self::assertStringContainsString("\"impossible\" is not valid", $this->dispatch("difficulty impossible", $console)[0]);
		self::assertSame([], $this->worldDifficulties);
		self::assertSame(-1, $this->config->getConfigInt(ServerProperties::DIFFICULTY, -1));
	}

	public function testDefaultGamemodeAcceptsEveryAlias() : void{
		$console = $this->console();
		$expected = [
			"survival" => ["SURVIVAL", "Survival Mode"], "s" => ["SURVIVAL", "Survival Mode"], "0" => ["SURVIVAL", "Survival Mode"],
			"Creative" => ["CREATIVE", "Creative Mode"], "c" => ["CREATIVE", "Creative Mode"], "1" => ["CREATIVE", "Creative Mode"],
			"adventure" => ["ADVENTURE", "Adventure Mode"], "A" => ["ADVENTURE", "Adventure Mode"], "2" => ["ADVENTURE", "Adventure Mode"],
			"SPECTATOR" => ["SPECTATOR", "Spectator Mode"], "v" => ["SPECTATOR", "Spectator Mode"], "view" => ["SPECTATOR", "Spectator Mode"], "3" => ["SPECTATOR", "Spectator Mode"]
		];
		foreach($expected as $input => [$name, $label]){
			$input = (string) $input;
			self::assertSame(["The world's default game mode is now $label"], $this->dispatch("defaultgamemode $input", $console), $input);
			self::assertSame($name, $this->config->getConfigString(ServerProperties::GAME_MODE), $input);
		}
		self::assertSame(GameMode::SPECTATOR, GameMode::fromString($this->config->getConfigString(ServerProperties::GAME_MODE)));
	}

	public function testInvalidDefaultGamemodeChangesNothing() : void{
		$console = $this->console();
		foreach(["defaultgamemode 4", "defaultgamemode -1", "defaultgamemode godmode", "defaultgamemode creative survival", "defaultgamemode creative 1"] as $line){
			$messages = $this->dispatch($line, $console);
			self::assertNotSame([], $messages, $line);
			self::assertStringNotContainsString("default game mode is now", $messages[0], $line);
		}
		self::assertSame(["Usage: /defaultgamemode <gamemode>"], $this->dispatch("defaultgamemode", $console));
		self::assertStringContainsString("\"godmode\" is not valid", $this->dispatch("defaultgamemode godmode", $console)[0]);
		self::assertSame("", $this->config->getConfigString(ServerProperties::GAME_MODE));
	}

	public function testCommandsRequirePermission() : void{
		$denied = $this->player("Denied", []);
		self::assertStringContainsString("permission", $this->dispatch("difficulty hard", $denied)[0]);
		self::assertStringContainsString("permission", $this->dispatch("defaultgamemode creative", $denied)[0]);
		self::assertSame([], $this->worldDifficulties);
		self::assertSame("", $this->config->getConfigString(ServerProperties::GAME_MODE));

		$admin = $this->player("Admin", [DefaultPermissionNames::COMMAND_DIFFICULTY]);
		self::assertSame(["Set game difficulty to 3"], $this->dispatch("difficulty hard", $admin));
		self::assertStringContainsString("permission", $this->dispatch("defaultgamemode creative", $admin)[0]);
	}
}
