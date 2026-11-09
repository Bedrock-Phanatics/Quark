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

use quark\inventory\ArmorInventory;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\PermissibleBase;
use quark\player\Player;

final class ClearCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, SimpleInventory> */
	private array $main = [];
	/** @phpstan-var array<string, SimpleInventory> */
	private array $cursor = [];
	/** @phpstan-var array<string, ArmorInventory> */
	private array $armor = [];
	/** @phpstan-var array<string, SimpleInventory> */
	private array $offHand = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$this->main[$name] = new SimpleInventory(36);
		$this->cursor[$name] = new SimpleInventory(1);
		$this->armor[$name] = new ArmorInventory();
		$this->offHand[$name] = new SimpleInventory(1);
		$player->method("getInventory")->willReturn($this->main[$name]);
		$player->method("getCursorInventory")->willReturn($this->cursor[$name]);
		$player->method("getArmorInventory")->willReturn($this->armor[$name]);
		$player->method("getOffHandInventory")->willReturn($this->offHand[$name]);

		$this->main[$name]->addItem(VanillaItems::APPLE()->setCount(10), VanillaItems::DIAMOND_SWORD(), VanillaItems::APPLE()->setCount(5));
		$this->cursor[$name]->setItem(0, VanillaItems::APPLE()->setCount(2));
		$this->armor[$name]->setItem(0, VanillaItems::DIAMOND_HELMET());
		$this->offHand[$name]->setItem(0, VanillaItems::APPLE()->setCount(1));
		return $player;
	}

	private function total(string $name, bool $applesOnly = false) : int{
		$total = 0;
		foreach([$this->main[$name], $this->cursor[$name], $this->armor[$name], $this->offHand[$name]] as $inventory){
			foreach($inventory->getContents() as $item){
				if(!$applesOnly || $item->getTypeId() === VanillaItems::APPLE()->getTypeId()){
					$total += $item->getCount();
				}
			}
		}
		return $total;
	}

	public function testClearsOwnInventory() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(20, $this->total("Steve"));
		self::assertSame(["Cleared the inventory of Steve, removing 20 items"], $this->dispatch("clear", $steve));
		self::assertSame(0, $this->total("Steve"));
		self::assertSame(["Could not clear inventory of Steve, no items to remove"], $this->dispatch("clear", $steve));
	}

	public function testClearsOtherPlayersInventory() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");
		$this->dispatch("clear Alex", $steve);
		self::assertSame(0, $this->total("Alex"));
		self::assertSame(20, $this->total("Steve"));

		$this->spawn("Alex");
		$this->dispatch("clear Alex", $this->console());
		self::assertSame(0, $this->total("Alex"));
	}

	public function testClearsOnlyTheNamedItem() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Cleared the inventory of Steve, removing 18 items"], $this->dispatch("clear Steve apple", $steve));
		self::assertSame(2, $this->total("Steve"));
		self::assertSame(0, $this->total("Steve", true));
		self::assertSame(["Could not clear inventory of Steve, no items to remove"], $this->dispatch("clear @s apple", $steve));
	}

	public function testClearsUpToMaxCount() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Cleared the inventory of Steve, removing 12 items"], $this->dispatch("clear Steve apple 12", $steve));
		self::assertSame(6, $this->total("Steve", true));
		self::assertSame(["Cleared the inventory of Steve, removing 6 items"], $this->dispatch("clear Steve apple 100", $steve));
		self::assertSame(0, $this->total("Steve", true));
	}

	public function testMaxCountZeroOnlyCounts() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Steve has 18 items that match the criteria"], $this->dispatch("clear Steve apple 0", $steve));
		self::assertSame(["Could not clear inventory of Steve, no items to remove"], $this->dispatch("clear Steve diamond 0", $steve));
		self::assertSame(20, $this->total("Steve"));
	}

	public function testMaxCountMinusOneClearsEverythingMatching() : void{
		$steve = $this->spawn("Steve");
		$this->dispatch("clear Steve apple -1", $steve);
		self::assertSame(0, $this->total("Steve", true));
		self::assertSame(2, $this->total("Steve"));
	}

	public function testRejectsInvalidInputWithoutClearing() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		foreach([
			"clear Nobody", "clear Steve notanitem", "clear Steve air", "clear Steve apple abc", "clear Steve apple -2", "clear Steve apple 1.5",
			"clear Steve apple 99999999999999999999", "clear Steve apple 1 extra", "clear @a", "clear Steve apple 1e1"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame(20, $this->total("Steve"), $line);
			self::assertSame(20, $this->total("Alex"), $line);
		}
		self::assertStringContainsString("There is no such item with name notanitem", $this->dispatch("clear Steve notanitem", $steve)[0]);
	}

	public function testConsoleNeedsATarget() : void{
		$this->spawn("Alex");
		$console = $this->console();
		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("clear", $console));
		self::assertStringContainsString("Cannot find a player matching \"@s\"", $this->dispatch("clear @s", $console)[0]);
		self::assertSame(20, $this->total("Alex"));
	}

	public function testSelfPermissionDoesNotAllowClearingOthers() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_CLEAR_SELF]);
		$this->spawn("Alex");
		$this->dispatch("clear Alex", $steve);
		self::assertSame(20, $this->total("Alex"));
		$this->dispatch("clear Steve apple", $steve);
		self::assertSame(2, $this->total("Steve"));
		$this->dispatch("clear", $steve);
		self::assertSame(0, $this->total("Steve"));
	}

	public function testOtherPermissionDoesNotAllowClearingYourself() : void{
		$steve = $this->spawn("Steve", [DefaultPermissionNames::COMMAND_CLEAR_OTHER]);
		$this->spawn("Alex");
		$this->dispatch("clear", $steve);
		$this->dispatch("clear Steve", $steve);
		self::assertSame(20, $this->total("Steve"));
		$this->dispatch("clear Alex", $steve);
		self::assertSame(0, $this->total("Alex"));
	}

	public function testClearIsOperatorOnlyByDefaultLikeOtherInventoryCommands() : void{
		foreach([
			DefaultPermissionNames::COMMAND_CLEAR_SELF,
			DefaultPermissionNames::COMMAND_CLEAR_OTHER,
			DefaultPermissionNames::COMMAND_GIVE_SELF,
			DefaultPermissionNames::COMMAND_GIVE_OTHER,
			DefaultPermissionNames::COMMAND_ENCHANT_SELF,
			DefaultPermissionNames::COMMAND_ENCHANT_OTHER,
			DefaultPermissionNames::COMMAND_EFFECT_SELF,
			DefaultPermissionNames::COMMAND_EFFECT_OTHER
		] as $permission){
			$user = new PermissibleBase([DefaultPermissions::ROOT_USER => true]);
			$operator = new PermissibleBase([DefaultPermissions::ROOT_OPERATOR => true]);
			self::assertFalse($user->hasPermission($permission), $permission);
			self::assertTrue($operator->hasPermission($permission), $permission);
		}
	}
}
