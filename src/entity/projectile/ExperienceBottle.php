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

namespace quark\entity\projectile;

use quark\event\entity\ProjectileHitEvent;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use quark\world\particle\PotionSplashParticle;
use quark\world\sound\PotionSplashSound;
use function mt_rand;

class ExperienceBottle extends Throwable{
	public static function getNetworkTypeId() : string{ return EntityIds::XP_BOTTLE; }

	protected function getInitialGravity() : float{ return 0.07; }

	public function getResultDamage() : int{
		return -1;
	}

	public function onHit(ProjectileHitEvent $event) : void{
		$this->getWorld()->addParticle($this->location, new PotionSplashParticle(PotionSplashParticle::DEFAULT_COLOR()));
		$this->broadcastSound(new PotionSplashSound());

		$this->getWorld()->dropExperience($this->location, mt_rand(3, 11));
	}
}
