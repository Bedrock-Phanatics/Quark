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

namespace quark\event\world;

use pocketmine\math\Vector3;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\world\World;

/**
 * Called when lightning is about to strike, either during a thunderstorm or through World::strikeLightning().
 * Entities hit can still be protected individually through EntityDamageEvent.
 */
final class LightningStrikeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		World $world,
		private Vector3 $position,
		private bool $harmful,
		private bool $fire
	){
		parent::__construct($world);
	}

	public function getPosition() : Vector3{ return $this->position; }

	/**
	 * Whether the strike damages and ignites nearby entities. A harmless strike is purely visual.
	 */
	public function isHarmful() : bool{ return $this->harmful; }

	public function setHarmful(bool $harmful) : void{ $this->harmful = $harmful; }

	/**
	 * Whether the strike sets fire to blocks around it. Ignored for harmless strikes.
	 */
	public function isFire() : bool{ return $this->fire; }

	public function setFire(bool $fire) : void{ $this->fire = $fire; }
}
