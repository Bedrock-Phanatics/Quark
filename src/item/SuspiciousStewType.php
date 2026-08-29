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
use quark\utils\LegacyEnumShimTrait;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static SuspiciousStewType ALLIUM()
 * @method static SuspiciousStewType AZURE_BLUET()
 * @method static SuspiciousStewType BLUE_ORCHID()
 * @method static SuspiciousStewType CORNFLOWER()
 * @method static SuspiciousStewType DANDELION()
 * @method static SuspiciousStewType LILY_OF_THE_VALLEY()
 * @method static SuspiciousStewType OXEYE_DAISY()
 * @method static SuspiciousStewType POPPY()
 * @method static SuspiciousStewType TULIP()
 * @method static SuspiciousStewType WITHER_ROSE()
 */
enum SuspiciousStewType{
	use LegacyEnumShimTrait;

	case POPPY;
	case CORNFLOWER;
	case TULIP;
	case AZURE_BLUET;
	case LILY_OF_THE_VALLEY;
	case DANDELION;
	case BLUE_ORCHID;
	case ALLIUM;
	case OXEYE_DAISY;
	case WITHER_ROSE;

	/**
	 * @return EffectInstance[]
	 * @phpstan-return list<EffectInstance>
	 */
	public function getEffects() : array{
		return match($this){
			self::POPPY => [new EffectInstance(VanillaEffects::NIGHT_VISION(), 80)],
			self::CORNFLOWER => [new EffectInstance(VanillaEffects::JUMP_BOOST(), 80)],
			self::TULIP => [new EffectInstance(VanillaEffects::WEAKNESS(), 140)],
			self::AZURE_BLUET => [new EffectInstance(VanillaEffects::BLINDNESS(), 120)],
			self::LILY_OF_THE_VALLEY => [new EffectInstance(VanillaEffects::POISON(), 200)],
			self::DANDELION,
			self::BLUE_ORCHID => [new EffectInstance(VanillaEffects::SATURATION(), 6)],
			self::ALLIUM => [new EffectInstance(VanillaEffects::FIRE_RESISTANCE(), 40)],
			self::OXEYE_DAISY => [new EffectInstance(VanillaEffects::REGENERATION(), 120)],
			self::WITHER_ROSE => [new EffectInstance(VanillaEffects::WITHER(), 120)]
		};
	}
}
