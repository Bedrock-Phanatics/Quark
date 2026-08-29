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

/**
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityRegainHealthEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public const CAUSE_REGEN = 0;
	public const CAUSE_EATING = 1;
	public const CAUSE_MAGIC = 2;
	public const CAUSE_CUSTOM = 3;
	public const CAUSE_SATURATION = 4;

	public function __construct(
		Entity $entity,
		private float $amount,
		private int $regainReason
	){
		$this->entity = $entity;
	}

	public function getAmount() : float{
		return $this->amount;
	}

	public function setAmount(float $amount) : void{
		$this->amount = $amount;
	}

	/**
	 * Returns one of the CAUSE_* constants to indicate why this regeneration occurred.
	 */
	public function getRegainReason() : int{
		return $this->regainReason;
	}
}
