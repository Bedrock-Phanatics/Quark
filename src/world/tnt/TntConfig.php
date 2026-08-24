<?php

declare(strict_types=1);

namespace pocketmine\world\tnt;

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
