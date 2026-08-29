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

namespace quark\entity\effect;

use quark\entity\Entity;
use quark\entity\Living;
use quark\player\Player;

class LevitationEffect extends Effect{

	public function getApplyInterval(EffectInstance $instance) : int{
		return 1;
	}

	public function applyEffect(Living $entity, EffectInstance $instance, float $potency = 1.0, ?Entity $source = null) : void{
		if(!($entity instanceof Player)){ //TODO: ugly hack, player motion isn't updated properly by the server yet :(
			$entity->addMotion(0, ($instance->getEffectLevel() / 20 - $entity->getMotion()->y) / 5, 0);
		}
	}

	public function add(Living $entity, EffectInstance $instance) : void{
		$entity->setHasGravity(false);
	}

	public function remove(Living $entity, EffectInstance $instance) : void{
		$entity->setHasGravity();
	}
}
