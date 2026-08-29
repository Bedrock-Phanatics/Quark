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
use quark\event\entity\EntityDamageByChildEntityEvent;
use quark\event\entity\EntityDamageByEntityEvent;
use quark\event\entity\EntityDamageEvent;

class InstantDamageEffect extends InstantEffect{

	public function applyEffect(Living $entity, EffectInstance $instance, float $potency = 1.0, ?Entity $source = null) : void{
		//TODO: add particles (witch spell)
		$damage = (6 << $instance->getAmplifier()) * $potency;
		if($source !== null){
			$sourceOwner = $source->getOwningEntity();
			if($sourceOwner !== null){
				$ev = new EntityDamageByChildEntityEvent($sourceOwner, $source, $entity, EntityDamageEvent::CAUSE_MAGIC, $damage);
			}else{
				$ev = new EntityDamageByEntityEvent($source, $entity, EntityDamageEvent::CAUSE_MAGIC, $damage);
			}
		}else{
			$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGIC, $damage);
		}
		$entity->attack($ev);
	}
}
