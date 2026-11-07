<?php

declare(strict_types=1);

namespace quark\entity;

use PHPUnit\Framework\TestCase;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\player\OfflinePlayer;
use function spl_object_id;
use function str_repeat;

final class HumanInventoryTest extends TestCase{
	public function testOffHandAccessorsUseTheOffHandInventory() : void{
		$human = new \ReflectionClass(Human::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(Entity::class, "closed")->setValue($human, true);
		$inventory = new SimpleInventory(1);
		new \ReflectionProperty(Human::class, "offHandInventory")->setValue($human, $inventory);
		self::assertTrue($human->getOffHandItem()->isNull());

		$item = VanillaItems::TOTEM();
		$human->setOffHandItem($item);
		self::assertTrue($item->equalsExact($inventory->getItem(0)));
		self::assertTrue($item->equalsExact($human->getOffHandItem()));
		$item->setCount(2);
		self::assertSame(1, $human->getOffHandItem()->getCount());
		$human->getOffHandItem()->setCount(3);
		self::assertSame(1, $human->getOffHandItem()->getCount());
	}

	public function testSavedInventoriesLoadAsOfflinePlayer() : void{
		$world = $this->createMock(\quark\world\World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getServer")->willReturn($this->createMock(\quark\Server::class));
		$human = new Human(new Location(0, 64, 0, $world, 0, 0), new Skin("test", str_repeat("\0", 64 * 32 * 4)));
		$human->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$human->getArmorInventory()->setBoots(VanillaItems::IRON_BOOTS());
		$human->setOffHandItem(VanillaItems::TOTEM());
		$human->getEnderInventory()->setItem(4, VanillaItems::APPLE());

		$player = new OfflinePlayer("Steve", $human->saveNBT());
		self::assertTrue(VanillaItems::DIAMOND()->equalsExact($player->getInventory()->getItem(0)));
		self::assertTrue(VanillaItems::IRON_BOOTS()->equalsExact($player->getArmorInventory()->getBoots()));
		self::assertTrue(VanillaItems::TOTEM()->equalsExact($player->getOffHandInventory()->getItem(0)));
		self::assertTrue(VanillaItems::APPLE()->equalsExact($player->getEnderInventory()->getItem(4)));
	}

	public function testCloseDetachesOwnedInventoryListenersOnly() : void{
		$server = $this->createMock(\quark\Server::class);
		$world = $this->createMock(\quark\world\World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->method("getServer")->willReturn($server);
		$human = new Human(new Location(0, 64, 0, $world, 0, 0), new Skin("test", str_repeat("\0", 64 * 32 * 4)));
		$inventories = [$human->getInventory(), $human->getArmorInventory(), $human->getOffHandInventory()];
		$pluginChanges = 0;
		$pluginListener = CallbackInventoryListener::onAnyChange(static function() use (&$pluginChanges) : void{ ++$pluginChanges; });
		foreach($inventories as $inventory){
			$inventory->getListeners()->add($pluginListener);
		}

		$human->close();

		foreach($inventories as $inventory){
			self::assertSame([spl_object_id($pluginListener) => $pluginListener], $inventory->getListeners()->toArray());
			$inventory->setItem(0, VanillaItems::APPLE());
		}
		self::assertSame(3, $pluginChanges);
	}
}
