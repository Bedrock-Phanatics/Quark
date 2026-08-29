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

namespace quark\world\tnt;

use function max;

final class TntConfig{
	private static bool $enabled = true;
	private static int $maxActivePerChunk = 64;
	private static int $maxActiveNearby = 192;
	private static int $maxIgnitionsPerChunkPerTick = 24;
	private static int $maxDispenserIgnitionsPerSecond = 20;

	public static function configure(bool $enabled, int $maxActivePerChunk, int $maxActiveNearby, int $maxIgnitionsPerChunkPerTick, int $maxDispenserIgnitionsPerSecond) : void{
		self::$enabled = $enabled;
		self::$maxActivePerChunk = max(0, $maxActivePerChunk);
		self::$maxActiveNearby = max(0, $maxActiveNearby);
		self::$maxIgnitionsPerChunkPerTick = max(0, $maxIgnitionsPerChunkPerTick);
		self::$maxDispenserIgnitionsPerSecond = max(0, $maxDispenserIgnitionsPerSecond);
	}

	public static function isEnabled() : bool{ return self::$enabled; }
	public static function getMaxActivePerChunk() : int{ return self::$maxActivePerChunk; }
	public static function getMaxActiveNearby() : int{ return self::$maxActiveNearby; }
	public static function getMaxIgnitionsPerChunkPerTick() : int{ return self::$maxIgnitionsPerChunkPerTick; }
	public static function getMaxDispenserIgnitionsPerSecond() : int{ return self::$maxDispenserIgnitionsPerSecond; }
}
