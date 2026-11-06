<?php

declare(strict_types=1);

namespace quark\entity;

use PHPUnit\Framework\TestCase;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;

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
}
