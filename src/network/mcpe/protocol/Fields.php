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

use pocketmine\color\Color;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinArmSizeType;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage as ProtocolSkinImage;
use pocketmine\utils\Binary;
use quark\network\mcpe\protocol\types\NetworkItemInstanceDescriptor as NetItemInstance;
use quark\network\mcpe\protocol\types\NetworkItemStackDescriptor as NetItemStack;
use quark\network\mcpe\protocol\types\SerializedSkinRef as NetSkinRef;
use quark\network\mcpe\protocol\types\SkinImage as NetSkinImage;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function strlen;

/**
 * Converts game values and the protocol value objects gameplay works with to and from the packet field shapes in
 * Packets. These only rearrange values, the bytes are always written by Bedwire\Protocol.
 *
 * @phpstan-import-type Vec3f from Packets
 * @phpstan-import-type Vec2f from Packets
 * @phpstan-import-type BlockPosition from Packets
 * @phpstan-import-type NetworkItemStackDescriptor from Packets
 * @phpstan-import-type NetworkItemInstanceDescriptor from Packets
 * @phpstan-import-type SkinImage from Packets
 * @phpstan-import-type SerializedSkinRef from Packets
 * @phpstan-import-type AbilityData from Packets
 * @phpstan-import-type PlayerListAddEntry from Packets
 * @phpstan-import-type PlayerListRemoveEntry from Packets
 * @phpstan-import-type RecipeIngredientRecipeIngredient from Packets
 */
final class Fields{
	//ability bits for the speeds a layer sets, private in AbilitiesLayer
	private const ABILITY_FLY_SPEED = 13;
	private const ABILITY_WALK_SPEED = 14;

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return Vec3f
	 */
	public static function vec3(Vector3 $vector) : array{
		return ["x" => (float) $vector->x, "y" => (float) $vector->y, "z" => (float) $vector->z];
	}

	/**
	 * @phpstan-return Vec2f
	 */
	public static function vec2(float $x, float $y) : array{
		return ["x" => $x, "y" => $y];
	}

	/**
	 * Packs an angle in degrees into the byte movement packets carry, wrapping like the angle does.
	 */
	public static function rotationByte(float $degrees) : int{
		return ((int) ($degrees / (360 / 256))) & 0xff;
	}

	/**
	 * @phpstan-return BlockPosition
	 */
	public static function blockPosition(Vector3 $vector) : array{
		return ["x" => $vector->getFloorX(), "y" => $vector->getFloorY(), "z" => $vector->getFloorZ()];
	}

	/**
	 * UUID fields hold the canonical bytes, protocol-zig swaps each half into the order Bedrock writes.
	 */
	public static function uuid(UuidInterface $uuid) : string{
		return $uuid->getBytes();
	}

	public static function toUuid(string $bytes) : UuidInterface{
		if(strlen($bytes) !== 16){
			throw new \InvalidArgumentException("A UUID is 16 bytes, got " . strlen($bytes));
		}
		return Uuid::fromBytes($bytes);
	}

	/**
	 * A stack ID of zero means the item has none.
	 *
	 * @phpstan-return NetworkItemStackDescriptor
	 */
	public static function itemStack(ItemStack $item, int $stackId = 0) : array{
		return [
			"id" => $item->getId(),
			"stack_size" => $item->getCount(),
			"aux_value" => $item->getMeta(),
			"net_id_variant" => $stackId !== 0 ? $stackId : null,
			"block_runtime_id" => $item->getBlockRuntimeId(),
			"user_data_buffer" => $item->getRawExtraData(),
		];
	}

	/**
	 * An item outside the item stack request system, which clients expect to carry stack ID 1 unless it is empty.
	 *
	 * @phpstan-return NetworkItemStackDescriptor
	 */
	public static function legacyItemStack(ItemStack $item) : array{
		return self::itemStack($item, $item->getId() === 0 ? 0 : 1);
	}

	/**
	 * @phpstan-return NetworkItemStackDescriptor
	 */
	public static function itemStackWrapper(ItemStackWrapper $wrapper) : array{
		return self::itemStack($wrapper->getItemStack(), $wrapper->getStackId());
	}

	public static function toItemStackWrapper(NetItemStack $item) : ItemStackWrapper{
		return new ItemStackWrapper($item->netIdVariant ?? 0, self::toItemStack($item));
	}

