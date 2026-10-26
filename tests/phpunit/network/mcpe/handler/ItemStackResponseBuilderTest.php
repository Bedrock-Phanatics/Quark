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
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\ItemStackResponsePacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponse;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseContainerInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackresponse\ItemStackResponseSlotInfo;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\ItemStackInfo;
use quark\network\mcpe\protocol\Packets;
use function bin2hex;

final class ItemStackResponseBuilderTest extends TestCase{

	public function testResponsesMatchBedrockProtocol() : void{
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

		$expected = ItemStackResponsePacket::create([
			new ItemStackResponse(ItemStackResponse::RESULT_OK, -7, [
				new ItemStackResponseContainerInfo(new FullContainerName(ContainerUIIds::HOTBAR), [
					new ItemStackResponseSlotInfo(1, 1, 1, 101, "blade", "blade", 12),
					new ItemStackResponseSlotInfo(4, 4, 7, 104, "", "", 0),
				]),
			]),
			new ItemStackResponse(ItemStackResponse::RESULT_ERROR, 9, null),
		]);
		$writer = new ByteBufferWriter();
		$expected->encode($writer);

		$actual = Packets::itemStackResponse(["responses" => [$builder->build(), ItemStackResponseBuilder::error(9)]]);
		self::assertSame(bin2hex($writer->getData()), bin2hex($actual->getEncoded()));
	}
}
