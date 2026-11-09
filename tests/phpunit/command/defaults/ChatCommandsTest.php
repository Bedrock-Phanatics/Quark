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

use quark\command\TestCommandSender;
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;

final class ChatCommandsTest extends DefaultCommandTestCase{
	/** @phpstan-var list<string> */
	private array $broadcasts = [];

	protected function setUp() : void{
		parent::setUp();
		$this->server->method("broadcastMessage")->willReturnCallback(function(Translatable|string $message) : int{
			$this->broadcasts[] = TextFormat::clean($message instanceof Translatable ? TestCommandSender::language()->translate($message) : $message);
			return 1;
		});
	}

	public function testMeBroadcastsTheRawTextWithTheSendersName() : void{
		$steve = $this->player("Steve");

		self::assertSame([], $this->dispatch("me waves  \"hello\"   there ", $steve));
		self::assertSame([], $this->dispatch("me hi", $this->console()));
		self::assertSame(["* Steve waves  \"hello\"   there", "* tester hi"], $this->broadcasts);
	}

	public function testMeRequiresTextAndPermission() : void{
		self::assertSame(["Usage: /me <message...>"], $this->dispatch("me", $this->console()));

		$denied = $this->player("Denied", []);
		self::assertStringContainsString("permission", $this->dispatch("me hi", $denied)[0]);
		self::assertSame([], $this->broadcasts);

		$allowed = $this->player("Allowed", [DefaultPermissionNames::COMMAND_ME]);
		$this->dispatch("me hi", $allowed);
		self::assertSame(["* Allowed hi"], $this->broadcasts);
	}

	public function testTellWhispersToPlayerWithAliasesAndPrefixMatching() : void{
		$steve = $this->player("Steve");
		$this->player("Alex");

		self::assertSame(["You whisper to Alex: hi  \"there\"  friend"], $this->dispatch("tell Alex hi  \"there\"  friend ", $steve));
		self::assertSame(["Steve whispers to you: hi  \"there\"  friend"], $this->sent["Alex"]);

		self::assertSame(["You whisper to Alex: yo"], $this->dispatch("w ale yo", $steve));
		self::assertSame(["You whisper to Alex: sup"], $this->dispatch("msg Alex sup", $steve));
	}

	public function testTellFromConsoleUsesConsoleName() : void{
		$this->player("Alex");
		self::assertSame(["You whisper to Alex: hello"], $this->dispatch("tell Alex hello", $this->console()));
		self::assertSame(["tester whispers to you: hello"], $this->sent["Alex"]);
	}

	public function testTellRejectsSelfUnknownPlayersAndMissingArguments() : void{
		$steve = $this->player("Steve");
		$this->player("Alex");

		self::assertSame(["You can't send a private message to yourself!"], $this->dispatch("tell Steve hi", $steve));
		self::assertStringContainsString("Cannot find a player matching \"Nobody\"", $this->dispatch("tell Nobody hi", $steve)[0]);
		self::assertStringContainsString("not supported", $this->dispatch("tell @a hi", $steve)[0]);
		self::assertSame(["Usage: /tell <player> <message...>"], $this->dispatch("tell Alex", $steve));
		self::assertSame(["Usage: /tell <player> <message...>"], $this->dispatch("tell", $steve));
		self::assertArrayNotHasKey("Alex", $this->sent);
	}

	public function testTellRequiresPermission() : void{
		$denied = $this->player("Denied", []);
		$this->player("Alex");
		self::assertStringContainsString("permission", $this->dispatch("tell Alex hi", $denied)[0]);
		self::assertArrayNotHasKey("Alex", $this->sent);
	}
}
