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

namespace quark\event\player;

use quark\entity\Entity;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use pocketmine\math\Vector3;
use quark\player\Player;

/**
 * Called when a player interacts with an entity (e.g. shearing a sheep, naming a mob using a nametag).
 */
class PlayerEntityInteractEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private Entity $entity,
		private Vector3 $clickPos
	){
		$this->player = $player;
	}

	public function getEntity() : Entity{
		return $this->entity;
	}

	/**
	 * Returns the absolute coordinates of the click. This is usually on the surface of the entity's hitbox.
	 */
	public function getClickPosition() : Vector3{
		return $this->clickPos;
	}
}
