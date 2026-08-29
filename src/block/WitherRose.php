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

namespace quark\block;

use quark\block\utils\StaticSupportTrait;
use quark\entity\effect\EffectInstance;
use quark\entity\effect\VanillaEffects;
use quark\entity\Entity;
use quark\entity\Living;
use pocketmine\math\Facing;

class WitherRose extends Flowable{
	use StaticSupportTrait;

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return
			$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD) ||
			match($supportBlock->getTypeId()){
				BlockTypeIds::NETHERRACK,
				BlockTypeIds::SOUL_SAND,
				BlockTypeIds::SOUL_SOIL => true,
				default => false
			};
	}

	public function hasEntityCollision() : bool{ return true; }

	public function onEntityInside(Entity $entity) : bool{
		if($entity instanceof Living && !$entity->getEffects()->has(VanillaEffects::WITHER())){
			$entity->getEffects()->add(new EffectInstance(VanillaEffects::WITHER(), 40));
		}
		return true;
	}

	public function getFlameEncouragement() : int{ return 60; }

	public function getFlammability() : int{ return 100; }
}
