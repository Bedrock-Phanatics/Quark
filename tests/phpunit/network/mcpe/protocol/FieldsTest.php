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
use pmmp\encoding\ByteBufferWriter;
use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;
use pocketmine\network\mcpe\protocol\PlayerEnchantOptionsPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\Enchant;
use pocketmine\network\mcpe\protocol\types\EnchantOption;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinArmSizeType;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use Ramsey\Uuid\Uuid;
use function bin2hex;
use function str_repeat;
use function strlen;

/**
 * Checks that values built for Bedwire\Protocol encode exactly like BedrockProtocol's encoders did, and read back.
 *
 * @phpstan-import-type MobEquipmentFields from Packets
 * @phpstan-import-type PlayerSkinFields from Packets
 */
final class FieldsTest extends TestCase{

	private static function legacy(ClientboundPacket $packet) : string{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		return $writer->getData();
	}

	public function testVectorsAndUuids() : void{
		$vector = new Vector3(1, -2.5, 3.25);
		self::assertSame(["x" => 1.0, "y" => -2.5, "z" => 3.25], Fields::vec3($vector));
		self::assertTrue(Fields::toVector3(Fields::vec3($vector))->equals($vector));
		self::assertSame(["x" => 1, "y" => -3, "z" => 3], Fields::blockPosition($vector));
		$uuid = Uuid::fromString("0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0");
		self::assertSame(16, strlen(Fields::uuid($uuid)));
		self::assertTrue(Fields::toUuid(Fields::uuid($uuid))->equals($uuid));
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
	public function testItemsMatchBedrockProtocol(ItemStackWrapper $item) : void{
		$expected = self::legacy(MobEquipmentPacket::create(5, $item, 1, 2, 0));
		$actual = Packets::mobEquipment([
			"target_runtime_id" => 5,
			"item" => Fields::itemStackWrapper($item),
			"slot" => 1,
			"selected_slot" => 2,
			"container_id" => 0,
		])->getEncoded();
		self::assertSame(bin2hex($expected), bin2hex($actual));

		/** @phpstan-var MobEquipmentFields $decoded */
		$decoded = Protocol::decode($actual);
		self::assertEquals($item, Fields::toItemStackWrapper($decoded["item"]));
		self::assertEquals($item->getItemStack(), Fields::toItemStack(Fields::itemInstance($item->getItemStack())));
	}

	public function testSkinsMatchBedrockProtocol() : void{
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
		$expected = self::legacy(PlayerSkinPacket::create($uuid, "old", "new", $skin));
		$actual = Packets::playerSkin([
			"uuid" => Fields::uuid($uuid),
			"serialized_skin" => Fields::skin($skin),
			"localized_new_skin_name" => "new",
			"localized_old_skin_name" => "old",
		])->getEncoded();
		self::assertSame(bin2hex($expected), bin2hex($actual));

		/** @phpstan-var PlayerSkinFields $decoded */
		$decoded = Protocol::decode($actual);
		self::assertEquals($skin, Fields::toSkinData($decoded["serialized_skin"]));
		self::assertTrue(Fields::toUuid($decoded["uuid"])->equals($uuid));

		$expected = self::legacy(PlayerListPacket::create([
			PlayerListEntry::createAdditionEntry($uuid, -9, "Steve", $skin, "1234"),
			PlayerListEntry::createRemovalEntry($uuid),
		]));
		$actual = Packets::playerList(["entries" => [
			Fields::playerListAddition($uuid, -9, "Steve", $skin, "1234"),
			Fields::playerListRemoval($uuid),
		]])->getEncoded();
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}

	public function testEnchantOptionsMatchBedrockProtocol() : void{
		$expected = self::legacy(PlayerEnchantOptionsPacket::create([
			new EnchantOption(30, 0, [new Enchant(9, 3), new Enchant(17, 1)], [], [], "fire", 77),
		]));
		$actual = Packets::playerEnchantOptions(["options" => [[
			"cost" => 30,
			"enchants" => ["slot" => 0, "item_enchants" => [[
				["enchant_type" => 9, "enchant_level" => 3],
				["enchant_type" => 17, "enchant_level" => 1],
			], [], []]],
			"enchant_name" => "fire",
			"enchant_net_id" => 77,
		]]])->getEncoded();
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}

	public function testAbilitiesMatchBedrockProtocol() : void{
		$data = new AbilitiesData(CommandPermissions::OPERATOR, PlayerPermissions::OPERATOR, -42, [
			new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, [
				AbilitiesLayer::ABILITY_ALLOW_FLIGHT => true,
				AbilitiesLayer::ABILITY_FLYING => false,
				AbilitiesLayer::ABILITY_PRIVILEGED_BUILDER => true,
			], 0.05, 1.0, 0.1),
			new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [AbilitiesLayer::ABILITY_FLYING => true], null, null, null),
		]);
		$expected = self::legacy(UpdateAbilitiesPacket::create($data));
		$actual = Packets::updateAbilities(["data" => Fields::abilities($data)])->getEncoded();
		self::assertSame(bin2hex($expected), bin2hex($actual));
	}
}
