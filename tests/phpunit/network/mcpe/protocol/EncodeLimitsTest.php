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
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\utils\Limits;
use quark\entity\InvalidSkinException;
use quark\entity\Skin;
use quark\item\enchantment\EnchantingOption;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\LegacySkinAdapter;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\utils\Utils;
use quark\world\World;
use function array_fill;
use function str_repeat;
use const NAN;

/**
 * Values the API allows but the protocol cannot carry must be clamped or rejected before they are sent.
 */
final class EncodeLimitsTest extends TestCase{

	public function testOversizedStackCountIsClamped() : void{
		$stack = TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::STICK()->setCount(70000));
		self::assertSame(TypeConverter::MAX_NETWORK_COUNT, $stack->getCount());
		$decoded = EncodedPacket::of(MobEquipmentPacket::create(1, new ItemStackWrapper(0, $stack), 0, 0, 0))->getPacket();
		self::assertInstanceOf(MobEquipmentPacket::class, $decoded);
		self::assertSame(TypeConverter::MAX_NETWORK_COUNT, $decoded->item->getItemStack()->getCount());
	}

	/**
	 * @return \Generator<string, array{int, string, list<int>}>
	 */
	public static function badEnchantingOptionProvider() : \Generator{
		yield "xp level" => [256, "name", []];
		yield "empty name" => [1, "", []];
		yield "long name" => [1, str_repeat("a", 257), []];
		yield "enchantment level" => [1, "name", [256]];
	}

	/**
	 * @dataProvider badEnchantingOptionProvider
	 * @param list<int> $levels
	 */
	public function testEnchantingOptionRejectsUnsendableValues(int $xpLevel, string $name, array $levels) : void{
		$enchantments = [];
		foreach($levels as $level){
			$enchantments[] = new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), $level);
		}
		$this->expectException(\InvalidArgumentException::class);
		new EnchantingOption($xpLevel, $name, $enchantments);
	}

	public function testWorldTimeKeepsTimeOfDay() : void{
		self::assertSame(1000, NetworkSession::networkWorldTime(1000));
		$time = NetworkSession::networkWorldTime(Limits::INT32_MAX + 1);
		self::assertGreaterThanOrEqual(Limits::INT32_MIN, $time);
		self::assertLessThanOrEqual(Limits::INT32_MAX, $time);
		self::assertSame((Limits::INT32_MAX + 1) % World::TIME_FULL, $time % World::TIME_FULL);
		$decoded = EncodedPacket::of(SetTimePacket::create($time))->getPacket();
		self::assertInstanceOf(SetTimePacket::class, $decoded);
		self::assertSame($time, $decoded->time);
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

	/**
	 * @param list<SkinAnimation> $animations
	 */
	private static function skinData(SkinImage $image, array $animations = []) : SkinData{
		return new SkinData("id", '{"geometry":{"default":"geometry.humanoid.custom"}}', $image, $animations);
	}

	public function testSkinsWithinLimitsAreAccepted() : void{
		$skin = (new LegacySkinAdapter())->fromSkinData(self::skinData(new SkinImage(64, 64, str_repeat("\x00", 64 * 64 * 4))));
		self::assertSame("geometry.humanoid.custom", $skin->getGeometryName());
	}

	/**
	 * @return \Generator<string, array{SkinData}>
	 */
	public static function oversizedSkinProvider() : \Generator{
		$small = new SkinImage(1, 1, "\x00\x00\x00\x00");
		yield "skin image" => [self::skinData(new SkinImage(1, 4097, str_repeat("\x00", 4097 * 4)))];
		yield "animation image" => [self::skinData($small, [new SkinAnimation(new SkinImage(4097, 1, str_repeat("\x00", 4097 * 4)), 0, 1.0, 0)])];
		yield "animation count" => [self::skinData($small, array_fill(0, 33, new SkinAnimation($small, 0, 1.0, 0)))];
		yield "animation type" => [self::skinData($small, [new SkinAnimation($small, 0x1_0000_0000, 1.0, 0)])];
		yield "negative expression" => [self::skinData($small, [new SkinAnimation($small, 0, 1.0, -1)])];
	}

	/**
	 * @dataProvider oversizedSkinProvider
	 */
	public function testSkinLimits(SkinData $data) : void{
		$this->expectException(InvalidSkinException::class);
		(new LegacySkinAdapter())->fromSkinData($data);
	}

	public function testInfiniteSkinGeometryIsInvalid() : void{
		$this->expectException(InvalidSkinException::class);
		new Skin("id", str_repeat("\x00", 64 * 64 * 4), "", "geometry", '{"a":1e999}');
	}
}
