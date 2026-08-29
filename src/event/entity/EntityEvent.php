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

/**
 * Entity related Events, like spawn, inventory, attack...
 */
namespace quark\event\entity;

use quark\entity\Entity;
use quark\event\Event;

/**
 * @phpstan-template TEntity of Entity
 */
abstract class EntityEvent extends Event{
	/** @phpstan-var TEntity */
	protected Entity $entity;

	/**
	 * @return Entity
	 * @phpstan-return TEntity
	 */
	public function getEntity(){
		return $this->entity;
	}
}
