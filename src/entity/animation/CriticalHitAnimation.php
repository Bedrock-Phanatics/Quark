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

namespace quark\entity\animation;

use quark\entity\Living;
use pocketmine\network\mcpe\protocol\AnimatePacket;

final class CriticalHitAnimation implements Animation{

	public function __construct(private Living $entity, private int $particleCount = 55){}

	public function encode() : array{
		return [
			AnimatePacket::create($this->entity->getId(), AnimatePacket::ACTION_CRITICAL_HIT, $this->particleCount),
		];
	}
}
