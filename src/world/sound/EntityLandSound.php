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

namespace quark\world\sound;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use quark\block\Block;
use quark\entity\Entity;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\packet\LevelSoundEventPacket;

/**
 * Played when an entity hits the ground after falling a distance that doesn't cause damage, e.g. due to jumping.
 */
class EntityLandSound implements Sound{
	public function __construct(
		private Entity $entity,
		private Block $blockLandedOn
	){}

	public function encode(Vector3 $pos) : array{
		return [new LevelSoundEventPacket(
			soundEvent: LevelSoundEvent::LAND,
			position: $pos,
			data: TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->blockLandedOn->getStateId()),
			actorIdentifier: $this->entity::getNetworkTypeId(),
			isBaby: false, //TODO: does isBaby have any relevance here?
			isGlobal: false,
			actorUniqueId: $this->entity->getId(),
		)];
	}
}
