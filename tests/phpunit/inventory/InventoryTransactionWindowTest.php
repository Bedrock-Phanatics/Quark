<?php

declare(strict_types=1);

namespace quark\inventory;

use PHPUnit\Framework\TestCase;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\CraftingTableInventoryWindow;
use quark\entity\Entity;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\inventory\InventoryTransactionEvent;
use quark\event\RegisteredListener;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\inventory\transaction\InventoryTransaction;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\player\PlayerInventoryType;
use quark\player\PlayerInventoryWindow;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\Position;

final class InventoryTransactionWindowTest extends TestCase{
	public function testCombinedSlotsKeepBackingInventoryRestrictions() : void{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, $this->createMock(\Logger::class));
		$storage = new SimpleInventory(2);
		$storage->setMaxStackSize(1);
		$inventory = new CombinedInventoryProxy([$storage, new ArmorInventory()]);
		$window = new BlockInventoryWindow($player, $inventory, new Position(0, 64, 0, null));
		$player->method("getCurrentWindow")->willReturn($window);
		(new SlotChangeAction($window, 2 + ArmorInventory::SLOT_HEAD, VanillaItems::AIR(), VanillaItems::DIAMOND_HELMET()))->validate($player);
		foreach([
			[0, VanillaItems::DIAMOND()->setCount(2)],
			[2 + ArmorInventory::SLOT_CHEST, VanillaItems::DIAMOND_HELMET()]
		] as [$slot, $item]){
			try{
				(new SlotChangeAction($window, $slot, VanillaItems::AIR(), $item))->validate($player);
				self::fail("Combined inventory bypassed its backing slot restrictions");
			}catch(TransactionValidationException){
				self::assertTrue($inventory->isSlotEmpty($slot));
			}
		}
	}

	public function testTransferRejectsAWindowClosedByTheTransactionEvent() : void{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, $this->createMock(\Logger::class));
		$inventory = new SimpleInventory(36);
		$item = VanillaItems::DIAMOND();
		$inventory->setItem(0, $item);
		$main = new PlayerInventoryWindow($player, $inventory, PlayerInventoryType::INVENTORY);
		$player->method("getPermanentWindows")->willReturn([PlayerInventoryType::INVENTORY->name => $main]);
		$window = new CraftingTableInventoryWindow($player, new Position(0, 64, 0, null));
		$current = $window;
		$player->method("getCurrentWindow")->willReturnCallback(static function() use (&$current){ return $current; });
		$transaction = new InventoryTransaction($player, [
			new SlotChangeAction($main, 0, $item, VanillaItems::AIR()),
			new SlotChangeAction($window, 0, VanillaItems::AIR(), $item)
		]);
		$transaction->validate();
		$plugin = $this->createMock(Plugin::class);
		HandlerListManager::global()->getListFor(InventoryTransactionEvent::class)->register(new RegisteredListener(
			static function(InventoryTransactionEvent $event) use (&$current) : void{ $current = null; },
			EventPriority::NORMAL, $plugin, true, Pulse::zone("inventory.test")
		));
		try{
			try{
				$transaction->execute();
				self::fail("Transfer into a closed window succeeded");
			}catch(TransactionValidationException){
				self::assertTrue($item->equalsExact($inventory->getItem(0)));
				self::assertTrue($window->getInventory()->isSlotEmpty(0));
				self::assertFalse($transaction->hasExecuted());
			}
		}finally{
			HandlerListManager::global()->unregisterAll($plugin);
		}
	}
}
