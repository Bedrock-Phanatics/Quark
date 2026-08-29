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

namespace quark\crafting;

use quark\utils\LegacyEnumShimTrait;
use quark\world\sound\BlastFurnaceSound;
use quark\world\sound\CampfireSound;
use quark\world\sound\FurnaceSound;
use quark\world\sound\SmokerSound;
use quark\world\sound\Sound;
use function spl_object_id;

/**
 * TODO: These tags need to be removed once we get rid of LegacyEnumShimTrait (PM6)
 *  These are retained for backwards compatibility only.
 *
 * @method static FurnaceType BLAST_FURNACE()
 * @method static FurnaceType CAMPFIRE()
 * @method static FurnaceType FURNACE()
 * @method static FurnaceType SMOKER()
 * @method static FurnaceType SOUL_CAMPFIRE()
 *
 * @phpstan-type TMetadata array{0: int, 1: Sound}
 */
enum FurnaceType{
	use LegacyEnumShimTrait;

	case FURNACE;
	case BLAST_FURNACE;
	case SMOKER;
	case CAMPFIRE;
	case SOUL_CAMPFIRE;

	/**
	 * @phpstan-return TMetadata
	 */
	private function getMetadata() : array{
		/** @phpstan-var array<int, TMetadata> $cache */
		static $cache = [];

		return $cache[spl_object_id($this)] ??= match($this){
			self::FURNACE => [200, new FurnaceSound()],
			self::BLAST_FURNACE => [100, new BlastFurnaceSound()],
			self::SMOKER => [100, new SmokerSound()],
			self::CAMPFIRE, self::SOUL_CAMPFIRE => [600, new CampfireSound()]
		};
	}

	public function getCookDurationTicks() : int{ return $this->getMetadata()[0]; }

	public function getCookSound() : Sound{ return $this->getMetadata()[1]; }
}
