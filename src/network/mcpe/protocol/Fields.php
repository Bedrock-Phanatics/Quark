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
use pocketmine\utils\Binary;
use quark\network\mcpe\protocol\types\AbilitiesData;
use quark\network\mcpe\protocol\types\AbilitiesLayer;
use quark\network\mcpe\protocol\types\AbilityData;
use quark\network\mcpe\protocol\types\AnimatedImageData;
use quark\network\mcpe\protocol\types\DescriptorEmpty;
use quark\network\mcpe\protocol\types\DescriptorItem;
use quark\network\mcpe\protocol\types\DescriptorItemTag;
use quark\network\mcpe\protocol\types\DescriptorMolang;
use quark\network\mcpe\protocol\types\inventory\ItemStack;
use quark\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use quark\network\mcpe\protocol\types\NetworkItemInstanceDescriptor as NetItemInstance;
use quark\network\mcpe\protocol\types\NetworkItemStackDescriptor as NetItemStack;
use quark\network\mcpe\protocol\types\PersonaPiece;
use quark\network\mcpe\protocol\types\PlayerListAddEntry;
use quark\network\mcpe\protocol\types\PlayerListRemoveEntry;
use quark\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use quark\network\mcpe\protocol\types\recipe\RecipeIngredient;
use quark\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use quark\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use quark\network\mcpe\protocol\types\RecipeIngredientRecipeIngredient as NetRecipeIngredient;
use quark\network\mcpe\protocol\types\SerializedAbilitiesDataSerializedLayer;
use quark\network\mcpe\protocol\types\SerializedSkinRef as NetSkinRef;
use quark\network\mcpe\protocol\types\SerializedSkinRefPieceTintColorsEntry;
use quark\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use quark\network\mcpe\protocol\types\skin\PersonaSkinPieceType;
use quark\network\mcpe\protocol\types\skin\SkinAnimation;
use quark\network\mcpe\protocol\types\skin\SkinArmSizeType;
use quark\network\mcpe\protocol\types\skin\SkinData;
use quark\network\mcpe\protocol\types\skin\SkinImage as ProtocolSkinImage;
use quark\network\mcpe\protocol\types\SkinImage as NetSkinImage;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function strlen;

/**
 * Converts the protocol value objects gameplay works with to and from the generated packet field classes.
 */
final class Fields{
	private function __construct(){
		//NOOP
	}

