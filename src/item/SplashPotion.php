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

namespace quark\item;

use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Location;
use quark\entity\projectile\SplashPotion as SplashPotionEntity;
use quark\entity\projectile\Throwable;
use quark\player\Player;

class SplashPotion extends ProjectileItem{

	private PotionType $potionType = PotionType::WATER;

	public function __construct(
		ItemIdentifier $identifier,
		string $name = "Splash Potion",
		array $enchantmentTags = [],
		private bool $linger = false
	){
		//TODO: remove unnecessary default parameters in PM6, they remain because backward compatibility
		parent::__construct($identifier, $name, $enchantmentTags);
	}

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->potionType);
	}

	public function getType() : PotionType{ return $this->potionType; }

	/**
	 * @return $this
	 */
	public function setType(PotionType $type) : self{
		$this->potionType = $type;
		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	protected function createEntity(Location $location, Player $thrower) : Throwable{
		$projectile = new SplashPotionEntity($location, $thrower, $this->potionType);
		$projectile->setLinger($this->linger);
		return $projectile;
	}

	public function getThrowForce() : float{
		return 0.5;
	}

	/**
	 * Returns whether this splash potion will create an area-effect cloud on impact.
	 */
	public function willLinger() : bool{
		return $this->linger;
	}
}
