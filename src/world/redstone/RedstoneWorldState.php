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

use Generator;
use InvalidArgumentException;
use pocketmine\block\Block;
use pocketmine\block\Opaque;
use pocketmine\block\RedstoneWire;
use pocketmine\block\utils\redstone\PowerSource;
use pocketmine\block\utils\redstone\RedstoneBlockUtils;
use pocketmine\block\utils\redstone\Waitable;
use pocketmine\math\Facing;
use pocketmine\timings\Timings;
use pocketmine\world\format\Chunk;
use pocketmine\world\World;
use RangeException;
use function intdiv;
use function max;

final class RedstoneWorldState{

	public const int WAITABLE_UPDATE_NORMAL_PRIORITY = 0;
	public const int WAITABLE_UPDATE_LOW_PRIORITY = 1;

	public static function redstoneTicks(int $redstone_ticks) : int{
		return $redstone_ticks * 2;
	}

	readonly private BlockUpdateQueue $waitable_low_priority;
	readonly private BlockUpdateQueue $waitable_normal_priority;

	private(set) int $tick = 0;
	private int $pistonActionsThisTick = 0;
	private int $dispenserActionsThisTick = 0;

	/** @var array<int, RedstoneChunk|null> Extra redstone data for each loaded chunk. */
	private array $chunks = [];

	public function __construct(
		readonly public World $world
	){
		$this->waitable_low_priority = new BlockUpdateQueue();
		$this->waitable_normal_priority = new BlockUpdateQueue();
	}

	public function tick() : void{
		Timings::$redstoneScheduler->time(fn() => $this->tickScheduledUpdates());
	}

	private function tickScheduledUpdates() : void{
		$tick = ++$this->tick;
		$this->pistonActionsThisTick = 0;
		$this->dispenserActionsThisTick = 0;
		$remaining = RedstoneConfig::getMaxUpdatesPerTick();
		$manager = RedstoneManager::getInstance();
		$lowDue = $this->waitable_low_priority->getScheduledCountAt($tick) > 0;
		$normalLimit = $lowDue ? max(1, intdiv($remaining * 3, 4)) : $remaining;
		foreach($this->waitable_normal_priority->pop($tick, $normalLimit) as [$x, $y, $z]){
			--$remaining;
			if(!$manager->isChunkEnabled($this->world, $x >> 4, $z >> 4)){ continue; }
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block instanceof Waitable){ $block->onRedstoneTickReceive(); }
		}
		foreach($this->waitable_low_priority->pop($tick, $remaining) as [$x, $y, $z]){
			--$remaining;
			if(!$manager->isChunkEnabled($this->world, $x >> 4, $z >> 4)){ continue; }
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block instanceof Waitable){ $block->onRedstoneTickReceive(); }
		}
	}

	public function tryPistonAction() : bool{
		return ++$this->pistonActionsThisTick <= RedstoneConfig::getMaxPistonActionsPerTick();
	}

	public function tryDispenserAction() : bool{
		return ++$this->dispenserActionsThisTick <= RedstoneConfig::getMaxDispenserActionsPerTick();
	}

	public function scheduleWaitableUpdate(Block&Waitable $waitable, int $game_ticks, int $priority = self::WAITABLE_UPDATE_NORMAL_PRIORITY, bool $override = false) : void{
		$game_ticks >= 1 || throw new RangeException("game_ticks must be >= 1, got " . $game_ticks);
		$pos = $waitable->getPosition();
		$this->scheduleWaitableUpdateAt((int) $pos->x, (int) $pos->y, (int) $pos->z, $this->tick + $game_ticks, $priority, $override);
	}

	public function scheduleWaitableUpdateAt(int $x, int $y, int $z, int $full_tick, int $priority, bool $override) : void{
		if(!RedstoneManager::getInstance()->isChunkEnabled($this->world, $x >> 4, $z >> 4)){ return; }
		if($priority === self::WAITABLE_UPDATE_NORMAL_PRIORITY){
			$this->waitable_normal_priority->push($x, $y, $z, $full_tick, $override, max(0, RedstoneConfig::getMaxScheduledUpdates() - $this->waitable_low_priority->getSize()));
		}elseif($priority === self::WAITABLE_UPDATE_LOW_PRIORITY){
			$this->waitable_low_priority->push($x, $y, $z, $full_tick, $override, max(0, RedstoneConfig::getMaxScheduledUpdates() - $this->waitable_normal_priority->getSize()));
		}else{
			throw new InvalidArgumentException("Unexpected priority: {$priority}");
		}
	}

	public function loadChunk(int $chunkX, int $chunkZ) : void{
		$this->chunks[World::chunkHash($chunkX, $chunkZ)] = null;
	}

	public function unloadChunk(int $chunkX, int $chunkZ) : void{
		unset($this->chunks[World::chunkHash($chunkX, $chunkZ)]);
	}

	public function getChunk(int $chunkX, int $chunkZ) : RedstoneChunk{
		$index = World::chunkHash($chunkX, $chunkZ);
		return $this->chunks[$index] ??= new RedstoneChunk();
	}

	public function setExtraDataAt(int $x, int $y, int $z, BlockData $data) : void{
		$this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->setBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $data);
	}

	public function removeExtraDataAt(int $x, int $y, int $z) : void{
		$this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->removeBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
	}

	public function getExtraDataAt(int $x, int $y, int $z) : ?BlockData{
		return $this->getChunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)->getBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
	}

	/**
	 * Returns all power sources that have the potential to strongly power
	 * the opaque block.
	 *
	 * @return Generator<PowerSource>
	 */
	public function getPotentiallyStronglyPoweringSources(Block $block, int $facing, ?int $ignored_side = null) : Generator{
		$manager = RedstoneManager::getInstance();
		if($manager->isEnabledAt($block->getPosition()) && $block instanceof PowerSource && !($block instanceof RedstoneWire) && $block->canPower($facing)){
			yield -1 => $block;
		}
		if($block instanceof Opaque){
			$block_pos = $block->getPosition();
			foreach(Facing::ALL as $side){
				if($side === $ignored_side){
					continue;
				}
				$source = RedstoneBlockUtils::getBlockAtSide($block_pos, $side);
				if($manager->isEnabledAt($source->getPosition()) && $source instanceof PowerSource && $source->canStronglyPower(Facing::opposite($side))){
					yield $side => $source;
				}
			}
		}
	}

	/**
	 * Returns all power sources that are strongly powering the opaque
	 * block.
	 *
	 * @return Generator<PowerSource>
	 */
	public function getStronglyPoweringSources(Block $block, int $facing, ?int $ignored_side = null) : Generator{
		foreach($this->getPotentiallyStronglyPoweringSources($block, $facing, $ignored_side) as $side => $source){
			if($source->getPowerLevel() > 0){
				yield $side => $source;
			}
		}
	}

	/**
	 * Returns whether the opaque block is strongly powered.
	 */
	public function isStronglyPowered(Block $block, int $facing, ?int $ignored_side = null) : bool{
		foreach($this->getPotentiallyStronglyPoweringSources($block, $facing, $ignored_side) as $source){
			if($source->getPowerLevel() > 0){
				return true;
			}
		}
		return false;
	}
}
