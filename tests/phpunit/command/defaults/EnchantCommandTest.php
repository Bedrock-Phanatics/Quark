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

use quark\item\enchantment\VanillaEnchantments;
use quark\item\Item;
use quark\item\ItemTypeIds;
use quark\item\VanillaItems;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

final class EnchantCommandTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, Item> */
	private array $held = [];
	/** @phpstan-var list<string> */
	private array $changes = [];

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function spawn(string $name, ?Item $item = null, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$this->held[$name] = $item ?? VanillaItems::DIAMOND_SWORD();
		$player->method("getMainHandItem")->willReturnCallback(fn() : Item => $this->held[$name]);
		$player->method("setMainHandItem")->willReturnCallback(function(Item $item) use ($name) : void{
			$this->held[$name] = $item;
			$this->changes[] = $name;
		});
		return $player;
	}

	public function testEnchantsHeldItem() : void{
		$steve = $this->spawn("Steve");
		self::assertSame(["Enchanting succeeded for Steve"], $this->dispatch("enchant Steve sharpness", $steve));
		self::assertSame(1, $this->held["Steve"]->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));

		$this->dispatch("enchant Steve sharpness 5", $steve);
		self::assertSame(5, $this->held["Steve"]->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));

		$this->dispatch("enchant @s UNBREAKING 3", $steve);
		self::assertSame(3, $this->held["Steve"]->getEnchantmentLevel(VanillaEnchantments::UNBREAKING()));
		self::assertSame(5, $this->held["Steve"]->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testEnchantsBooksIntoEnchantedBooks() : void{
		$steve = $this->spawn("Steve", VanillaItems::BOOK());
		$this->dispatch("enchant Steve sharpness 2", $steve);
		self::assertSame(ItemTypeIds::ENCHANTED_BOOK, $this->held["Steve"]->getTypeId());
		self::assertSame(2, $this->held["Steve"]->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testEnchantsOtherPlayerFromConsole() : void{
		$this->spawn("Alex");
		$this->dispatch("enchant Alex sharpness 3", $this->console());
		self::assertSame(["Alex"], $this->changes);
		self::assertSame(3, $this->held["Alex"]->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()));
	}

	public function testRejectsLevelsAboveTheMaximum() : void{
		$steve = $this->spawn("Steve");
		self::assertStringContainsString("is too big", $this->dispatch("enchant Steve sharpness 6", $steve)[0]);
		self::assertStringContainsString("is too big", $this->dispatch("enchant Steve unbreaking 4", $steve)[0]);
		self::assertSame([], $this->changes);
	}

	public function testRejectsInvalidInputWithoutChangingTheItem() : void{
		$steve = $this->spawn("Steve");
		$this->spawn("Alex");

		foreach([
			"enchant", "enchant Steve", "enchant Nobody sharpness", "enchant Steve notanenchantment", "enchant Steve sharpness 0",
			"enchant Steve sharpness -1", "enchant Steve sharpness abc", "enchant Steve sharpness 1.5", "enchant Steve sharpness 99999999999999999999",
			"enchant Steve sharpness 32768", "enchant Steve sharpness 1 extra", "enchant @a sharpness", "enchant Steve sharpness 1e1"
		] as $line){
			$this->dispatch($line, $steve);
			self::assertSame([], $this->changes, $line);
		}
		self::assertStringContainsString("There is no such enchantment with ID notanenchantment", $this->dispatch("enchant Steve notanenchantment", $steve)[0]);
	}

	public function testRequiresAHeldItem() : void{
		$steve = $this->spawn("Steve", VanillaItems::AIR());
		self::assertSame(["The target does not hold an item"], $this->dispatch("enchant Steve sharpness", $steve));
		self::assertSame([], $this->changes);
	}

	public function testConsoleCannotEnchantItself() : void{
		$this->spawn("Alex");
		self::assertStringContainsString("Cannot find a player matching \"@s\"", $this->dispatch("enchant @s sharpness", $this->console())[0]);
		self::assertSame([], $this->changes);
	}

	public function testSelfPermissionDoesNotAllowEnchantingOthers() : void{
		$steve = $this->spawn("Steve", null, [DefaultPermissionNames::COMMAND_ENCHANT_SELF]);
		$this->spawn("Alex");
		$this->dispatch("enchant Alex sharpness", $steve);
		self::assertSame([], $this->changes);
		$this->dispatch("enchant Steve sharpness", $steve);
		self::assertSame(["Steve"], $this->changes);
	}

	public function testWithoutPermissionNothingChanges() : void{
		$steve = $this->spawn("Steve", null, false);
		$this->dispatch("enchant Steve sharpness", $steve);
		self::assertSame([], $this->changes);
	}
}
