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

use quark\entity\Attribute;
use quark\entity\AttributeFactory;
use quark\entity\AttributeMap;
use quark\entity\ExperienceManager;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

final class XpCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var list<string> */
	private array $calls = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, int $level = 10, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$xp = self::createStub(ExperienceManager::class);
		$xp->method("getXpLevel")->willReturn($level);
		$xp->method("addXp")->willReturnCallback(function(int $amount, bool $sound) use ($name) : bool{
			$this->calls[] = "$name addXp $amount " . ($sound ? "sound" : "silent");
			return true;
		});
		$xp->method("addXpLevels")->willReturnCallback(function(int $amount, bool $sound) use ($name) : bool{
			$this->calls[] = "$name addXpLevels $amount " . ($sound ? "sound" : "silent");
			return true;
		});
		$xp->method("subtractXpLevels")->willReturnCallback(function(int $amount) use ($name) : bool{
			$this->calls[] = "$name subtractXpLevels $amount";
			return true;
		});
		$attributes = new AttributeMap();
		$attributes->add(AttributeFactory::getInstance()->mustGet(Attribute::EXPERIENCE_LEVEL));
		$player->method("getXpManager")->willReturn($xp);
		$player->method("getAttributeMap")->willReturn($attributes);
		return $player;
	}

	public function testAddsExperiencePoints() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Gave 100 experience to Steve"], $this->dispatch("xp 100", $steve));
		$this->dispatch("xp 0", $steve);
		$this->dispatch("xp +5", $steve);
		$this->dispatch("xp 2147483647", $steve);
		self::assertSame([
			"Steve addXp 100 silent", "Steve addXp 0 silent", "Steve addXp 5 silent", "Steve addXp 2147483647 silent"
		], $this->calls);
	}

	public function testAddsAndRemovesLevels() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Gave 5 experience levels to Steve"], $this->dispatch("xp 5L", $steve));
		self::assertSame(["Taken 3 levels from Steve"], $this->dispatch("xp -3L", $steve));
		$this->dispatch("xp 0L", $steve);
		$this->dispatch("xp 2l", $steve);
		self::assertSame(["Steve addXpLevels 5 silent", "Steve subtractXpLevels 3", "Steve addXpLevels 0 silent", "Steve addXpLevels 2 silent"], $this->calls);
	}

	public function testLevelsAreLimitedToWhatThePlayerCanHold() : void{
		$steve = $this->spawn("Steve", 10);
		$this->dispatch("xp -50L", $steve);
		$this->dispatch("xp 2147483647L", $steve);
		self::assertSame(["Steve subtractXpLevels 10", "Steve addXpLevels 24781 silent"], $this->calls);
	}

	public function testExperienceCannotBeWithdrawnAsPoints() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Cannot give player negative experience points"], $this->dispatch("xp -5", $steve));
		self::assertSame([], $this->calls);
	}

	public function testTargetsOtherPlayers() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");
		self::assertSame(["Gave 7 experience to Alex"], $this->dispatch("xp 7 Alex", $steve));
		$this->dispatch("xp -2L Alex", $steve);
		$this->dispatch("xp 4 @s", $steve);
		$this->dispatch("xp 3L Al", $this->console());
		self::assertSame(["Alex addXp 7 silent", "Alex subtractXpLevels 2", "Steve addXp 4 silent", "Alex addXpLevels 3 silent"], $this->calls);
	}

	public function testRejectsInvalidInputWithoutChangingExperience() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		foreach([
			"xp", "xp abc", "xp 1.5", "xp 2147483648", "xp -2147483649", "xp 99999999999999999999", "xp 5LL", "xp L", "xp 5 Nobody", "xp 5 Alex extra",
			"xp 5L Nobody", "xp 5L Alex extra", "xp 1.5L", "xp 2147483648L", "xp 99999999999999999999L", "xp 5 @a", "xp 5e1", "xp 5 L"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->calls, $line);
		}
	}

	public function testConsoleNeedsATarget() : void{
		$this->spawn("Alex");
		$console = $this->console();
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("xp 5", $console));
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("xp 5L", $console));
		self::assertSame([], $this->calls);
	}

	public function testSelfPermissionDoesNotAllowChangingOthers() : void{
		$steve = $this->spawn("Steve", 10, [DefaultPermissionNames::COMMAND_XP_SELF]);
		$this->spawn("Alex");
		$this->dispatch("xp 5 Alex", $steve);
		$this->dispatch("xp 5L Alex", $steve);
		self::assertSame([], $this->calls);
		$this->dispatch("xp 5", $steve);
		$this->dispatch("xp 5L Steve", $steve);
		self::assertSame(["Steve addXp 5 silent", "Steve addXpLevels 5 silent"], $this->calls);
	}

	public function testOtherPermissionDoesNotAllowChangingYourself() : void{
		$steve = $this->spawn("Steve", 10, [DefaultPermissionNames::COMMAND_XP_OTHER]);
		$this->spawn("Alex");
		$this->dispatch("xp 5", $steve);
		$this->dispatch("xp 5L", $steve);
		$this->dispatch("xp 5 Steve", $steve);
		self::assertSame([], $this->calls);
		$this->dispatch("xp 5 Alex", $steve);
		self::assertSame(["Alex addXp 5 silent"], $this->calls);
	}
}
