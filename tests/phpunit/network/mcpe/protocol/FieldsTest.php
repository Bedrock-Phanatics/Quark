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

use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use pocketmine\color\Color;
use quark\crafting\CraftingManagerFromDataHelper;
use quark\data\bedrock\BedrockDataFiles;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\VanillaItems;
use quark\network\mcpe\cache\CraftingDataCache;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket;
use quark\network\mcpe\protocol\packet\PlayerEnchantOptionsPacket;
use quark\network\mcpe\protocol\packet\PlayerListPacket;
use quark\network\mcpe\protocol\packet\PlayerSkinPacket;
use quark\network\mcpe\protocol\packet\UpdateAbilitiesPacket;
use quark\network\mcpe\protocol\types\AbilitiesData;
use quark\network\mcpe\protocol\types\AbilitiesLayer;
use quark\network\mcpe\protocol\types\command\CommandPermissions;
use quark\network\mcpe\protocol\types\DescriptorEmpty;
use quark\network\mcpe\protocol\types\DescriptorItem;
use quark\network\mcpe\protocol\types\EnchantmentInstance as NetEnchantmentInstance;
use quark\network\mcpe\protocol\types\inventory\ItemStack;
use quark\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use quark\network\mcpe\protocol\types\ItemEnchantOption;
use quark\network\mcpe\protocol\types\ItemEnchants;
use quark\network\mcpe\protocol\types\PlayerPermissions;
use quark\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use quark\network\mcpe\protocol\types\skin\SkinAnimation;
use quark\network\mcpe\protocol\types\skin\SkinArmSizeType;
use quark\network\mcpe\protocol\types\skin\SkinData;
use quark\network\mcpe\protocol\types\skin\SkinImage;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use Ramsey\Uuid\Uuid;
use function bin2hex;
use function str_repeat;
use function strlen;

/**
 * Checks that values built for Bedwire\Protocol encode exactly like the bytes BedrockProtocol's encoders recorded, and read back.
 */
final class FieldsTest extends TestCase{

	private static function bytes(Packet $packet) : string{
		return EncodedPacket::of($packet)->getBytes();
	}

	public function testUuids() : void{
		$uuid = Uuid::fromString("0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0");
		self::assertSame(16, strlen(Fields::uuid($uuid)));
		self::assertTrue(Fields::toUuid(Fields::uuid($uuid))->equals($uuid));
	}

	public function testTextMatchesRecordedEncoding() : void{
		self::assertSame(bin2hex(RecordedPackets::get($this, 0)), bin2hex(self::bytes(TextPackets::raw("hello"))));
		self::assertSame(bin2hex(RecordedPackets::get($this, 1)), bin2hex(self::bytes(TextPackets::tip(""))));
		self::assertSame(bin2hex(RecordedPackets::get($this, 2)), bin2hex(self::bytes(TextPackets::translation("key", ["a", "b"]))));
		self::assertSame(bin2hex(RecordedPackets::get($this, 3)), bin2hex(self::bytes(TextPackets::jukeboxPopup("disc", ["c"]))));
	}

