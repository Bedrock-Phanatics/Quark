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

use quark\entity\effect\EffectInstance;
use quark\entity\effect\VanillaEffects;

class Pufferfish extends Food{

	public function getFoodRestore() : int{
		return 1;
	}

	public function getSaturationRestore() : float{
		return 0.2;
	}

	public function getAdditionalEffects() : array{
		return [
			new EffectInstance(VanillaEffects::HUNGER(), 300, 2),
			new EffectInstance(VanillaEffects::POISON(), 1200, 3),
			new EffectInstance(VanillaEffects::NAUSEA(), 300, 1)
		];
	}
}