	/**
	 * Packs an angle in degrees into the byte movement packets carry, wrapping like the angle does.
	 */
	public static function rotationByte(float $degrees) : int{
		return ((int) ($degrees / (360 / 256))) & 0xff;
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
	 */
	public static function itemStack(ItemStack $item, int $stackId = 0) : NetItemStack{
		return new NetItemStack($item->getId(), $item->getCount(), $item->getMeta(), $stackId !== 0 ? $stackId : null, $item->getBlockRuntimeId(), $item->getRawExtraData());
	}

	/**
	 * An item outside the item stack request system, which clients expect to carry stack ID 1 unless it is empty.
	 */
	public static function legacyItemStack(ItemStack $item) : NetItemStack{
		return self::itemStack($item, $item->getId() === 0 ? 0 : 1);
	}

	public static function itemStackWrapper(ItemStackWrapper $wrapper) : NetItemStack{
		return self::itemStack($wrapper->getItemStack(), $wrapper->getStackId());
	}

	public static function toItemStackWrapper(NetItemStack $item) : ItemStackWrapper{
		return new ItemStackWrapper($item->netIdVariant ?? 0, self::toItemStack($item));
	}

	public static function itemInstance(ItemStack $item) : NetItemInstance{
		return new NetItemInstance($item->getId(), $item->getCount(), $item->getMeta(), $item->getBlockRuntimeId(), $item->getRawExtraData());
	}

	public static function toItemStack(NetItemStack|NetItemInstance $item) : ItemStack{
		return new ItemStack($item->id, $item->auxValue, $item->stackSize, $item->blockRuntimeId, $item->userDataBuffer);
	}

	public static function recipeIngredient(RecipeIngredient $ingredient) : NetRecipeIngredient{
		$descriptor = $ingredient->getDescriptor();
		return new NetRecipeIngredient(match(true){
			$descriptor === null => new DescriptorEmpty(0x7fff),
			$descriptor instanceof StringIdMetaItemDescriptor => new DescriptorItem($descriptor->getId(), $descriptor->getMeta()),
			$descriptor instanceof TagItemDescriptor => new DescriptorItemTag($descriptor->getTag(), $descriptor->getMeta()),
			$descriptor instanceof MolangItemDescriptor => new DescriptorMolang($descriptor->getMolangExpression(), $descriptor->getMolangVersion()),
		}, $ingredient->getCount());
	}

	private static function skinImage(ProtocolSkinImage $image) : NetSkinImage{
		return new NetSkinImage($image->getWidth(), $image->getHeight(), $image->getData());
	}

	private static function toSkinImage(NetSkinImage $image) : ProtocolSkinImage{
		return new ProtocolSkinImage($image->height, $image->width, $image->imageBytes);
	}

	public static function skin(SkinData $skin) : NetSkinRef{
		$animations = [];
		foreach($skin->getAnimations() as $animation){
			$animations[] = new AnimatedImageData(self::skinImage($animation->getImage()), $animation->getType(), $animation->getFrames(), $animation->getExpressionType());
		}
		$pieces = [];
		foreach($skin->getPersonaPieces() as $piece){
			$pieces[] = new PersonaPiece($piece->getPieceId(), $piece->getPieceType()->toOrdinal(), self::uuid($piece->getPackId()), $piece->isDefaultPiece(), $piece->getProductId());
		}
		$tints = [];
		foreach($skin->getPieceTintColors() as $tint){
			$colors = [];
			foreach($tint->getColors() as $color){
				$colors[] = Binary::signInt($color->toARGB());
			}
			$tints[] = new SerializedSkinRefPieceTintColorsEntry($tint->getPieceType()->value, $colors);
		}
		return new NetSkinRef(
			$skin->getSkinId(),
			$skin->getPlayFabId(),
			$skin->getResourcePatch(),
			self::skinImage($skin->getSkinImage()),
			$animations,
			self::skinImage($skin->getCapeImage()),
			$skin->getGeometryDataJson(),
			$skin->getGeometryDataEngineVersion(),
			$skin->getAnimationData(),
			$skin->getCapeId(),
			$skin->getFullSkinId(),
			$skin->getArmSize()->toOrdinal(),
			Binary::signInt($skin->getSkinColor()->toARGB()),
			$pieces,
			$tints,
			$skin->isPremium(),
			$skin->isPersona(),
			$skin->isPersonaCapeOnClassic(),
			$skin->isPrimaryUser(),
			$skin->isOverride(),
			$skin->getTrustedSkinFlag(),
			$skin->getProfileHash()
		);
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

	public static function playerListAddition(UuidInterface $uuid, int $actorUniqueId, string $username, SkinData $skin, string $xboxUserId = "") : PlayerListAddEntry{
		return new PlayerListAddEntry(
			action: 0,
			uuid: self::uuid($uuid),
			actorUniqueId: $actorUniqueId,
			playerName: $username,
			xblXuid: $xboxUserId,
			platformOnlineId: "",
			buildPlatform: -1,
			serializedSkin: self::skin($skin),
			isTeacher: false,
			isHost: false,
			isSubClient: false,
			playerColor: -1, //white
		);
	}

	public static function playerListRemoval(UuidInterface $uuid) : PlayerListRemoveEntry{
		return new PlayerListRemoveEntry(1, self::uuid($uuid));
	}

	public static function abilities(AbilitiesData $data) : AbilityData{
		$layers = [];
		foreach($data->getAbilityLayers() as $layer){
			$set = 0;
			$values = 0;
			foreach($layer->getBoolAbilities() as $ability => $value){
				$set |= 1 << $ability;
				$values |= $value ? 1 << $ability : 0;
			}
			if($layer->getFlySpeed() !== null){
				$set |= 1 << AbilitiesLayer::ABILITY_FLY_SPEED;
			}
			if($layer->getVerticalFlySpeed() !== null){
				$set |= 1 << AbilitiesLayer::ABILITY_VERTICAL_FLY_SPEED;
			}
			if($layer->getWalkSpeed() !== null){
				$set |= 1 << AbilitiesLayer::ABILITY_WALK_SPEED;
			}
			$layers[] = new SerializedAbilitiesDataSerializedLayer(
				$layer->getLayerId(),
				$set,
				$values,
				$layer->getFlySpeed() ?? 0.0,
				$layer->getVerticalFlySpeed() ?? 0.0,
				$layer->getWalkSpeed() ?? 0.0
			);
		}
		return new AbilityData($data->getTargetActorUniqueId(), $data->getPlayerPermission()->value, $data->getCommandPermission()->toOrdinal(), $layers);
	}
}
