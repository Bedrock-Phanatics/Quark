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

namespace quark\block\utils;

use quark\block\BlockIdentifier;
use quark\block\BlockTypeInfo;

trait WoodTypeTrait{
	private WoodType $woodType; //immutable for now

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, WoodType $woodType){
		$this->woodType = $woodType;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getWoodType() : WoodType{
		return $this->woodType;
	}
}
