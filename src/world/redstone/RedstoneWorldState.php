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

namespace quark\world\redstone;

use Generator;
use InvalidArgumentException;
use quark\block\Block;
use quark\block\Opaque;
use quark\block\RedstoneWire;
use quark\block\utils\redstone\PowerSource;
use quark\block\utils\redstone\RedstoneBlockUtils;
use quark\block\utils\redstone\Waitable;
use quark\math\Facing;
use quark\pulse\internal\PulseZones;
use quark\world\ChunkListener;
use quark\world\ChunkListenerNoOpTrait;
use quark\world\format\Chunk;
use quark\world\World;
use RangeException;
use function count;
use function intdiv;
use function max;

final class RedstoneWorldState implements ChunkListener{
	use ChunkListenerNoOpTrait {
		onChunkLoaded as private;
		onChunkUnloaded as private;
		onChunkChanged as private;
		onBlockChanged as private;
	}

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
	/** Scheduled updates rejected or evicted because the configured limit was reached. */
	private(set) int $droppedUpdates = 0;
	private int $nextDropWarningTick = 0;

	/** @var array<int, RedstoneChunk> Extra redstone data, only for loaded chunks that have any. */
	private array $chunks = [];

	/** @var array<int, array<int, int>> chunk hash => block hash => priority */
	private array $deferred = [];
	private int $deferredCount = 0;

	public function __construct(
		readonly public World $world
	){
		$this->waitable_low_priority = new BlockUpdateQueue();
		$this->waitable_normal_priority = new BlockUpdateQueue();
	}

	public function tick() : void{
		PulseZones::$redstoneScheduler->time(fn() => $this->tickScheduledUpdates());
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
			if($this->deferIfUnloaded($x, $y, $z, self::WAITABLE_UPDATE_NORMAL_PRIORITY)){ continue; }
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block instanceof Waitable){ $block->onRedstoneTickReceive(); }
		}
		foreach($this->waitable_low_priority->pop($tick, $remaining) as [$x, $y, $z]){
			--$remaining;
			if(!$manager->isChunkEnabled($this->world, $x >> 4, $z >> 4)){ continue; }
			if($this->deferIfUnloaded($x, $y, $z, self::WAITABLE_UPDATE_LOW_PRIORITY)){ continue; }
			$block = $this->world->getBlockAt($x, $y, $z);
			if($block instanceof Waitable){ $block->onRedstoneTickReceive(); }
		}
	}

	//repeaters, torches and comparators read up to two blocks away
	private function deferIfUnloaded(int $x, int $y, int $z, int $priority) : bool{
		$chunkHash = $this->world->getUnloadedChunkNear($x, $z, 2);
		if($chunkHash === null){
			return false;
		}
		$blockHash = World::blockHash($x, $y, $z);
		if(!isset($this->deferred[$chunkHash][$blockHash])){
			if($this->deferredCount >= RedstoneConfig::getMaxScheduledUpdates()){
				foreach($this->deferred as $stale => $updates){
					$this->deferredCount -= count($updates);
					$this->onUpdatesDropped(count($updates));
					unset($this->deferred[$stale]);
					$this->releaseChunkListener($stale);
					break;
				}
			}
			if(!isset($this->deferred[$chunkHash])){
				World::getXZ($chunkHash, $chunkX, $chunkZ);
				$this->world->registerChunkListener($this, $chunkX, $chunkZ);
			}
			++$this->deferredCount;
		}
		$this->deferred[$chunkHash][$blockHash] = $priority;
		return true;
	}

	public function onChunkLoaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$updates = $this->deferred[$chunkHash] ?? null;
		if($updates === null){
			return;
		}
		unset($this->deferred[$chunkHash]);
		$this->deferredCount -= count($updates);
		$this->releaseChunkListener($chunkHash);
		foreach($updates as $blockHash => $priority){
			World::getBlockXYZ($blockHash, $x, $y, $z);
			$this->scheduleWaitableUpdateAt($x, $y, $z, $this->tick + 1, $priority, false);
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
		$queued = match($priority){
			self::WAITABLE_UPDATE_NORMAL_PRIORITY => $this->waitable_normal_priority->push($x, $y, $z, $full_tick, $override, max(0, RedstoneConfig::getMaxScheduledUpdates() - $this->waitable_low_priority->getSize())),
			self::WAITABLE_UPDATE_LOW_PRIORITY => $this->waitable_low_priority->push($x, $y, $z, $full_tick, $override, max(0, RedstoneConfig::getMaxScheduledUpdates() - $this->waitable_normal_priority->getSize())),
			default => throw new InvalidArgumentException("Unexpected priority: {$priority}")
		};
		if(!$queued){
			$this->onUpdatesDropped(1);
		}
	}

	//components re-arm on their next block update, but the circuit may sit stale until then
	private function onUpdatesDropped(int $count) : void{
		$this->droppedUpdates += $count;
		if($this->tick >= $this->nextDropWarningTick){
			$this->nextDropWarningTick = $this->tick + 1200;
			$this->world->getLogger()->warning("Redstone dropped scheduled updates after reaching max-scheduled-updates (" . RedstoneConfig::getMaxScheduledUpdates() . "), {$this->droppedUpdates} so far");
		}
	}

	public function onChunkUnloaded(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		unset($this->chunks[$chunkHash]);
		$this->releaseChunkListener($chunkHash);
	}

	//a replaced chunk has none of the blocks the data belonged to
	public function onChunkChanged(int $chunkX, int $chunkZ, Chunk $chunk) : void{
		$this->onChunkUnloaded($chunkX, $chunkZ, $chunk);
	}

	//data is dropped as soon as something else takes the block's place, however it was removed
	public function onBlockChanged(int $x, int $y, int $z) : void{
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$data = ($this->chunks[$chunkHash] ?? null)?->getBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
		if($data !== null && !$data->isValidFor($this->world->getBlockAt($x, $y, $z))){
			$this->removeExtraDataAt($x, $y, $z);
		}
	}

	private function releaseChunkListener(int $chunkHash) : void{
		if(!isset($this->chunks[$chunkHash]) && !isset($this->deferred[$chunkHash])){
			World::getXZ($chunkHash, $chunkX, $chunkZ);
			$this->world->unregisterChunkListener($this, $chunkX, $chunkZ);
		}
	}

	/** Number of loaded chunks currently holding redstone data. */
	public function getDataChunkCount() : int{
		return count($this->chunks);
	}

	public function setExtraDataAt(int $x, int $y, int $z, BlockData $data) : void{
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$chunk = $this->chunks[$chunkHash] ?? null;
		if($chunk === null){
			if(!$this->world->isChunkLoaded($chunkX, $chunkZ)){
				return;
			}
			$chunk = $this->chunks[$chunkHash] = new RedstoneChunk();
			$this->world->registerChunkListener($this, $chunkX, $chunkZ);
		}
		$chunk->setBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK, $data);
	}

	public function removeExtraDataAt(int $x, int $y, int $z) : void{
		$chunkHash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		$chunk = $this->chunks[$chunkHash] ?? null;
		if($chunk === null){
			return;
		}
		$chunk->removeBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
		if($chunk->isEmpty()){
			unset($this->chunks[$chunkHash]);
			$this->releaseChunkListener($chunkHash);
		}
	}

	public function getExtraDataAt(int $x, int $y, int $z) : ?BlockData{
		return ($this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null)?->getBlockData($x & Chunk::COORD_MASK, $y, $z & Chunk::COORD_MASK);
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
