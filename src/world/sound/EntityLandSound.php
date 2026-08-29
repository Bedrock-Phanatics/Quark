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

use quark\block\Block;
use quark\entity\Entity;
use pocketmine\math\Vector3;
use quark\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Played when an entity hits the ground after falling a distance that doesn't cause damage, e.g. due to jumping.
 */
class EntityLandSound implements Sound{
	public function __construct(
		private Entity $entity,
		private Block $blockLandedOn
	){}

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::create(
			LevelSoundEvent::LAND,
			$pos,
			TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->blockLandedOn->getStateId()),
			$this->entity::getNetworkTypeId(),
			false, //TODO: does isBaby have any relevance here?
			false,
			$this->entity->getId(),
			null
		)];
	}
}
