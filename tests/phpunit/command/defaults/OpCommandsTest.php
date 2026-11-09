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
use function str_repeat;

final class OpCommandsTest extends DefaultCommandTestCase{
	/** @phpstan-var list<string> */
	private array $ops = [];

	protected function setUp() : void{
		parent::setUp();
		$this->server->method("addOp")->willReturnCallback(function(string $name) : void{
			$this->ops[] = "+$name";
		});
		$this->server->method("removeOp")->willReturnCallback(function(string $name) : void{
			$this->ops[] = "-$name";
		});
	}

	public function testOpAndDeopNotifyTheTargetWhenOnline() : void{
		$steve = $this->player("Steve");
		$console = $this->console();

		self::assertSame(["Opped Steve"], $this->dispatch("op Steve", $console));
		self::assertSame(["You have been opped"], $this->sent["Steve"]);
		self::assertSame(["De-opped Steve"], $this->dispatch("deop Steve", $console));
		self::assertSame(["You have been opped", "You have been de-opped"], $this->sent["Steve"]);
		self::assertSame(["+Steve", "-Steve"], $this->ops);

		self::assertSame(["Opped Ghost"], $this->dispatch("op Ghost", $steve));
		self::assertSame(["De-opped Ghost"], $this->dispatch("deop Ghost", $steve));
		self::assertSame(["+Steve", "-Steve", "+Ghost", "-Ghost"], $this->ops);
	}

	public function testNamesAreValidatedBeforeAnyChange() : void{
		$console = $this->console();

		foreach(["op", "deop"] as $command){
			self::assertSame(["Usage: /$command <player>"], $this->dispatch($command, $console));
			self::assertSame(["Usage: /$command <player>"], $this->dispatch("$command Steve Alex", $console));
			foreach(["bad!name", "rcon", "Console", str_repeat("a", 17), "<script>"] as $name){
				self::assertStringContainsString("Invalid value for <player>", $this->dispatch("$command $name", $console)[0], "$command $name");
			}
		}
		self::assertSame([], $this->ops);
	}

	public function testNamesWithSpacesAndLongestLengthAreAccepted() : void{
		$console = $this->console();
		self::assertSame(["Opped Mr Steve"], $this->dispatch("op \"Mr Steve\"", $console));
		self::assertSame(["Opped " . str_repeat("a", 16)], $this->dispatch("op " . str_repeat("a", 16), $console));
		self::assertSame(["+Mr Steve", "+" . str_repeat("a", 16)], $this->ops);
	}

	public function testPermissionsAreSeparate() : void{
		$giver = $this->player("Giver", [DefaultPermissionNames::COMMAND_OP_GIVE]);
		$taker = $this->player("Taker", [DefaultPermissionNames::COMMAND_OP_TAKE]);

		self::assertStringContainsString("permission", $this->dispatch("deop Steve", $giver)[0]);
		self::assertStringContainsString("permission", $this->dispatch("op Steve", $taker)[0]);
		self::assertSame([], $this->ops);

		self::assertSame(["Opped Steve"], $this->dispatch("op Steve", $giver));
		self::assertSame(["De-opped Steve"], $this->dispatch("deop Steve", $taker));
	}
}
