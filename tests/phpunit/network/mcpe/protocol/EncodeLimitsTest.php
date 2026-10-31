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

namespace quark\network\mcpe\protocol;

use PHPUnit\Framework\TestCase;
use quark\item\enchantment\EnchantingOption;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket;
use quark\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use function str_repeat;

/**
 * Values the API allows but the protocol cannot carry must not reach the native encoder unchanged.
 */
final class EncodeLimitsTest extends TestCase{

	public function testOversizedTextIsCut() : void{
		self::assertNotSame("", EncodedPacket::of(TextPackets::raw(str_repeat("é", 40000)))->getBytes());
		self::assertNotSame("", EncodedPacket::of(TextPackets::translation("key", ["1", "2", "3", "4", "5", "6"]))->getBytes());
	}

	public function testOversizedStackCountIsClamped() : void{
		$stack = TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::STICK()->setCount(70000));
		self::assertSame(TypeConverter::MAX_NETWORK_COUNT, $stack->getCount());
		self::assertNotSame("", EncodedPacket::of(new MobEquipmentPacket(
			targetRuntimeId: 1,
			item: Fields::itemStackWrapper(new ItemStackWrapper(0, $stack)),
			slot: 0,
			selectedSlot: 0,
			containerId: 0,
		))->getBytes());
	}

	public function testEnchantingOptionRejectsUnsendableValues() : void{
		$this->expectException(\InvalidArgumentException::class);
		new EnchantingOption(256, "name", []);
	}
}
