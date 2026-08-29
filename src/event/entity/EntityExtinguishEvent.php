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

/**
 * Called when an entity on fire gets extinguished.
 *
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityExtinguishEvent extends EntityEvent{
	public const CAUSE_CUSTOM = 0;
	public const CAUSE_WATER = 1;
	public const CAUSE_WATER_CAULDRON = 2;
	public const CAUSE_RESPAWN = 3;
	public const CAUSE_FIRE_PROOF = 4;
	public const CAUSE_TICKING = 5;
	public const CAUSE_RAIN = 6;
	public const CAUSE_POWDER_SNOW = 7;

	public function __construct(
		Entity $entity,
		private int $cause
	){
		$this->entity = $entity;
	}

	public function getCause() : int{
		return $this->cause;
	}
}
