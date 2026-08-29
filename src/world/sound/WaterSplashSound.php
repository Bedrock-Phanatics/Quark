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
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;

final class WaterSplashSound implements Sound{

	public function __construct(private float $volume){
		if($volume < 0 || $volume > 1){
			throw new \InvalidArgumentException("Volume must be between 0 and 1");
		}
	}

	public function encode(Vector3 $pos) : array{
		return [LevelSoundEventPacket::create(
			LevelSoundEvent::SPLASH,
			$pos,
			(int) ($this->volume * 16777215),
			":",
			false,
			false,
			-1,
			null
		)];
	}
}
