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

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

/**
 * The world sound and event packets most sounds and particles are made of.
 */
final class LevelPackets{

	private function __construct(){
		//NOOP
	}

	/**
	 * A sound that is not tied to an entity.
	 *
	 * @param string $sound    a LevelSoundEvent name
	 * @param bool   $isGlobal whether listeners hear it at full volume wherever they are
	 */
	public static function sound(string $sound, Vector3 $position, bool $isGlobal, int $data = -1) : Packet{
		return Packets::levelSoundEvent([
			"sound_event" => $sound,
			"position" => Fields::vec3($position),
			"data" => $data,
			"actor_identifier" => ":",
			"is_baby" => false,
			"is_global" => $isGlobal,
			"actor_unique_id" => -1,
			"fire_at_position" => null,
		]);
	}

	/**
	 * @param int $eventId a LevelEvent constant
	 */
	public static function event(int $eventId, int $data, ?Vector3 $position) : Packet{
		return Packets::levelEvent([
			"event_id" => $eventId,
			"position" => $position !== null ? Fields::vec3($position) : ["x" => 0.0, "y" => 0.0, "z" => 0.0],
			"data" => $data,
		]);
	}

	/**
	 * @param int $particleId a ParticleIds constant
	 */
	public static function particle(int $particleId, int $data, Vector3 $position) : Packet{
		return self::event(LevelEvent::ADD_PARTICLE_MASK | $particleId, $data, $position);
	}
}
