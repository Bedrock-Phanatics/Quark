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
use quark\entity\Entity;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packets;

/**
 * Played when an entity hits the ground after falling a short distance.
 */
class EntityShortFallSound implements Sound{
	public function __construct(private Entity $entity){}

	public function encode(Vector3 $pos) : array{
		return [Packets::levelSoundEvent([
			"sound_event" => LevelSoundEvent::FALL_SMALL,
			"position" => Fields::vec3($pos),
			"data" => -1,
			"actor_identifier" => $this->entity::getNetworkTypeId(),
			"is_baby" => false, //TODO: does isBaby have any relevance here?
			"is_global" => false,
			"actor_unique_id" => -1,
			"fire_at_position" => null,
		])];
	}
}