	/**
	 * @phpstan-return NetworkItemInstanceDescriptor
	 */
	public static function itemInstance(ItemStack $item) : array{
		return [
			"id" => $item->getId(),
			"stack_size" => $item->getCount(),
			"aux_value" => $item->getMeta(),
			"block_runtime_id" => $item->getBlockRuntimeId(),
			"user_data_buffer" => $item->getRawExtraData(),
		];
	}

	public static function toItemStack(NetItemStack|NetItemInstance $item) : ItemStack{
		return new ItemStack($item->id, $item->auxValue, $item->stackSize, $item->blockRuntimeId, $item->userDataBuffer);
	}

	/**
	 * A recipe ingredient as crafting data lists it.
	 *
	 * @phpstan-return RecipeIngredientRecipeIngredient
	 */
	public static function recipeIngredient(RecipeIngredient $ingredient) : array{
		$descriptor = $ingredient->getDescriptor();
		return [
			"descriptor" => match(true){
				$descriptor === null => ["empty" => ["aux" => 0x7fff]],
				$descriptor instanceof StringIdMetaItemDescriptor => ["item" => ["name" => $descriptor->getId(), "aux" => $descriptor->getMeta()]],
				$descriptor instanceof TagItemDescriptor => ["item_tag" => ["tag" => $descriptor->getTag(), "aux" => $descriptor->getMeta()]],
				$descriptor instanceof MolangItemDescriptor => ["molang" => ["expression" => $descriptor->getMolangExpression(), "version" => $descriptor->getMolangVersion()]],
			},
			"count" => $ingredient->getCount(),
		];
	}

	/**
	 * @phpstan-return SkinImage
	 */
	private static function skinImage(ProtocolSkinImage $image) : array{
		return ["width" => $image->getWidth(), "height" => $image->getHeight(), "image_bytes" => $image->getData()];
	}

	private static function toSkinImage(NetSkinImage $image) : ProtocolSkinImage{
		return new ProtocolSkinImage($image->height, $image->width, $image->imageBytes);
	}

	/**
	 * @phpstan-return SerializedSkinRef
	 */
	public static function skin(SkinData $skin) : array{
		$animations = [];
		foreach($skin->getAnimations() as $animation){
			$animations[] = [
				"skin_image" => self::skinImage($animation->getImage()),
				"animated_texture_type" => $animation->getType(),
				"frames" => $animation->getFrames(),
				"animation_expression" => $animation->getExpressionType(),
			];
		}
		$pieces = [];
		foreach($skin->getPersonaPieces() as $piece){
			$pieces[] = [
				"piece_id" => $piece->getPieceId(),
				"piece_type" => $piece->getPieceType()->toOrdinal(),
				"pack_id" => self::uuid($piece->getPackId()),
				"is_default_piece" => $piece->isDefaultPiece(),
				"product_id" => $piece->getProductId(),
			];
		}
		$tints = [];
		foreach($skin->getPieceTintColors() as $tint){
			$colors = [];
			foreach($tint->getColors() as $color){
				$colors[] = Binary::signInt($color->toARGB());
			}
			$tints[] = ["key" => $tint->getPieceType()->value, "value" => $colors];
		}
		return [
			"id" => $skin->getSkinId(),
			"play_fab_id" => $skin->getPlayFabId(),
			"resource_patch" => $skin->getResourcePatch(),
			"image_data" => self::skinImage($skin->getSkinImage()),
			"animated_image_data" => $animations,
			"cape_image_data" => self::skinImage($skin->getCapeImage()),
			"geometry_data" => $skin->getGeometryDataJson(),
			"geometry_data_min_engine_version" => $skin->getGeometryDataEngineVersion(),
			"animation_data" => $skin->getAnimationData(),
			"cape_id" => $skin->getCapeId(),
			"full_id" => $skin->getFullSkinId(),
			"arm_size" => $skin->getArmSize()->toOrdinal(),
			"skin_color" => Binary::signInt($skin->getSkinColor()->toARGB()),
			"persona_pieces" => $pieces,
			"piece_tint_colors" => $tints,
			"is_premium" => $skin->isPremium(),
			"is_persona" => $skin->isPersona(),
			"is_persona_cape_on_classic_skin" => $skin->isPersonaCapeOnClassic(),
			"is_primary_user" => $skin->isPrimaryUser(),
			"overrides_player_appearance" => $skin->isOverride(),
			"trusted_skin_flag" => $skin->getTrustedSkinFlag(),
			"profile_hash" => $skin->getProfileHash(),
		];
	}

