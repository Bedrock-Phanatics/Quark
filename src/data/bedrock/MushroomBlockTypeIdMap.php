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

namespace quark\data\bedrock;

use quark\block\utils\MushroomBlockType;
use quark\data\bedrock\block\convert\property\ValueMappings;
use quark\utils\SingletonTrait;

/**
 * @deprecated
 */
final class MushroomBlockTypeIdMap{
	use SingletonTrait;
	/** @phpstan-use IntSaveIdMapTrait<MushroomBlockType> */
	use IntSaveIdMapTrait;

	public function __construct(){
		$newMapping = ValueMappings::getInstance()->mushroomBlockType;
		foreach(MushroomBlockType::cases() as $case){
			$this->register($newMapping->valueToRaw($case), $case);
		}
	}
}
