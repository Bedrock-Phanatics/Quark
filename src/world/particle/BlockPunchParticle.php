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

namespace quark\world\particle;

use quark\block\Block;
use pocketmine\math\Vector3;
use quark\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;

/**
 * This particle appears when a player is attacking a block face in survival mode attempting to break it.
 */
class BlockPunchParticle implements Particle{
	public function __construct(
		private Block $block,
		private int $face
	){}

	public function encode(Vector3 $pos) : array{
		return [LevelEventPacket::create(LevelEvent::PARTICLE_PUNCH_BLOCK, TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($this->block->getStateId()) | ($this->face << 24), $pos)];
	}
}
