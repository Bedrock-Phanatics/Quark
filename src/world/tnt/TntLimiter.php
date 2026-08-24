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

namespace pocketmine\world\tnt;

use pocketmine\entity\object\PrimedTNT;
use pocketmine\math\Vector3;
use pocketmine\world\Position;
use pocketmine\world\World;
use function array_filter;
use function max;
use function min;

final class TntLimiter{
	/** @var array<int, array<int, int>> Number of active TNT entities in each chunk, grouped by world ID. */
	private static array $activeByChunk = [];
	/** @var array<int, array{int, int}> The world and origin chunk recorded for each tracked entity ID. */
	private static array $trackedEntities = [];
	/** @var array<int, array<int, array{int, int}>> Per-chunk ignition counts, stored with the tick they belong to. */
	private static array $chunkIgnitions = [];
	/** @var array<int, array<string, array{int, float}>> Each dispenser's last refill tick and remaining ignition tokens. */
	private static array $dispenserIgnitions = [];
	/** @var array<int, int> Last cleanup tick for each world. */
	private static array $lastCleanup = [];
	/** @var array<int, true> Worlds that already have an unload callback registered. */
	private static array $registeredWorlds = [];

	public static function tryIgnite(Position $spawn, ?Vector3 $dispenser = null) : bool{
		if(!TntConfig::isEnabled()){
			return true;
		}
		$world = $spawn->getWorld();
		$worldId = $world->getId();
		self::ensureWorld($world);
		$chunkX = ((int) $spawn->x) >> 4;
		$chunkZ = ((int) $spawn->z) >> 4;
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$active = self::$activeByChunk[$worldId] ?? [];

		$chunkLimit = TntConfig::getMaxActivePerChunk();
		if($chunkLimit > 0 && ($active[$chunkHash] ?? 0) >= $chunkLimit){
			return false;
		}
		$nearbyLimit = TntConfig::getMaxActiveNearby();
		if($nearbyLimit > 0){
			$nearby = 0;
			for($x = $chunkX - 1; $x <= $chunkX + 1; ++$x){
				for($z = $chunkZ - 1; $z <= $chunkZ + 1; ++$z){
					$nearby += $active[World::chunkHash($x, $z)] ?? 0;
				}
			}
			if($nearby >= $nearbyLimit){
				return false;
			}
		}

		$tick = $world->getServer()->getTick();
		self::cleanupRates($worldId, $tick);
		$perTickLimit = TntConfig::getMaxIgnitionsPerChunkPerTick();
		$chunkRate = self::$chunkIgnitions[$worldId][$chunkHash] ?? null;
		$chunkCount = $chunkRate !== null && $chunkRate[0] === $tick ? $chunkRate[1] : 0;
		if($perTickLimit > 0 && $chunkCount >= $perTickLimit){
			return false;
		}

		if($dispenser !== null){
			$dispenserLimit = TntConfig::getMaxDispenserIgnitionsPerSecond();
			if($dispenserLimit > 0){
				$dispenserKey = ((int) $dispenser->x) . ':' . ((int) $dispenser->y) . ':' . ((int) $dispenser->z);
				$rate = self::$dispenserIgnitions[$worldId][$dispenserKey] ?? [$tick, (float) $dispenserLimit];
				$tokens = min((float) $dispenserLimit, $rate[1] + max(0, $tick - $rate[0]) * ($dispenserLimit / 20));
				if($tokens < 1.0){
					return false;
				}
				self::$dispenserIgnitions[$worldId][$dispenserKey] = [$tick, $tokens - 1.0];
			}
		}

		self::$chunkIgnitions[$worldId][$chunkHash] = [$tick, $chunkCount + 1];
		return true;
	}

	public static function track(PrimedTNT $tnt) : void{
		if(isset(self::$trackedEntities[$tnt->getId()])){
			return;
		}
		$world = $tnt->getWorld();
		$worldId = $world->getId();
		self::ensureWorld($world);
		$location = $tnt->getLocation();
		$chunkHash = World::chunkHash(((int) $location->x) >> 4, ((int) $location->z) >> 4);
		self::$trackedEntities[$tnt->getId()] = [$worldId, $chunkHash];
		self::$activeByChunk[$worldId][$chunkHash] = (self::$activeByChunk[$worldId][$chunkHash] ?? 0) + 1;
	}

	private static function ensureWorld(World $world) : void{
		$worldId = $world->getId();
		if(isset(self::$registeredWorlds[$worldId])){
			return;
		}
		self::$registeredWorlds[$worldId] = true;
		$world->addOnUnloadCallback(static fn() => self::clearWorld($worldId));
	}

	private static function cleanupRates(int $worldId, int $tick) : void{
		if($tick - (self::$lastCleanup[$worldId] ?? 0) < 200){
			return;
		}
		self::$lastCleanup[$worldId] = $tick;
		self::$chunkIgnitions[$worldId] = array_filter(
			self::$chunkIgnitions[$worldId] ?? [],
			static fn(array $rate) : bool => $rate[0] >= $tick - 1
		);
		self::$dispenserIgnitions[$worldId] = array_filter(
			self::$dispenserIgnitions[$worldId] ?? [],
			static fn(array $rate) : bool => $rate[0] >= $tick - 20
		);
	}

	private static function clearWorld(int $worldId) : void{
		unset(self::$activeByChunk[$worldId], self::$chunkIgnitions[$worldId], self::$dispenserIgnitions[$worldId], self::$lastCleanup[$worldId], self::$registeredWorlds[$worldId]);
		foreach(self::$trackedEntities as $entityId => [$entityWorldId]){
			if($entityWorldId === $worldId){
				unset(self::$trackedEntities[$entityId]);
			}
		}
	}

	public static function untrack(PrimedTNT $tnt) : void{
		$tracked = self::$trackedEntities[$tnt->getId()] ?? null;
		if($tracked === null){
			return;
		}
		unset(self::$trackedEntities[$tnt->getId()]);
		[$worldId, $chunkHash] = $tracked;
		$count = (self::$activeByChunk[$worldId][$chunkHash] ?? 1) - 1;
		if($count > 0){
			self::$activeByChunk[$worldId][$chunkHash] = $count;
		}else{
			unset(self::$activeByChunk[$worldId][$chunkHash]);
			if((self::$activeByChunk[$worldId] ?? []) === []){
				unset(self::$activeByChunk[$worldId]);
			}
		}
	}
}
