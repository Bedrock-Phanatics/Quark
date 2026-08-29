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
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use pocketmine\math\Vector3;

/**
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityMotionEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Entity $entity,
		private Vector3 $mot
	){
		$this->entity = $entity;
	}

	public function getVector() : Vector3{
		return $this->mot;
	}
}
