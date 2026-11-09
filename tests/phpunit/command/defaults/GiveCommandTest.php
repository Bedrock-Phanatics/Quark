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

use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

final class GiveCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, SimpleInventory> */
	private array $inventories = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true, int $slots = 36) : Player{
		$player = $this->player($name, $permissions);
		$this->inventories[$name] = new SimpleInventory($slots);
		$player->method("getInventory")->willReturn($this->inventories[$name]);
		return $player;
	}

	private function total(string $name) : int{
		$total = 0;
		foreach($this->inventories[$name]->getContents() as $item){
			$total += $item->getCount();
		}
		return $total;
	}

	public function testGivesAFullStackByDefault() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Given Apple * 64 to Steve"], $this->dispatch("give Steve apple", $steve));
		self::assertSame(64, $this->total("Steve"));
		$this->dispatch("give Steve diamond_sword", $steve);
		self::assertSame(65, $this->total("Steve"));
	}

	public function testGivesRequestedCount() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Given Apple * 3 to Steve"], $this->dispatch("give Steve apple 3", $steve));
		self::assertSame(3, $this->total("Steve"));
		$this->dispatch("give @s apple 200", $steve);
		self::assertSame(203, $this->total("Steve"));
		$this->dispatch("give Stev minecraft:apple 1", $steve);
		self::assertSame(204, $this->total("Steve"));
	}

	public function testGivesToOtherPlayerFromConsole() : void{
		$this->spawn("Alex");
		$this->dispatch("give Alex apple 2", $this->console());
		self::assertSame(2, $this->total("Alex"));
	}

	public function testAcceptsLegacyItemNames() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("give Steve 276 1", $steve);
		self::assertSame(VanillaItems::DIAMOND_SWORD()->getTypeId(), $this->inventories["Steve"]->getItem(0)->getTypeId());
	}

	public function testAppliesTagsPreservingTheirText() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("give Steve apple 1 {display:{Name:\"Two  Spaces, a 'quote'\"}}", $steve);
		self::assertSame("Two  Spaces, a 'quote'", $this->inventories["Steve"]->getItem(0)->getCustomName());
	}

	public function testRejectsInvalidTagsWithoutGivingAnything() : void{
		$steve = $this->spawn("Steve");
		self::assertStringContainsString("Data tag parsing failed", $this->dispatch("give Steve apple 1 {display:", $steve)[0]);
		self::assertStringContainsString("Data tag parsing failed", $this->dispatch("give Steve apple 1 notatag", $steve)[0]);
		self::assertStringContainsString("Data tag parsing failed", $this->dispatch("give Steve apple 1 {} trailing", $steve)[0]);
		self::assertSame(0, $this->total("Steve"));
	}

	public function testRejectsInvalidInputWithoutGivingAnything() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		foreach([
			"give", "give Steve", "give Nobody apple", "give Steve notanitem", "give Steve air", "give Steve 0", "give Steve apple 0", "give Steve apple -1",
			"give Steve apple 32768", "give Steve apple abc", "give Steve apple 1.5", "give Steve apple 99999999999999999999", "give @a apple", "give Steve apple 1e1"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame(0, $this->total("Steve"), $line);
			self::assertSame(0, $this->total("Alex"), $line);
		}
		self::assertStringContainsString("There is no such item with name notanitem", $this->dispatch("give Steve notanitem", $steve)[0]);
		self::assertStringContainsString("There is no such item with name air", $this->dispatch("give Steve air", $steve)[0]);
	}

	public function testDoesNotGiveWhatDoesNotFit() : void{
		$steve = $this->spawn("Steve", slots: 1);
		$this->dispatch("give Steve apple 60", $steve);
		self::assertSame(60, $this->total("Steve"));

		self::assertStringContainsString("not enough inventory space", $this->dispatch("give Steve apple 5", $steve)[0]);
		self::assertSame(60, $this->total("Steve"));
		self::assertStringContainsString("not enough inventory space", $this->dispatch("give Steve diamond_sword 1", $steve)[0]);
		self::assertSame(60, $this->total("Steve"));

		$this->dispatch("give Steve apple 4", $steve);
		self::assertSame(64, $this->total("Steve"));
	}

	public function testConsoleCannotGiveToItself() : void{
		$this->spawn("Alex");
		self::assertStringContainsString("Cannot find a player matching \"@s\"", $this->dispatch("give @s apple", $this->console())[0]);
		self::assertSame(0, $this->total("Alex"));
	}

	public function testSelfPermissionDoesNotAllowGivingToOthers() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_GIVE_SELF]);
		$this->spawn("Alex");
		$this->dispatch("give Alex apple 1", $steve);
		self::assertSame(0, $this->total("Alex"));
		$this->dispatch("give Steve apple 1", $steve);
		self::assertSame(1, $this->total("Steve"));
	}

	public function testWithoutPermissionNothingIsGiven() : void{
		$steve = $this->spawn("Steve", false);
		$this->dispatch("give Steve apple", $steve);
		self::assertSame(0, $this->total("Steve"));
	}
}
