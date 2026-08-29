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

namespace quark\event\entity;

use quark\entity\Entity;
use quark\entity\Living;
use quark\entity\projectile\Projectile;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\item\Item;
use function count;

/**
 * @phpstan-extends EntityEvent<Living>
 */
class EntityShootBowEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	private Entity $projectile;

	public function __construct(
		Living $shooter,
		private Item $bow,
		Projectile $projectile,
		private float $force
	){
		$this->entity = $shooter;
		$this->projectile = $projectile;
	}

	/**
	 * @return Living
	 */
	public function getEntity(){
		return $this->entity;
	}

	public function getBow() : Item{
		return $this->bow;
	}

	/**
	 * Returns the entity considered as the projectile in this event.
	 *
	 * NOTE: This might not return a Projectile if a plugin modified the target entity.
	 */
	public function getProjectile() : Entity{
		return $this->projectile;
	}

	public function setProjectile(Entity $projectile) : void{
		if($projectile !== $this->projectile){
			if(count($this->projectile->getViewers()) === 0){
				$this->projectile->close();
			}
			$this->projectile = $projectile;
		}
	}

	public function getForce() : float{
		return $this->force;
	}

	public function setForce(float $force) : void{
		$this->force = $force;
	}
}
