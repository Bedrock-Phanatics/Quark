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

use quark\block\Block;
use quark\entity\Entity;

class EntityCombustByBlockEvent extends EntityCombustEvent{
	protected Block $combuster;

	public function __construct(Block $combuster, Entity $combustee, int $duration){
		parent::__construct($combustee, $duration);
		$this->combuster = $combuster;
	}

	public function getCombuster() : Block{
		return $this->combuster;
	}
}
