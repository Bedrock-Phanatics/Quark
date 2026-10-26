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

use pocketmine\network\mcpe\protocol\types\ActorEvent;
use quark\entity\object\FireworkRocket;
use quark\network\mcpe\protocol\Packets;

final class FireworkParticlesAnimation implements Animation{

	public function __construct(
		private FireworkRocket $entity
	){}

	public function encode() : array{
		return [
			Packets::actorEvent(["target_runtime_id" => $this->entity->getId(), "event_id" => ActorEvent::FIREWORK_PARTICLES, "data" => 0, "fire_at_position" => null])
		];
	}
}
