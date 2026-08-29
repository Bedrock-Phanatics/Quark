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

use quark\entity\Attribute;
use quark\entity\effect\EffectInstance;
use quark\entity\Entity;
use quark\entity\Human;
use quark\entity\Living;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;

/**
 * This class allows broadcasting entity events to many viewers on the server network.
 */
interface EntityEventBroadcaster{

	/**
	 * @param NetworkSession[] $recipients
	 * @param Attribute[]      $attributes
	 */
	public function syncAttributes(array $recipients, Living $entity, array $attributes) : void;

	/**
	 * @param NetworkSession[]   $recipients
	 * @param MetadataProperty[] $properties
	 *
	 * @phpstan-param array<int, MetadataProperty> $properties
	 */
	public function syncActorData(array $recipients, Entity $entity, array $properties) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onEntityEffectAdded(array $recipients, Living $entity, EffectInstance $effect, bool $replacesOldEffect) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onEntityEffectRemoved(array $recipients, Living $entity, EffectInstance $effect) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onEntityRemoved(array $recipients, Entity $entity) : void;

	/**
	 * TODO: expand this to more than just humans
	 *
	 * @param NetworkSession[] $recipients
	 */
	public function onMobMainHandItemChange(array $recipients,Human $mob) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onMobOffHandItemChange(array $recipients, Human $mob) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onMobArmorChange(array $recipients, Living $mob) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onPickUpItem(array $recipients, Entity $collector, Entity $pickedUp) : void;

	/**
	 * @param NetworkSession[] $recipients
	 */
	public function onEmote(array $recipients, Human $from, string $emoteId) : void;
}
