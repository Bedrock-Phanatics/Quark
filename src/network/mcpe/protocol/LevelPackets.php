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
use pocketmine\utils\Limits;
use quark\network\mcpe\protocol\packet\LevelEventPacket;
use quark\network\mcpe\protocol\packet\LevelSoundEventPacket;
use quark\network\mcpe\protocol\types\LevelEvent;
use function max;
use function min;

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
	public static function sound(string $sound, Vector3 $position, bool $isGlobal, int $data = -1) : LevelSoundEventPacket{
		return new LevelSoundEventPacket($sound, $position, self::data($data), ":", false, $isGlobal, -1);
	}

	/**
	 * @param int $eventId a LevelEvent constant
	 */
	public static function event(int $eventId, int $data, ?Vector3 $position) : LevelEventPacket{
		return new LevelEventPacket($eventId, $position ?? Vector3::zero(), self::data($data));
	}

	/**
	 * Clamps event data, often a scaled pitch, volume or scale from plugin input, to the 32 bits it is sent in.
	 */
	public static function data(int $data) : int{
		return max(Limits::INT32_MIN, min($data, Limits::INT32_MAX));
	}

	/**
	 * @param int $particleId a ParticleIds constant
	 */
	public static function particle(int $particleId, int $data, Vector3 $position) : LevelEventPacket{
		return self::event(LevelEvent::ADD_PARTICLE_MASK | $particleId, $data, $position);
	}
}
