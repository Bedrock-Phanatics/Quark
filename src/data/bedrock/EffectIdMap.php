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

use quark\entity\effect\Effect;
use quark\entity\effect\VanillaEffects;
use quark\utils\SingletonTrait;

final class EffectIdMap{
	use SingletonTrait;
	/** @phpstan-use IntSaveIdMapTrait<Effect> */
	use IntSaveIdMapTrait;

	private function __construct(){
		$this->register(EffectIds::SPEED, VanillaEffects::SPEED());
		$this->register(EffectIds::SLOWNESS, VanillaEffects::SLOWNESS());
		$this->register(EffectIds::HASTE, VanillaEffects::HASTE());
		$this->register(EffectIds::MINING_FATIGUE, VanillaEffects::MINING_FATIGUE());
		$this->register(EffectIds::STRENGTH, VanillaEffects::STRENGTH());
		$this->register(EffectIds::INSTANT_HEALTH, VanillaEffects::INSTANT_HEALTH());
		$this->register(EffectIds::INSTANT_DAMAGE, VanillaEffects::INSTANT_DAMAGE());
		$this->register(EffectIds::JUMP_BOOST, VanillaEffects::JUMP_BOOST());
		$this->register(EffectIds::NAUSEA, VanillaEffects::NAUSEA());
		$this->register(EffectIds::REGENERATION, VanillaEffects::REGENERATION());
		$this->register(EffectIds::RESISTANCE, VanillaEffects::RESISTANCE());
		$this->register(EffectIds::FIRE_RESISTANCE, VanillaEffects::FIRE_RESISTANCE());
		$this->register(EffectIds::WATER_BREATHING, VanillaEffects::WATER_BREATHING());
		$this->register(EffectIds::INVISIBILITY, VanillaEffects::INVISIBILITY());
		$this->register(EffectIds::BLINDNESS, VanillaEffects::BLINDNESS());
		$this->register(EffectIds::NIGHT_VISION, VanillaEffects::NIGHT_VISION());
		$this->register(EffectIds::HUNGER, VanillaEffects::HUNGER());
		$this->register(EffectIds::WEAKNESS, VanillaEffects::WEAKNESS());
		$this->register(EffectIds::POISON, VanillaEffects::POISON());
		$this->register(EffectIds::WITHER, VanillaEffects::WITHER());
		$this->register(EffectIds::HEALTH_BOOST, VanillaEffects::HEALTH_BOOST());
		$this->register(EffectIds::ABSORPTION, VanillaEffects::ABSORPTION());
		$this->register(EffectIds::SATURATION, VanillaEffects::SATURATION());
		$this->register(EffectIds::LEVITATION, VanillaEffects::LEVITATION());
		$this->register(EffectIds::FATAL_POISON, VanillaEffects::FATAL_POISON());
		$this->register(EffectIds::CONDUIT_POWER, VanillaEffects::CONDUIT_POWER());
		//TODO: SLOW_FALLING
		//TODO: BAD_OMEN
		//TODO: VILLAGE_HERO
		$this->register(EffectIds::DARKNESS, VanillaEffects::DARKNESS());
	}
}
