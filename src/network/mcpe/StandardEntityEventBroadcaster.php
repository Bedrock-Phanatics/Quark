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

namespace quark\network\mcpe;

use pocketmine\network\mcpe\protocol\EmotePacket as BedrockEmotePacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket as BedrockMobEffectPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use quark\data\bedrock\EffectIdMap;
use quark\entity\effect\EffectInstance;
use quark\entity\Entity;
use quark\entity\Human;
use quark\entity\Living;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\EmotePacket;
use quark\network\mcpe\protocol\packet\MobArmourEquipmentPacket;
use quark\network\mcpe\protocol\packet\MobEffectPacket;
use quark\network\mcpe\protocol\packet\MobEquipmentPacket;
use quark\network\mcpe\protocol\packet\RemoveActorPacket;
use quark\network\mcpe\protocol\packet\SetActorDataPacket;
use quark\network\mcpe\protocol\packet\TakeItemActorPacket;
use quark\network\mcpe\protocol\packet\UpdateAttributesPacket;
use quark\network\mcpe\protocol\types\AttributeData;
use quark\network\mcpe\protocol\types\DataItemEntry;
use quark\network\mcpe\protocol\types\PropertySyncData;
use function count;
use function usort;

final class StandardEntityEventBroadcaster implements EntityEventBroadcaster{

	public function __construct(
		private PacketBroadcaster $broadcaster,
		private TypeConverter $typeConverter
	){}

	/**
	 * @param NetworkSession[] $recipients
	 */
	private function sendDataPacket(array $recipients, Packet $packet) : void{
		$this->broadcaster->broadcastPackets($recipients, [$packet]);
	}

	public function syncAttributes(array $recipients, Living $entity, array $attributes) : void{
		if(count($attributes) > 0){
			$list = [];
			foreach($attributes as $attribute){
				$list[] = new AttributeData(
					minValue: $attribute->getMinValue(),
					maxValue: $attribute->getMaxValue(),
					currentValue: $attribute->getValue(),
					defaultMinValue: $attribute->getMinValue(),
					defaultMaxValue: $attribute->getMaxValue(),
					defaultValue: $attribute->getDefaultValue(),
					name: $attribute->getId(),
					modifiers: [],
				);
			}
			$this->sendDataPacket($recipients, new UpdateAttributesPacket(targetRuntimeId: $entity->getId(), attributeList: $list, tick: 0));
		}
	}

	public function syncActorData(array $recipients, Entity $entity, array $properties) : void{
		//TODO: HACK! as of 1.18.10, the client responds differently to the same data ordered in different orders - for
		//example, sending HEIGHT in the list before FLAGS when unsetting the SWIMMING flag results in a hitbox glitch
		usort($properties, fn(DataItemEntry $a, DataItemEntry $b) : int => $a->id <=> $b->id);
		$this->sendDataPacket($recipients, new SetActorDataPacket(
			targetRuntimeId: $entity->getId(),
			actorData: $properties,
			synchedProperties: new PropertySyncData(intEntriesList: [], floatEntriesList: []),
			tick: 0,
		));
	}

	public function onEntityEffectAdded(array $recipients, Living $entity, EffectInstance $effect, bool $replacesOldEffect) : void{
		//TODO: we may need yet another effect <=> ID map in the future depending on protocol changes
		$this->sendDataPacket($recipients, new MobEffectPacket(
			targetRuntimeId: $entity->getId(),
			eventId: $replacesOldEffect ? BedrockMobEffectPacket::EVENT_MODIFY : BedrockMobEffectPacket::EVENT_ADD,
			effectId: EffectIdMap::getInstance()->toId($effect->getType()),
			effectAmplifier: $effect->getAmplifier(),
			showParticles: $effect->isVisible(),
			effectDurationTicks: $effect->isInfinite() ? -1 : $effect->getDuration(),
			tick: 0,
			ambient: $effect->isAmbient(),
		));
	}

	public function onEntityEffectRemoved(array $recipients, Living $entity, EffectInstance $effect) : void{
		$this->sendDataPacket($recipients, new MobEffectPacket(
			targetRuntimeId: $entity->getId(),
			eventId: BedrockMobEffectPacket::EVENT_REMOVE,
			effectId: EffectIdMap::getInstance()->toId($effect->getType()),
			effectAmplifier: 0,
			showParticles: false,
			effectDurationTicks: 0,
			tick: 0,
			ambient: false,
		));
	}

	public function onEntityRemoved(array $recipients, Entity $entity) : void{
		$this->sendDataPacket($recipients, new RemoveActorPacket(targetActorId: $entity->getId()));
	}

	public function onMobMainHandItemChange(array $recipients, Human $mob) : void{
		//TODO: we could send zero for slot here because remote players don't need to know which slot was selected
		$inv = $mob->getInventory();
		$this->sendDataPacket($recipients, new MobEquipmentPacket(
			targetRuntimeId: $mob->getId(),
			item: Fields::legacyItemStack($this->typeConverter->coreItemStackToNet($inv->getItemInHand())),
			slot: $inv->getHeldItemIndex(),
			selectedSlot: $inv->getHeldItemIndex(),
			containerId: ContainerIds::INVENTORY,
		));
	}

	public function onMobOffHandItemChange(array $recipients, Human $mob) : void{
		$inv = $mob->getOffHandInventory();
		$this->sendDataPacket($recipients, new MobEquipmentPacket(
			targetRuntimeId: $mob->getId(),
			item: Fields::legacyItemStack($this->typeConverter->coreItemStackToNet($inv->getItem(0))),
			slot: 0,
			selectedSlot: 0,
			containerId: ContainerIds::OFFHAND,
		));
	}

	public function onMobArmorChange(array $recipients, Living $mob) : void{
		$inv = $mob->getArmorInventory();
		$converter = $this->typeConverter;
		$this->sendDataPacket($recipients, new MobArmourEquipmentPacket(
			targetRuntimeId: $mob->getId(),
			head: Fields::legacyItemStack($converter->coreItemStackToNet($inv->getHelmet())),
			torso: Fields::legacyItemStack($converter->coreItemStackToNet($inv->getChestplate())),
			legs: Fields::legacyItemStack($converter->coreItemStackToNet($inv->getLeggings())),
			feet: Fields::legacyItemStack($converter->coreItemStackToNet($inv->getBoots())),
			body: Fields::itemStack(ItemStack::null()),
		));
	}

	public function onPickUpItem(array $recipients, Entity $collector, Entity $pickedUp) : void{
		$this->sendDataPacket($recipients, new TakeItemActorPacket(itemRuntimeId: $pickedUp->getId(), actorRuntimeId: $collector->getId()));
	}

	public function onEmote(array $recipients, Human $from, string $emoteId) : void{
		$this->sendDataPacket($recipients, new EmotePacket(
			actorRuntimeId: $from->getId(),
			emoteId: $emoteId,
			emoteLengthTicks: 0, //seems to be irrelevant for the client, we cannot risk rebroadcasting random values received
			xuid: "",
			platformId: "",
			flags: BedrockEmotePacket::FLAG_SERVER | BedrockEmotePacket::FLAG_MUTE_ANNOUNCEMENT,
		));
	}
}
