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

use quark\entity\Living;
use quark\entity\object\AreaEffectCloud;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when an area effect cloud applies effects to entities.
 *
 * @phpstan-extends EntityEvent<AreaEffectCloud>
 */
class AreaEffectCloudApplyEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param Living[] $affectedEntities
	 */
	public function __construct(
		AreaEffectCloud $entity,
		protected array $affectedEntities
	){
		$this->entity = $entity;
	}

	/**
	 * @return AreaEffectCloud
	 */
	public function getEntity(){
		return $this->entity;
	}

	/**
	 * Returns the affected entities.
	 *
	 * @return Living[]
	 */
	public function getAffectedEntities() : array{
		return $this->affectedEntities;
	}
}
