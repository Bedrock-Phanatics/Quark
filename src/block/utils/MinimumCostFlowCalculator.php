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

namespace quark\block\utils;

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\math\Facing;
use quark\world\format\Chunk;
use quark\world\World;
use function array_fill_keys;
use function count;
use function intdiv;
use function min;

/**
 * Calculates the path(s) of least resistance for liquid flow.
 */
final class MinimumCostFlowCalculator{

	private const CAN_FLOW_DOWN = 1;
	private const CAN_FLOW = 0;
	private const BLOCKED = -1;

	/**
	 * Keyed by position relative to the origin, which flow never strays more than a few blocks from.
	 * @var int[]
	 */
	private array $status = [];
	private int $originX = 0;
	private int $originY = 0;
	private int $originZ = 0;
	/** @var bool[] */
	private array $canFlowIntoState = [];
	/** @var array<int, Chunk|false> the 3x3 chunks around the origin, false when unloaded */
	private array $chunks = [];
	/** @var true[] */
	private array $flowable;
	private int $minY;

	/**
	 * @phpstan-param \Closure(Block) : bool $canFlowInto only asked about blocks that can be flowed into, and must give
	 * the same answer for every in-world block of the same state
	 */
	public function __construct(
		private World $world,
		private int $flowDecayPerBlock,
		private \Closure $canFlowInto
	){
		$this->flowable = RuntimeBlockStateRegistry::getInstance()->flowable;
		$this->minY = $world->getMinY();
	}

	private static function key(int $dx, int $dz) : int{
		return (($dx + 16) << 5) | ($dz + 16);
	}

	private function status(int $x, int $z) : int{
		$key = self::key($x - $this->originX, $z - $this->originZ);
		if(isset($this->status[$key])){
			return $this->status[$key];
		}
		$y = $this->originY;
		$world = $this->world;
		$chunkX = $x >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $z >> Chunk::COORD_BIT_SIZE;
		$chunk = $this->chunks[($chunkX - ($this->originX >> Chunk::COORD_BIT_SIZE) + 1) * 3 + $chunkZ - ($this->originZ >> Chunk::COORD_BIT_SIZE) + 1] ??= $world->getChunk($chunkX, $chunkZ) ?? false;
		if($chunk === false){
			//unloaded or past the world's edge, which getBlockAt() reads as air
			if(!$world->isInWorld($x, $y, $z) || !($this->canFlowInto)($world->getBlockAt($x, $y, $z))){
				return $this->status[$key] = self::BLOCKED;
			}
			return $this->status[$key] = self::CAN_FLOW_DOWN;
		}
		$localX = $x & Chunk::COORD_MASK;
		$localZ = $z & Chunk::COORD_MASK;
		$stateId = $chunk->getBlockStateId($localX, $y, $localZ);
		if(!isset($this->flowable[$stateId]) || !($this->canFlowIntoState[$stateId] ??= ($this->canFlowInto)($world->getBlockAt($x, $y, $z)))){
			return $this->status[$key] = self::BLOCKED;
		}
		return $this->status[$key] = $y <= $this->minY || isset($this->flowable[$chunk->getBlockStateId($localX, $y - 1, $localZ)]) ? self::CAN_FLOW_DOWN : self::CAN_FLOW;
	}

	/**
	 * Breadth-first search for the nearest cell next to a drop, never stepping towards $excluded. This finds the same
	 * costs as walking every non-reversing path, because a shortest path never doubles back.
	 */
	private function calculateFlowCost(int $startX, int $startZ, int $maxCost, int $excluded) : int{
		$frontier = [$startX, $startZ];
		$seen = [self::key($startX - $this->originX, $startZ - $this->originZ) => true];
		for($cost = 1; ; ++$cost){
			$next = [];
			for($i = 0, $count = count($frontier); $i < $count; $i += 2){
				$x = $frontier[$i];
				$z = $frontier[$i + 1];
				foreach(Facing::HORIZONTAL as $j){
					if($j === $excluded){
						continue;
					}
					[$dx, , $dz] = Facing::OFFSET[$j];
					$status = $this->status($sideX = $x + $dx, $sideZ = $z + $dz);
					if($status === self::CAN_FLOW_DOWN){
						return $cost;
					}
					if($status === self::CAN_FLOW && $cost < $maxCost && !isset($seen[$key = self::key($sideX - $this->originX, $sideZ - $this->originZ)])){
						$seen[$key] = true;
						$next[] = $sideX;
						$next[] = $sideZ;
					}
				}
			}
			if(count($next) === 0){
				return 1000;
			}
			$frontier = $next;
		}
	}

	/**
	 * @return int[]
	 */
	public function getOptimalFlowDirections(int $originX, int $originY, int $originZ) : array{
		$this->originX = $originX;
		$this->originY = $originY;
		$this->originZ = $originZ;
		$flowCost = array_fill_keys(Facing::HORIZONTAL, 1000);
		$maxCost = intdiv(4, $this->flowDecayPerBlock);
		foreach(Facing::HORIZONTAL as $j){
			[$dx, , $dz] = Facing::OFFSET[$j];
			$status = $this->status($x = $originX + $dx, $z = $originZ + $dz);
			if($status === self::CAN_FLOW_DOWN){
				$flowCost[$j] = $maxCost = 0;
			}elseif($status === self::CAN_FLOW && $maxCost > 0){
				$flowCost[$j] = $this->calculateFlowCost($x, $z, $maxCost, Facing::opposite($j));
				$maxCost = min($maxCost, $flowCost[$j]);
			}
		}

		$this->status = [];
		$this->canFlowIntoState = [];
		$this->chunks = [];

		$minCost = min($flowCost);

		$isOptimalFlowDirection = [];

		foreach($flowCost as $facing => $cost){
			if($cost === $minCost){
				$isOptimalFlowDirection[] = $facing;
			}
		}

		return $isOptimalFlowDirection;
	}
}
