<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\world\redstone;

use InvalidArgumentException;
use pocketmine\world\World;
use function array_keys;
use function in_array;
use function is_string;
use function max;
use function strtolower;
use function trim;

final class RedstoneConfig{
	public const string WORLD_POLICY_ALL = 'all';
	public const string WORLD_POLICY_ALLOWLIST = 'allowlist';
	public const string WORLD_POLICY_BLOCKLIST = 'blocklist';

	private static bool $enabled = true;
	private static int $maxWireNetworkSize = 65536;
	private static int $maxScheduledUpdates = 262144;
	private static int $maxUpdatesPerTick = 8192;
	private static bool $pistonsEnabled = true;
	private static int $maxPistonActionsPerTick = 1024;
	private static int $maxDispenserActionsPerTick = 4096;
	private static bool $dispensersEnabled = true;
	private static string $worldPolicy = self::WORLD_POLICY_ALL;
	/** @var array<string, true> Normalized world names used by the configured policy. */
	private static array $worlds = [];

	/** @param mixed[] $worlds */
	public static function configure(int $maxWireNetworkSize, int $maxScheduledUpdates, int $maxUpdatesPerTick, bool $pistonsEnabled, bool $dispensersEnabled, int $maxPistonActionsPerTick, int $maxDispenserActionsPerTick, bool $enabled = true, string $worldPolicy = self::WORLD_POLICY_ALL, array $worlds = []) : void{
		self::$enabled = $enabled;
		self::$maxWireNetworkSize = max(1, $maxWireNetworkSize);
		self::$maxScheduledUpdates = max(1, $maxScheduledUpdates);
		self::$maxUpdatesPerTick = max(1, $maxUpdatesPerTick);
		self::$pistonsEnabled = $pistonsEnabled;
		self::$maxPistonActionsPerTick = max(1, $maxPistonActionsPerTick);
		self::$maxDispenserActionsPerTick = max(1, $maxDispenserActionsPerTick);
		self::$dispensersEnabled = $dispensersEnabled;
		$worldPolicy = strtolower(trim($worldPolicy));
		if(!in_array($worldPolicy, [self::WORLD_POLICY_ALL, self::WORLD_POLICY_ALLOWLIST, self::WORLD_POLICY_BLOCKLIST], true)){
			throw new InvalidArgumentException("Invalid redstone world-policy '" . $worldPolicy . "'; expected all, allowlist, or blocklist");
		}
		self::$worldPolicy = $worldPolicy;
		$normalized = [];
		foreach($worlds as $world){
			if(is_string($world) && ($world = strtolower(trim($world))) !== ''){
				$normalized[$world] = true;
			}
		}
		self::$worlds = $normalized;
	}

	public static function isEnabled() : bool{ return self::$enabled; }
	public static function isEnabledForWorld(World $world) : bool{ return self::isWorldNameEnabled($world->getFolderName()); }
	public static function isWorldNameEnabled(string $folderName) : bool{
		if(!self::$enabled){ return false; }
		$listed = isset(self::$worlds[strtolower($folderName)]);
		return match(self::$worldPolicy){
			self::WORLD_POLICY_ALL => true,
			self::WORLD_POLICY_ALLOWLIST => $listed,
			self::WORLD_POLICY_BLOCKLIST => !$listed,
			default => false
		};
	}
	public static function getWorldPolicy() : string{ return self::$worldPolicy; }
	/** @return string[] */
	public static function getWorlds() : array{ return array_keys(self::$worlds); }
	public static function getMaxWireNetworkSize() : int{ return self::$maxWireNetworkSize; }
	public static function getMaxScheduledUpdates() : int{ return self::$maxScheduledUpdates; }
	public static function getMaxUpdatesPerTick() : int{ return self::$maxUpdatesPerTick; }
	public static function arePistonsEnabled() : bool{ return self::$pistonsEnabled; }
	public static function getMaxPistonActionsPerTick() : int{ return self::$maxPistonActionsPerTick; }
	public static function getMaxDispenserActionsPerTick() : int{ return self::$maxDispenserActionsPerTick; }
	public static function areDispensersEnabled() : bool{ return self::$dispensersEnabled; }
}
