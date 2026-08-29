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

namespace quark\block\utils\redstone;

trait RedstoneLampBehavior{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 2;
	private(set) bool $requires_strong_power = true;

	protected function onReceivePower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setPowered($powered), false);
		}
	}
}
