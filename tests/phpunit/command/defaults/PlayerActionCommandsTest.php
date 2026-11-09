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

use quark\event\entity\EntityDamageEvent;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

final class PlayerActionCommandsTest extends DefaultCommandTestCase{
	/** @phpstan-var list<string> */
	private array $killed = [];
	/** @phpstan-var list<array{string, string, int}> */
	private array $transfers = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function living(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("getHealth")->willReturn(20.0);
		$player->method("attack")->willReturnCallback(function(EntityDamageEvent $event) use ($name) : void{
			if($event->getCause() === EntityDamageEvent::CAUSE_SUICIDE && $event->getBaseDamage() === 20.0){
				$this->killed[] = $name;
			}
		});
		$player->method("transfer")->willReturnCallback(function(string $address, int $port = 19132) use ($name) : bool{
			$this->transfers[] = [$name, $address, $port];
			return true;
		});
		return $player;
	}

	public function testKillSelfAndOthers() : void{
		$steve = $this->living("Steve");
		$this->living("Alex");

		self::assertSame(["Killed Steve"], $this->dispatch("kill", $steve));
		self::assertSame(["Killed Steve"], $this->dispatch("suicide", $steve));
		self::assertSame(["Killed Steve"], $this->dispatch("kill @s", $steve));
		self::assertSame(["Killed Steve"], $this->dispatch("kill steve", $steve));
		self::assertSame(["Killed Alex"], $this->dispatch("kill ale", $steve));
		self::assertSame(["Steve", "Steve", "Steve", "Steve", "Alex"], $this->killed);
	}

	public function testConsoleMustNameAPlayer() : void{
		$this->living("Steve");
		$console = $this->console();

		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("kill", $console));
		self::assertSame([], $this->killed);
		self::assertSame(["Killed Steve"], $this->dispatch("kill Steve", $console));
		self::assertSame(["Steve"], $this->killed);
		self::assertStringContainsString("Cannot find a player matching \"@s\"", $this->dispatch("kill @s", $console)[0]);
	}

	public function testKillRejectsUnknownTargetsAndTrailingArguments() : void{
		$steve = $this->living("Steve");
		$this->living("Alex");

		self::assertStringContainsString("Cannot find a player matching \"Nobody\"", $this->dispatch("kill Nobody", $steve)[0]);
		self::assertStringContainsString("not supported", $this->dispatch("kill @a", $steve)[0]);
		self::assertSame(["Usage: /kill <target>", "Usage: /kill"], $this->dispatch("kill Alex Steve", $steve));
		self::assertSame([], $this->killed);
	}

	public function testKillSelfAndOtherPermissionsAreSeparate() : void{
		$selfOnly = $this->living("SelfOnly", [DefaultPermissionNames::COMMAND_KILL_SELF]);
		$otherOnly = $this->living("OtherOnly", [DefaultPermissionNames::COMMAND_KILL_OTHER]);
		$this->living("Victim");

		self::assertSame(["Killed SelfOnly"], $this->dispatch("kill", $selfOnly));
		self::assertSame(["Killed SelfOnly"], $this->dispatch("kill SelfOnly", $selfOnly));
		self::assertStringContainsString("permission", $this->dispatch("kill Victim", $selfOnly)[0]);
		self::assertSame(["SelfOnly", "SelfOnly"], $this->killed);

		self::assertStringContainsString("permission", $this->dispatch("kill", $otherOnly)[0]);
		self::assertStringContainsString("permission", $this->dispatch("kill OtherOnly", $otherOnly)[0]);
		self::assertStringContainsString("permission", $this->dispatch("kill @s", $otherOnly)[0]);
		self::assertSame(["Killed Victim"], $this->dispatch("kill Victim", $otherOnly));
		self::assertSame(["SelfOnly", "SelfOnly", "Victim"], $this->killed);

		$nothing = $this->living("Nothing", []);
		self::assertStringContainsString("permission", $this->dispatch("kill", $nothing)[0]);
		self::assertStringContainsString("permission", $this->dispatch("kill Victim", $nothing)[0]);
		self::assertSame(["SelfOnly", "SelfOnly", "Victim"], $this->killed);
	}

	public function testTransferUsesDefaultPort() : void{
		$steve = $this->living("Steve");
		self::assertSame([], $this->dispatch("transferserver play.example.com", $steve));
		self::assertSame([["Steve", "play.example.com", 19132]], $this->transfers);
	}

	public function testTransferAcceptsPortBoundaries() : void{
		$steve = $this->living("Steve");
		$this->dispatch("transferserver host 0", $steve);
		$this->dispatch("transferserver host 65535", $steve);
		$this->dispatch("transferserver host 25565", $steve);
		self::assertSame([["Steve", "host", 0], ["Steve", "host", 65535], ["Steve", "host", 25565]], $this->transfers);
	}

	public function testTransferRejectsBadInputWithoutTransferring() : void{
		$steve = $this->living("Steve");

		foreach(["transferserver host 65536", "transferserver host -1", "transferserver host abc", "transferserver host 1.5", "transferserver host 99999999999999999999"] as $line){
			self::assertStringContainsString("Invalid value for <port>", $this->dispatch($line, $steve)[0], $line);
		}
		self::assertSame(["Usage: /transferserver <address> [port]"], $this->dispatch("transferserver", $steve));
		self::assertSame(["Usage: /transferserver <address> [port]"], $this->dispatch("transferserver host 1 2", $steve));
		self::assertSame([], $this->transfers);
	}

	public function testTransferIsPlayerOnlyAndNeedsPermission() : void{
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("transferserver host", $this->console()));

		$denied = $this->living("Denied", []);
		self::assertStringContainsString("permission", $this->dispatch("transferserver host", $denied)[0]);
		self::assertSame([], $this->transfers);
	}
}
