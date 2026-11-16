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

namespace quark\item;

use function min;

/**
 * TODO: Implement gliding, durability loss while gliding and firework boosting.
 */
class Elytra extends Armor{

	//a worn out elytra stays at 1 durability instead of breaking
	public function applyDamage(int $amount) : bool{
		$left = $this->getMaxDurability() - 1 - $this->getDamage();
		return $left > 0 && parent::applyDamage(min($amount, $left));
	}
}
