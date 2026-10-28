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

namespace quark\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\ItemStackInfo;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\packet\ItemStackResponsePacket;
use quark\network\mcpe\protocol\RecordedPackets;
use quark\network\mcpe\protocol\types\inventory\ContainerUIIds;
use function bin2hex;

final class ItemStackResponseBuilderTest extends TestCase{

	public function testResponsesMatchRecordedEncoding() : void{
		$inventory = new SimpleInventory(9);
		$inventory->setItem(1, VanillaItems::DIAMOND_SWORD()->setDamage(12)->setCustomName("blade"));
		$inventory->setItem(4, VanillaItems::APPLE()->setCount(7));
		$manager = $this->createMock(InventoryManager::class);
		$manager->method("getCurrentWindowId")->willReturn(0);
		$manager->method("locateWindowAndSlot")->willReturnCallback(fn(int $windowId, int $slot) : ?array => $slot < 9 ? [$inventory, $slot] : null);
		$manager->method("getItemStackInfo")->willReturnCallback(fn($inventory, int $slot) : ItemStackInfo => new ItemStackInfo(null, 100 + $slot));

		$builder = new ItemStackResponseBuilder(-7, $manager);
		$builder->addSlot(ContainerUIIds::HOTBAR, 1);
		$builder->addSlot(ContainerUIIds::HOTBAR, 4);
		$builder->addSlot(ContainerUIIds::HOTBAR, 1);
		$builder->addSlot(ContainerUIIds::HOTBAR, 20); //unknown slots are left out
		$builder->addSlot(ContainerUIIds::CREATED_OUTPUT, 50);

		//slots 1 and 4 of the hotbar with their stack IDs, damage and custom name, then an error for request 9
		$actual = new ItemStackResponsePacket([$builder->build(), ItemStackResponseBuilder::error(9)]);
		self::assertSame(bin2hex(RecordedPackets::get($this, 0)), bin2hex(EncodedPacket::of($actual)->getBytes()));
	}
}
