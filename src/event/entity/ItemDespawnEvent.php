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

use quark\entity\object\ItemEntity;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when a dropped item tries to despawn due to its despawn delay running out.
 * Cancelling the event will reset the despawn delay to default (5 minutes).
 *
 * @phpstan-extends EntityEvent<ItemEntity>
 */
class ItemDespawnEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(ItemEntity $item){
		$this->entity = $item;
	}

	/**
	 * @return ItemEntity
	 */
	public function getEntity(){
		return $this->entity;
	}
}
