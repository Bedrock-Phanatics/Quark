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

namespace quark\block;

use quark\block\utils\redstone\PowerSource;
use quark\block\utils\redstone\PressurePlateTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\entity\Entity;
use quark\entity\Living;
use function array_filter;

class StonePressurePlate extends SimplePressurePlate implements PowerSource{
	use PressurePlateTrait;
	use RedstoneBlockAccessTrait;

	protected function filterIrrelevantEntities(array $entities) : array{
		return array_filter($entities, fn(Entity $e) => $e instanceof Living); //TODO: armor stands should activate stone plates too
	}
}
