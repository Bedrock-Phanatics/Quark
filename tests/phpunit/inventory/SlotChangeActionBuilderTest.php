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

namespace quark\inventory;

use PHPUnit\Framework\TestCase;
use quark\entity\Entity;
use quark\inventory\transaction\SlotChangeActionBuilder;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\player\PlayerInventoryType;
use quark\player\PlayerInventoryWindow;

final class SlotChangeActionBuilderTest extends TestCase{

	private function window(Inventory $inventory) : PlayerInventoryWindow{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		return new PlayerInventoryWindow($player, $inventory, PlayerInventoryType::INVENTORY);
	}

	public function testRestoringASlotDropsTheEarlierChange() : void{
		$inventory = new SimpleInventory(2);
		$inventory->setItem(0, VanillaItems::STICK()->setCount(64));
		$builder = new SlotChangeActionBuilder($this->window($inventory));

		$builder->setItem(0, VanillaItems::STICK()->setCount(32));
		$builder->setItem(0, VanillaItems::STICK()->setCount(64));

		self::assertSame(64, $builder->getItem(0)->getCount());
		self::assertCount(0, $builder->generateActions());
	}

	public function testChangesBecomeActionsOnTheWindow() : void{
		$inventory = new SimpleInventory(3);
		$inventory->setItem(2, VanillaItems::APPLE());
		$window = $this->window($inventory);
		$builder = new SlotChangeActionBuilder($window);

		self::assertSame([], $builder->addItem(VanillaItems::STICK()));
		$builder->clear(2);
		self::assertFalse($builder->isSlotEmpty(0));
		self::assertTrue($builder->isSlotEmpty(2));
		self::assertSame(0, $builder->getMatchingItemCount(2, VanillaItems::APPLE(), false));

		$actions = $builder->generateActions();
		self::assertCount(2, $actions);
		foreach($actions as $action){
			self::assertSame($window, $action->getInventoryWindow());
		}
		//the real inventory isn't touched until the transaction runs
		self::assertTrue($inventory->isSlotEmpty(0));
		self::assertFalse($inventory->isSlotEmpty(2));
	}
}