	/**
	 * @throws \InvalidArgumentException if the skin is not one the protocol objects can represent
	 */
	public static function toSkinData(NetSkinRef $skin) : SkinData{
		$animations = [];
		foreach($skin->animatedImageData as $animation){
			$animations[] = new SkinAnimation(self::toSkinImage($animation->skinImage), $animation->animatedTextureType, $animation->frames, $animation->animationExpression);
		}
		$pieces = [];
		foreach($skin->personaPieces as $piece){
			$pieces[] = new PersonaSkinPiece($piece->pieceId, PersonaSkinPieceType::fromOrdinal($piece->pieceType), self::toUuid($piece->packId), $piece->isDefaultPiece, $piece->productId);
		}
		$tints = [];
		foreach($skin->pieceTintColors as $tint){
			[$a, $b, $c, $d] = $tint->value;
			$tints[] = new PersonaPieceTintColor(PersonaSkinPieceType::fromPacket($tint->key), [
				Color::fromARGB($a & 0xffffffff),
				Color::fromARGB($b & 0xffffffff),
				Color::fromARGB($c & 0xffffffff),
				Color::fromARGB($d & 0xffffffff),
			]);
		}
		return new SkinData(
			$skin->id,
			$skin->playFabId,
			$skin->resourcePatch,
			self::toSkinImage($skin->imageData),
			$animations,
			self::toSkinImage($skin->capeImageData),
			$skin->geometryData,
			$skin->geometryDataMinEngineVersion,
			$skin->animationData,
			$skin->capeId,
			$skin->fullId,
			SkinArmSizeType::fromOrdinal($skin->armSize),
			Color::fromARGB($skin->skinColor & 0xffffffff),
			$pieces,
			$tints,
			$skin->trustedSkinFlag,
			$skin->isPremium,
			$skin->isPersona,
			$skin->isPersonaCapeOnClassicSkin,
			$skin->isPrimaryUser,
			$skin->overridesPlayerAppearance,
			$skin->profileHash
		);
	}

	/**
	 * @phpstan-return array{add: PlayerListAddEntry}
	 */
	public static function playerListAddition(UuidInterface $uuid, int $actorUniqueId, string $username, SkinData $skin, string $xboxUserId = "") : array{
		return ["add" => [
			"action" => 0,
			"uuid" => self::uuid($uuid),
			"actor_unique_id" => $actorUniqueId,
			"player_name" => $username,
			"xbl_xuid" => $xboxUserId,
			"platform_online_id" => "",
			"build_platform" => -1,
			"serialized_skin" => self::skin($skin),
			"is_teacher" => false,
			"is_host" => false,
			"is_sub_client" => false,
			"player_color" => -1, //white
		]];
	}

	/**
	 * @phpstan-return array{remove: PlayerListRemoveEntry}
	 */
	public static function playerListRemoval(UuidInterface $uuid) : array{
		return ["remove" => ["action" => 1, "uuid" => self::uuid($uuid)]];
	}

	/**
	 * @phpstan-return AbilityData
	 */
	public static function abilities(AbilitiesData $data) : array{
		$layers = [];
		foreach($data->getAbilityLayers() as $layer){
			$set = 0;
			$values = 0;
			foreach($layer->getBoolAbilities() as $ability => $value){
				$set |= 1 << $ability;
				$values |= $value ? 1 << $ability : 0;
			}
			if($layer->getFlySpeed() !== null){
				$set |= 1 << self::ABILITY_FLY_SPEED;
			}
			if($layer->getVerticalFlySpeed() !== null){
				$set |= 1 << AbilitiesLayer::ABILITY_VERTICAL_FLY_SPEED;
			}
			if($layer->getWalkSpeed() !== null){
				$set |= 1 << self::ABILITY_WALK_SPEED;
			}
			$layers[] = [
				"serialized_layer" => $layer->getLayerId(),
				"abilities_set" => $set,
				"ability_values" => $values,
				"fly_speed" => $layer->getFlySpeed() ?? 0.0,
				"vertical_fly_speed" => $layer->getVerticalFlySpeed() ?? 0.0,
				"walk_speed" => $layer->getWalkSpeed() ?? 0.0,
			];
		}
		return [
			"target_player_raw_id" => $data->getTargetActorUniqueId(),
			"player_permissions" => $data->getPlayerPermission()->value,
			"command_permissions" => $data->getCommandPermission()->toOrdinal(),
			"layers" => $layers,
		];
	}
}
