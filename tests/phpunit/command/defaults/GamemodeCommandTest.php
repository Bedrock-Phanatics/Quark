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
use quark\player\Player;

final class GamemodeCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, GameMode> */
	private array $modes = [];
	/** @phpstan-var list<string> */
	private array $changes = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$this->modes[$name] = GameMode::SURVIVAL;
		$player->method("getGamemode")->willReturnCallback(fn() : GameMode => $this->modes[$name]);
		$player->method("setGamemode")->willReturnCallback(function(GameMode $mode) use ($name) : bool{
			$this->modes[$name] = $mode;
			$this->changes[] = $name;
			return true;
		});
		return $player;
	}

	public function testChangesOwnGameMode() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Set own game mode to Creative Mode"], $this->dispatch("gamemode creative", $steve));
		self::assertSame(GameMode::CREATIVE, $this->modes["Steve"]);

		foreach(["survival" => GameMode::SURVIVAL, "c" => GameMode::CREATIVE, "a" => GameMode::ADVENTURE, "v" => GameMode::SPECTATOR, "view" => GameMode::SPECTATOR, "0" => GameMode::SURVIVAL, "1" => GameMode::CREATIVE, "2" => GameMode::ADVENTURE, "3" => GameMode::SPECTATOR, "s" => GameMode::SURVIVAL] as $word => $mode){
			$this->dispatch("gamemode $word", $steve);
			self::assertSame($mode, $this->modes["Steve"], (string) $word);
		}
	}

	public function testModeNamesAreCaseInsensitive() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("gamemode CrEaTiVe", $steve);
		self::assertSame(GameMode::CREATIVE, $this->modes["Steve"]);
	}

	public function testChangesOtherPlayersGameMode() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");
		self::assertSame(["Set Alex's game mode to Adventure Mode"], $this->dispatch("gamemode adventure Alex", $steve));
		self::assertSame(GameMode::ADVENTURE, $this->modes["Alex"]);
		self::assertSame(GameMode::SURVIVAL, $this->modes["Steve"]);
		self::assertSame(["Your game mode has been updated to Adventure Mode"], $this->sent["Alex"]);

		$this->dispatch("gamemode c Al", $this->console());
		self::assertSame(GameMode::CREATIVE, $this->modes["Alex"]);
	}

	public function testTargetingYourselfUsesSelfPermission() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_GAMEMODE_SELF]);
		self::assertSame(["Set own game mode to Creative Mode"], $this->dispatch("gamemode c Steve", $steve));
		self::assertSame(["Set own game mode to Adventure Mode"], $this->dispatch("gamemode a @s", $steve));
	}

	public function testReportsFailureWhenModeIsAlreadySet() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Game mode change for Steve failed!"], $this->dispatch("gamemode survival", $steve));
		self::assertSame([], $this->changes);
	}

	public function testRejectsUnknownModeAndMalformedInput() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		self::assertStringContainsString("Invalid value for <mode>", $this->dispatch("gamemode hardcore", $steve)[0]);
		foreach(["gamemode", "gamemode c Alex extra", "gamemode c Nobody", "gamemode 4", "gamemode -1", "gamemode c @a", "gamemode c @e"] as $line){
			$this->dispatch($line, $steve);
		}
		self::assertSame([], $this->changes);
	}

	public function testConsoleNeedsATarget() : void{
		$this->spawn("Alex");
		$console = $this->console();
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("gamemode c", $console));
		self::assertSame(["Invalid value for <target>: Cannot find a player matching \"@s\"", "Usage: /gamemode <mode> <target>"], $this->dispatch("gamemode c @s", $console));
		self::assertSame([], $this->changes);
	}

	public function testSelfPermissionDoesNotAllowChangingOthers() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_GAMEMODE_SELF]);
		$this->spawn("Alex");
		$this->dispatch("gamemode c Alex", $steve);
		self::assertSame([], $this->changes);
		$this->dispatch("gamemode c", $steve);
		self::assertSame(["Steve"], $this->changes);
	}

	public function testOtherPermissionDoesNotAllowChangingYourself() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_GAMEMODE_OTHER]);
		$this->spawn("Alex");
		$this->dispatch("gamemode c", $steve);
		$this->dispatch("gamemode c Steve", $steve);
		self::assertSame([], $this->changes);
		$this->dispatch("gamemode c Alex", $steve);
		self::assertSame(["Alex"], $this->changes);
	}

	public function testWithoutPermissionNothingChanges() : void{
		$steve = $this->spawn("Steve", false);
		$this->spawn("Alex");
		$this->dispatch("gamemode c", $steve);
		$this->dispatch("gamemode c Alex", $steve);
		self::assertSame([], $this->changes);
	}
}
