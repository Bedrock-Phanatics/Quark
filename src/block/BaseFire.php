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

use quark\entity\Entity;
use quark\entity\projectile\Arrow;
use quark\event\entity\EntityCombustByBlockEvent;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\event\entity\EntityDamageEvent;
use quark\item\Item;

abstract class BaseFire extends Flowable{

	public function hasEntityCollision() : bool{
		return true;
	}

	public function canBeReplaced() : bool{
		return true;
	}

	public function onEntityInside(Entity $entity) : bool{
		$ev = new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_FIRE, $this->getFireDamage());
		$entity->attack($ev);

		$ev = new EntityCombustByBlockEvent($this, $entity, 8);
		if($entity instanceof Arrow){
			$ev->cancel();
		}
		$ev->call();
		if(!$ev->isCancelled()){
			$entity->setOnFire($ev->getDuration());
		}
		return true;
	}

	abstract protected function getFireDamage() : int;

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
