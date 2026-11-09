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
use quark\player\Player;

final class TitleCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var list<string> */
	private array $calls = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("removeTitles")->willReturnCallback(function() use ($name) : void{
			$this->calls[] = "$name clear";
		});
		$player->method("resetTitles")->willReturnCallback(function() use ($name) : void{
			$this->calls[] = "$name reset";
		});
		$player->method("sendTitle")->willReturnCallback(function(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) use ($name) : void{
			$this->calls[] = "$name title $title";
		});
		$player->method("sendSubTitle")->willReturnCallback(function(string $text) use ($name) : void{
			$this->calls[] = "$name subtitle $text";
		});
		$player->method("sendActionBarMessage")->willReturnCallback(function(string $text) use ($name) : void{
			$this->calls[] = "$name actionbar $text";
		});
		$player->method("setTitleDuration")->willReturnCallback(function(int $fadeIn, int $stay, int $fadeOut) use ($name) : void{
			$this->calls[] = "$name times $fadeIn $stay $fadeOut";
		});
		return $player;
	}

	public function testClearAndReset() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Title command successfully executed"], $this->dispatch("title Steve clear", $steve));
		self::assertSame(["Title command successfully executed"], $this->dispatch("title Steve RESET", $steve));
		self::assertSame(["Steve clear", "Steve reset"], $this->calls);
	}

	public function testTextKeepsTheRestOfTheLineAsTyped() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("title Steve title Hello world", $steve);
		$this->dispatch("title Steve subtitle  two  spaces \"quoted\"", $steve);
		$this->dispatch("title Steve actionbar a", $steve);
		$this->dispatch("title Steve title clear", $steve);
		self::assertSame(["Steve title Hello world", "Steve subtitle two  spaces \"quoted\"", "Steve actionbar a", "Steve title clear"], $this->calls);
	}

	public function testTimes() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Title command successfully executed"], $this->dispatch("title Steve times 10 70 20", $steve));
		$this->dispatch("title @s times 0 0 0", $steve);
		$this->dispatch("title Steve times 2147483647 2147483647 2147483647", $steve);
		self::assertSame(["Steve times 10 70 20", "Steve times 0 0 0", "Steve times 2147483647 2147483647 2147483647"], $this->calls);
	}

	public function testTargetsOtherPlayersFromConsole() : void{
		$this->spawn("Alex");
		$this->dispatch("title Alex title Hi", $this->console());
		self::assertSame(["Alex title Hi"], $this->calls);
	}

	public function testRejectsInvalidInputWithoutSendingAnything() : void{
		$steve = $this->spawn("Steve");

		foreach([
			"title", "title Steve", "title Nobody clear", "title Steve unknown", "title Steve clear extra", "title Steve reset extra", "title Steve title",
			"title Steve subtitle", "title Steve actionbar", "title Steve times", "title Steve times 1", "title Steve times 1 2", "title Steve times 1 2 3 4",
			"title Steve times -1 2 3", "title Steve times 1 -2 3", "title Steve times 1 2 -3", "title Steve times a b c", "title Steve times 1.5 2 3",
			"title Steve times 2147483648 1 1", "title Steve times 99999999999999999999 1 1", "title @a clear", "title Steve titles Hi"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->calls, $line);
		}
	}

	public function testConsoleCannotTargetItself() : void{
		$this->spawn("Alex");
		self::assertNotSame([], $this->dispatch("title @s times 1 2 3", $this->console()));
		self::assertSame([], $this->calls);
	}

	public function testSelfPermissionDoesNotAllowTargetingOthers() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_TITLE_SELF]);
		$this->spawn("Alex");
		foreach(["title Alex clear", "title Alex reset", "title Alex title x", "title Alex subtitle x", "title Alex actionbar x", "title Alex times 1 1 1"] as $line){
			$this->dispatch($line, $steve);
		}
		self::assertSame([], $this->calls);
		$this->dispatch("title Steve title x", $steve);
		self::assertSame(["Steve title x"], $this->calls);
	}

	public function testWithoutPermissionNothingIsSent() : void{
		$steve = $this->spawn("Steve", false);
		$this->dispatch("title Steve clear", $steve);
		self::assertSame([], $this->calls);
	}
}
