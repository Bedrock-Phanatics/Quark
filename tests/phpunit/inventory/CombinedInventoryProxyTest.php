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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\entity\Entity;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\world\Position;
use function array_keys;
use function gc_collect_cycles;
use function spl_object_id;

final class CombinedInventoryProxyTest extends TestCase{

	private function player() : Player&MockObject{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		return $player;
	}

	public function testSlotsAreLaidOutInOrder() : void{
		$left = new SimpleInventory(2);
		$right = new SimpleInventory(3);
		$proxy = new CombinedInventoryProxy([$left, $right]);
		self::assertSame(5, $proxy->getSize());

		$proxy->setItem(1, VanillaItems::APPLE());
		$proxy->setItem(2, VanillaItems::STICK());
		$right->setItem(2, VanillaItems::DIAMOND());

		self::assertTrue($left->getItem(1)->equals(VanillaItems::APPLE()));
		self::assertTrue($right->getItem(0)->equals(VanillaItems::STICK()));
		self::assertTrue($proxy->getItem(4)->equals(VanillaItems::DIAMOND()));
		self::assertSame([1, 2, 4], array_keys($proxy->getContents()));
	}

	public function testOutOfRangeSlotsAreRejected() : void{
		$proxy = new CombinedInventoryProxy([new SimpleInventory(2), new SimpleInventory(2)]);
		$rejected = 0;
		foreach([-1, 4] as $slot){
			try{
				$proxy->getItem($slot);
			}catch(\InvalidArgumentException){
				++$rejected;
			}
		}
		self::assertSame(2, $rejected);
	}

	public function testTheSameInventoryCannotBeCombinedTwice() : void{
		$inventory = new SimpleInventory(1);
		$this->expectException(\InvalidArgumentException::class);
		new CombinedInventoryProxy([$inventory, $inventory]);
	}

	public function testItemsAreAddedAcrossBackingInventories() : void{
		$left = new SimpleInventory(1);
		$right = new SimpleInventory(1);
		$proxy = new CombinedInventoryProxy([$left, $right]);
		self::assertSame([], $proxy->addItem(VanillaItems::STICK()->setCount(100)));
		self::assertSame(64, $left->getItem(0)->getCount());
		self::assertSame(36, $right->getItem(0)->getCount());

		$proxy->setContents([1 => VanillaItems::APPLE()]);
		self::assertTrue($left->isSlotEmpty(0));
		self::assertTrue($right->getItem(0)->equals(VanillaItems::APPLE()));
	}

	public function testBackingChangesReachListenersOnceWithCombinedSlots() : void{
		$left = new SimpleInventory(2);
		$right = new SimpleInventory(2);
		$proxy = new CombinedInventoryProxy([$left, $right]);
		$slots = [];
		$proxy->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $_, int $slot, Item $__) use (&$slots) : void{ $slots[] = $slot; },
			null
		));

		$right->setItem(1, VanillaItems::APPLE());
		$proxy->setItem(0, VanillaItems::STICK());
		$proxy->setItem(3, VanillaItems::DIAMOND());

		self::assertSame([3, 0, 3], $slots);
	}

	public function testOtherProxiesOfTheSameChestSeeChanges() : void{
		$left = new SimpleInventory(1);
		$right = new SimpleInventory(1);
		$a = new CombinedInventoryProxy([$left, $right]);
		$b = new CombinedInventoryProxy([$left, $right]);
		$changes = 0;
		$b->getListeners()->add(CallbackInventoryListener::onAnyChange(function() use (&$changes) : void{ ++$changes; }));
		$a->setItem(1, VanillaItems::APPLE());
		self::assertSame(1, $changes);
		self::assertTrue($b->getItem(1)->equals(VanillaItems::APPLE()));
	}

	public function testDroppedProxiesStopListening() : void{
		$left = new SimpleInventory(1);
		$right = new SimpleInventory(1);
		(static function() use ($left, $right) : void{
			$proxy = new CombinedInventoryProxy([$left, $right]);
			$proxy->getListeners()->add(CallbackInventoryListener::onAnyChange(static function() : void{}));
		})();
		gc_collect_cycles();
		self::assertSame([], $left->getListeners()->toArray());
		self::assertSame([], $right->getListeners()->toArray());
	}

	public function testWindowsAreTrackedByEveryBackingInventory() : void{
		$left = new SimpleInventory(1);
		$right = new SimpleInventory(1);
		$proxy = new CombinedInventoryProxy([$left, $right]);
		$player = $this->player();
		$window = new BlockInventoryWindow($player, $proxy, new Position(0, 0, 0, null));
		$player->method("getCurrentWindow")->willReturn($window);

		$window->onOpen();
		self::assertSame([spl_object_id($player) => $player], $proxy->getViewers());
		self::assertSame([spl_object_id($player) => $player], $right->getViewers());

		//destroying either half must close the combined window
		$player->expects(self::once())->method("removeCurrentWindow");
		$left->removeAllWindows();
		self::assertSame([], $left->getViewers());
	}
}
