<?php

declare(strict_types=1);

namespace quark\inventory;

use PHPUnit\Framework\TestCase;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\CraftingTableInventoryWindow;
use quark\block\inventory\window\EnchantingTableInventoryWindow;
use quark\entity\Entity;
use quark\entity\ExperienceManager;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\inventory\InventoryTransactionEvent;
use quark\event\RegisteredListener;
use quark\inventory\transaction\action\DropItemAction;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\inventory\transaction\EnchantingTransaction;
use quark\inventory\transaction\InventoryTransaction;
use quark\inventory\transaction\TransactionValidationException;
use quark\item\enchantment\EnchantingHelper;
use quark\item\enchantment\EnchantingOption;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
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

	/** @return array<string, array{bool}> */
	public static function enchantingDestinations() : array{
		return ["inventory" => [false], "drop" => [true]];
	}

	/** @dataProvider enchantingDestinations */
	public function testEnchantingChargesXpAndRefreshesSeedBeforeInventoryCallbacks(bool $drop) : void{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, $this->createMock(\Logger::class));
		$inventory = new SimpleInventory(36);
		$main = new PlayerInventoryWindow($player, $inventory, PlayerInventoryType::INVENTORY);
		$window = new EnchantingTableInventoryWindow($player, new Position(0, 64, 0, null));
		$player->method("getCurrentWindow")->willReturn($window);
		$player->method("getPermanentWindows")->willReturn([PlayerInventoryType::INVENTORY->name => $main]);
		$player->method("hasFiniteResources")->willReturn(true);
		$level = 30;
		$seed = 123;
		$xp = $this->createMock(ExperienceManager::class);
		$xp->method("getXpLevel")->willReturnCallback(static function() use (&$level) : int{ return $level; });
		$xp->expects(self::once())->method("subtractXpLevels")->with(3)->willReturnCallback(static function(int $cost) use (&$level) : bool{
			$level -= $cost;
			return true;
		});
		$player->method("getXpManager")->willReturn($xp);
		$player->method("getEnchantmentSeed")->willReturnCallback(static function() use (&$seed) : int{ return $seed; });
		$player->expects(self::once())->method("regenerateEnchantmentSeed")->willReturnCallback(static function() use (&$seed) : void{ $seed = 456; });
		$option = new EnchantingOption(30, "test", [new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 1)]);
		$input = VanillaItems::DIAMOND_SWORD();
		$output = EnchantingHelper::enchantItem($input, $option->getEnchantments());
		$lapis = VanillaItems::LAPIS_LAZULI()->setCount(3);
		$window->getInventory()->setContents([$input, $lapis]);
		$transaction = new EnchantingTransaction($player, $option, 3, $window);
		$transaction->addAction($drop ? new DropItemAction($output) : new SlotChangeAction($main, 0, VanillaItems::AIR(), $output));
		$transaction->addAction(new SlotChangeAction($window, EnchantingTableInventoryWindow::SLOT_INPUT, $input, VanillaItems::AIR()));
		$transaction->addAction(new SlotChangeAction($window, EnchantingTableInventoryWindow::SLOT_LAPIS, $lapis, VanillaItems::AIR()));
		$callbacks = 0;
		$listener = CallbackInventoryListener::onAnyChange(static function() use ($player, &$callbacks) : void{
			++$callbacks;
			self::assertSame(27, $player->getXpManager()->getXpLevel());
			self::assertSame(456, $player->getEnchantmentSeed());
		});
		($drop ? $window->getInventory() : $inventory)->getListeners()->add($listener);
		if($drop){
			$player->expects(self::once())->method("dropItem")->with($output)->willReturnCallback(static function() use ($player, $window) : void{
				self::assertSame(27, $player->getXpManager()->getXpLevel());
				self::assertSame(456, $player->getEnchantmentSeed());
				self::assertSame([], $window->getInventory()->getContents());
			});
		}
		$transaction->execute();
		self::assertSame($drop ? 2 : 1, $callbacks);
		self::assertTrue($transaction->hasExecuted());
		self::assertTrue(($drop ? VanillaItems::AIR() : $output)->equalsExact($inventory->getItem(0)));
		self::assertSame([], $window->getInventory()->getContents());
	}
}
