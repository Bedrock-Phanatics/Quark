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
use quark\network\mcpe\protocol\packet\LevelSoundEventPacket;
use quark\network\mcpe\protocol\types\LevelSoundEvent;

/**
 * Played when a player attacks a mob, dealing damage.
 */
class EntityAttackSound implements Sound{

	public function encode(Vector3 $pos) : array{
		return [new LevelSoundEventPacket(
			soundEvent: LevelSoundEvent::ATTACK_STRONG, //TODO: seems like ATTACK is dysfunctional
			position: $pos,
			data: -1,
			actorIdentifier: "minecraft:player",
			isBaby: false,
			isGlobal: false,
			actorUniqueId: -1,
		)];
	}
}
