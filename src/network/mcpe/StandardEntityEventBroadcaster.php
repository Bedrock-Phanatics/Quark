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

use pocketmine\network\mcpe\protocol\EmotePacket;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
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
use quark\network\mcpe\protocol\Packets;
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
				$list[] = [
					"min_value" => $attribute->getMinValue(),
					"max_value" => $attribute->getMaxValue(),
					"current_value" => $attribute->getValue(),
					"default_min_value" => $attribute->getMinValue(),
					"default_max_value" => $attribute->getMaxValue(),
					"default_value" => $attribute->getDefaultValue(),
					"name" => $attribute->getId(),
					"modifiers" => [],
				];
			}
			$this->sendDataPacket($recipients, Packets::updateAttributes(["target_runtime_id" => $entity->getId(), "attribute_list" => $list, "tick" => 0]));
		}
	}

	public function syncActorData(array $recipients, Entity $entity, array $properties) : void{
		//TODO: HACK! as of 1.18.10, the client responds differently to the same data ordered in different orders - for
		//example, sending HEIGHT in the list before FLAGS when unsetting the SWIMMING flag results in a hitbox glitch
		usort($properties, fn(array $a, array $b) : int => $a["id"] <=> $b["id"]);
		$this->sendDataPacket($recipients, Packets::setActorData([
			"target_runtime_id" => $entity->getId(),
			"actor_data" => $properties,
			"synched_properties" => ["int_entries_list" => [], "float_entries_list" => []],
			"tick" => 0,
		]));
	}

	public function onEntityEffectAdded(array $recipients, Living $entity, EffectInstance $effect, bool $replacesOldEffect) : void{
		//TODO: we may need yet another effect <=> ID map in the future depending on protocol changes
		$this->sendDataPacket($recipients, Packets::mobEffect([
			"target_runtime_id" => $entity->getId(),
			"event_id" => $replacesOldEffect ? MobEffectPacket::EVENT_MODIFY : MobEffectPacket::EVENT_ADD,
			"effect_id" => EffectIdMap::getInstance()->toId($effect->getType()),
			"effect_amplifier" => $effect->getAmplifier(),
			"show_particles" => $effect->isVisible(),
			"effect_duration_ticks" => $effect->isInfinite() ? -1 : $effect->getDuration(),
			"tick" => 0,
			"ambient" => $effect->isAmbient(),
		]));
	}

	public function onEntityEffectRemoved(array $recipients, Living $entity, EffectInstance $effect) : void{
		$this->sendDataPacket($recipients, Packets::mobEffect([
			"target_runtime_id" => $entity->getId(),
			"event_id" => MobEffectPacket::EVENT_REMOVE,
			"effect_id" => EffectIdMap::getInstance()->toId($effect->getType()),
			"effect_amplifier" => 0,
			"show_particles" => false,
			"effect_duration_ticks" => 0,
			"tick" => 0,
			"ambient" => false,
		]));
	}

	public function onEntityRemoved(array $recipients, Entity $entity) : void{
		$this->sendDataPacket($recipients, Packets::removeActor(["target_actor_id" => $entity->getId()]));
	}

	public function onMobMainHandItemChange(array $recipients, Human $mob) : void{
		//TODO: we could send zero for slot here because remote players don't need to know which slot was selected
		$inv = $mob->getInventory();
		$this->sendDataPacket($recipients, Packets::mobEquipment([
			"target_runtime_id" => $mob->getId(),
			"item" => Fields::legacyItemStack($this->typeConverter->coreItemStackToNet($inv->getItemInHand())),
			"slot" => $inv->getHeldItemIndex(),
			"selected_slot" => $inv->getHeldItemIndex(),
			"container_id" => ContainerIds::INVENTORY,
		]));
	}

	public function onMobOffHandItemChange(array $recipients, Human $mob) : void{
		$inv = $mob->getOffHandInventory();
		$this->sendDataPacket($recipients, Packets::mobEquipment([
			"target_runtime_id" => $mob->getId(),
			"item" => Fields::legacyItemStack($this->typeConverter->coreItemStackToNet($inv->getItem(0))),
			"slot" => 0,
			"selected_slot" => 0,
			"container_id" => ContainerIds::OFFHAND,
		]));
	}

	public function onMobArmorChange(array $recipients, Living $mob) : void{
		$inv = $mob->getArmorInventory();
		$converter = $this->typeConverter;
		$this->sendDataPacket($recipients, Packets::mobArmourEquipment([
			"target_runtime_id" => $mob->getId(),
			"head" => Fields::legacyItemStack($converter->coreItemStackToNet($inv->getHelmet())),
			"torso" => Fields::legacyItemStack($converter->coreItemStackToNet($inv->getChestplate())),
			"legs" => Fields::legacyItemStack($converter->coreItemStackToNet($inv->getLeggings())),
			"feet" => Fields::legacyItemStack($converter->coreItemStackToNet($inv->getBoots())),
			"body" => Fields::itemStack(ItemStack::null()),
		]));
	}

	public function onPickUpItem(array $recipients, Entity $collector, Entity $pickedUp) : void{
		$this->sendDataPacket($recipients, Packets::takeItemActor(["item_runtime_id" => $pickedUp->getId(), "actor_runtime_id" => $collector->getId()]));
	}

	public function onEmote(array $recipients, Human $from, string $emoteId) : void{
		$this->sendDataPacket($recipients, Packets::emote([
			"actor_runtime_id" => $from->getId(),
			"emote_id" => $emoteId,
			"emote_length_ticks" => 0, //seems to be irrelevant for the client, we cannot risk rebroadcasting random values received
			"xuid" => "",
			"platform_id" => "",
			"flags" => EmotePacket::FLAG_SERVER | EmotePacket::FLAG_MUTE_ANNOUNCEMENT,
		]));
	}
}