	public function testVanillaCraftingDataRoundTrips() : void{
		Pulse::reset();
		PulseZones::init();
		$bytes = CraftingDataCache::getInstance()->getCache(CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES))->getBytes();
		ProtocolClasses::bind();
		$packet = Protocol::decode($bytes);
		self::assertInstanceOf(packet\CraftingDataPacket::class, $packet);
		$descriptors = [];
		foreach($packet->shapedRecipes as $recipe){
			foreach($recipe->ingredients as $ingredient){
				$descriptors[$ingredient->descriptor::class] = true;
			}
		}
		self::assertArrayHasKey(DescriptorEmpty::class, $descriptors);
		self::assertArrayHasKey(DescriptorItem::class, $descriptors);
		self::assertSame(bin2hex($bytes), bin2hex(Protocol::encode($packet->getId(), $packet)));
	}

	/**
	 * @return \Generator<string, array{ItemStackWrapper}>
	 */
	public static function itemProvider() : \Generator{
		$converter = TypeConverter::getInstance();
		$sword = VanillaItems::DIAMOND_SWORD()->setCustomName("named")->setLore(["a", "b"]);
		$sword->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2));
		yield "air" => [ItemStackWrapper::legacy(ItemStack::null())];
		yield "plain" => [new ItemStackWrapper(0, $converter->coreItemStackToNet(VanillaItems::APPLE()->setCount(12)))];
		yield "stack id" => [new ItemStackWrapper(-123, $converter->coreItemStackToNet($sword))];
		yield "block" => [new ItemStackWrapper(9, $converter->coreItemStackToNet(\quark\block\VanillaBlocks::OAK_LOG()->asItem()))];
		yield "totem" => [new ItemStackWrapper(1, $converter->coreItemStackToNet(VanillaItems::TOTEM()))];
	}

	/**
	 * @dataProvider itemProvider
	 */
	public function testItemsMatchRecordedEncoding(ItemStackWrapper $item) : void{
		$expected = RecordedPackets::get($this, 0);
		$actual = self::bytes(new MobEquipmentPacket(
			targetRuntimeId: 5,
			item: Fields::itemStackWrapper($item),
			slot: 1,
			selectedSlot: 2,
			containerId: 0,
		));
		self::assertSame(bin2hex($expected), bin2hex($actual));

		$decoded = EncodedPacket::fromBytes($actual)->getPacket();
		self::assertInstanceOf(MobEquipmentPacket::class, $decoded);
		self::assertEquals($item, Fields::toItemStackWrapper($decoded->item));
	}

	public function testSkinsMatchRecordedEncoding() : void{
		$image = new SkinImage(64, 64, str_repeat("\x7f", 64 * 64 * 4));
		$skin = new SkinData(
			"skin id",
			"playfab",
			'{"geometry":{"default":"geometry.humanoid.custom"}}',
			$image,
			[new SkinAnimation(new SkinImage(32, 32, str_repeat("\x01", 32 * 32 * 4)), SkinAnimation::TYPE_HEAD, 2.5, SkinAnimation::EXPRESSION_BLINKING)],
			new SkinImage(32, 64, str_repeat("\x02", 32 * 64 * 4)),
			"{}",
			"1.20.0",
			"animation data",
			"cape id",
			"full skin id",
			SkinArmSizeType::SLIM,
			new Color(10, 20, 30, 255),
			[new PersonaSkinPiece("piece", PersonaSkinPieceType::HAIR, Uuid::fromString("0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0"), true, "product")],
			[new PersonaPieceTintColor(PersonaSkinPieceType::HAIR, [new Color(255, 0, 0), new Color(0, 255, 0), new Color(0, 0, 255), new Color(1, 2, 3, 4)])],
			SkinData::TRUSTED_SKIN_TRUE,
			true,
			true,
			false,
			true,
			false,
			"hash"
		);
		$uuid = Uuid::fromString("01234567-89ab-cdef-0123-456789abcdef");
		$expected = RecordedPackets::get($this, 0);
		$actual = self::bytes(new PlayerSkinPacket(
			uuid: Fields::uuid($uuid),
			serializedSkin: Fields::skin($skin),
			localizedNewSkinName: "new",
			localizedOldSkinName: "old",
		));
		self::assertSame(bin2hex($expected), bin2hex($actual));

		$decoded = EncodedPacket::fromBytes($actual)->getPacket();
		self::assertInstanceOf(PlayerSkinPacket::class, $decoded);
		self::assertEquals($skin, Fields::toSkinData($decoded->serializedSkin));
		self::assertTrue(Fields::toUuid($decoded->uuid)->equals($uuid));

		$expected = RecordedPackets::get($this, 1);
		$actual = self::bytes(new PlayerListPacket(entries: [
			Fields::playerListAddition($uuid, -9, "Steve", $skin, "1234"),
			Fields::playerListRemoval($uuid),
		]));
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}

	public function testEnchantOptionsMatchRecordedEncoding() : void{
		$expected = RecordedPackets::get($this, 0);
		$actual = self::bytes(new PlayerEnchantOptionsPacket(options: [new ItemEnchantOption(
			cost: 30,
			enchants: new ItemEnchants(slot: 0, itemEnchants: [[
				new NetEnchantmentInstance(9, 3),
				new NetEnchantmentInstance(17, 1),
			], [], []]),
			enchantName: "fire",
			enchantNetId: 77,
		)]));
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}

	public function testAbilitiesMatchRecordedEncoding() : void{
		$data = new AbilitiesData(CommandPermissions::OPERATOR, PlayerPermissions::OPERATOR, -42, [
			new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, [
				AbilitiesLayer::ABILITY_ALLOW_FLIGHT => true,
				AbilitiesLayer::ABILITY_FLYING => false,
				AbilitiesLayer::ABILITY_PRIVILEGED_BUILDER => true,
			], 0.05, 1.0, 0.1),
			new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [AbilitiesLayer::ABILITY_FLYING => true], null, null, null),
		]);
		$expected = RecordedPackets::get($this, 0);
		$actual = self::bytes(new UpdateAbilitiesPacket(data: Fields::abilities($data)));
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}
}
