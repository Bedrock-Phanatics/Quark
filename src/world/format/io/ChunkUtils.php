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

namespace quark\world\format\io;

use pocketmine\world\format\PalettedBlockArray;
use function chr;
use function ord;
use function str_repeat;
use function strlen;

class ChunkUtils{

	/**
	 * Converts pre-MCPE-1.0 biome color array to biome ID array.
	 *
	 * @param int[] $array of biome color values
	 * @phpstan-param list<int> $array
	 */
	public static function convertBiomeColors(array $array) : string{
		$result = str_repeat("\x00", 256);
		foreach($array as $i => $color){
			$result[$i] = chr(($color >> 24) & 0xff);
		}
		return $result;
	}

	/**
	 * Converts 2D biomes into a 3D biome palette. This palette can then be cloned for every subchunk.
	 */
	public static function extrapolate3DBiomes(string $biomes2d) : PalettedBlockArray{
		if(strlen($biomes2d) !== 256){
			throw new \InvalidArgumentException("Biome array is expected to be exactly 256 bytes");
		}
		$biomePalette = new PalettedBlockArray(ord($biomes2d[0]));
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$biomeId = ord($biomes2d[($z << 4) | $x]);
				for($y = 0; $y < 16; ++$y){
					$biomePalette->set($x, $y, $z, $biomeId);
				}
			}
		}

		return $biomePalette;
	}
}
