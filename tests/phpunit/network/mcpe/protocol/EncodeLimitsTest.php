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
use pocketmine\math\Vector3;
use pocketmine\utils\Limits;
use quark\entity\InvalidSkinException;
use quark\entity\Skin;
use quark\item\enchantment\EnchantingOption;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket;
use quark\network\mcpe\protocol\packet\SetTimePacket;
use quark\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use quark\network\mcpe\protocol\types\LevelEvent;
use quark\network\mcpe\protocol\types\LevelSoundEvent;
use quark\network\mcpe\protocol\types\skin\SkinImage;
use quark\utils\Utils;
use quark\world\World;
use function str_repeat;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

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

	public function testLevelEventDataIsClamped() : void{
		self::assertNotSame("", EncodedPacket::of(LevelPackets::event(LevelEvent::BLOCK_START_BREAK, PHP_INT_MAX, null))->getBytes());
		self::assertNotSame("", EncodedPacket::of(LevelPackets::sound(LevelSoundEvent::LEVELUP, Vector3::zero(), false, PHP_INT_MIN))->getBytes());
	}

	public function testWorldTimeKeepsTimeOfDay() : void{
		self::assertSame(1000, NetworkSession::networkWorldTime(1000));
		$time = NetworkSession::networkWorldTime(Limits::INT32_MAX + 1);
		self::assertSame((Limits::INT32_MAX + 1) % World::TIME_FULL, $time % World::TIME_FULL);
		self::assertNotSame("", EncodedPacket::of(new SetTimePacket(time: $time))->getBytes());
	}

	/**
	 * @return \Generator<string, array{Vector3}>
	 */
	public static function badBlockPositionProvider() : \Generator{
		yield "too large" => [new Vector3(3e9, 0, 0)];
		yield "too small" => [new Vector3(0, -3e9, 0)];
		yield "nan" => [new Vector3(0, 0, NAN)];
	}

	/**
	 * @dataProvider badBlockPositionProvider
	 */
	public function testBlockPositionsOutsideInt32AreRejected(Vector3 $position) : void{
		$this->expectException(\InvalidArgumentException::class);
		Utils::checkBlockPositionFitsInt32($position);
	}

	public function testSkinLimits() : void{
		$this->expectException(\InvalidArgumentException::class);
		new SkinImage(SkinImage::MAX_SIZE + 1, 1, str_repeat("\x00", (SkinImage::MAX_SIZE + 1) * 4));
	}

	public function testInfiniteSkinGeometryIsInvalid() : void{
		$this->expectException(InvalidSkinException::class);
		new Skin("id", str_repeat("\x00", 64 * 64 * 4), "", "geometry", '{"a":1e999}');
	}
}
